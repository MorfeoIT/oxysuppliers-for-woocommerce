# Architettura

Stessa struttura di OxyProfit, per un motivo pratico: è già stata provata su un
negozio vero e i suoi difetti sono già stati pagati.

## Strati

```
src/
  Domain/       PHP puro. Nessuna chiamata a WordPress, nessun $wpdb.
  Engine/       Le decisioni: fabbisogno, quantità suggerita, riconciliazione.
  Persistence/  Tables, Migrator e i repository su $wpdb.
  Service/      Quello che orchestra: audit log, ricezioni, calcolo costi.
  Support/      Capability, impostazioni, licenza.
  Integration/  Ponte verso WooCommerce e verso gli altri plugin Oxy.
  Admin/        Schermate, tabelle, form, asset.
  Rest/         Controller REST oxysuppliers/v1.
  Pdf/          Generazione del PDF dell'ordine.
  Plugin.php    Composizione: è qui che gli strati si incontrano.
```

Sono gli stessi nomi di OxyProfit, di proposito: chi ha appena letto un plugin
della famiglia sa già dove guardare nell'altro.

**La regola che regge tutto il resto:** `Domain` e `Engine` non chiamano
WordPress. Regole, righe, orologio e configurazione arrivano dal costruttore.
È questo che rende provabile ogni ramo del calcolo del fabbisogno senza un
database — su OxyArea la stessa scelta (`AccessResolver` senza WordPress) è
quella che ha permesso 225 test unit veri.

I filtri che permettono a terzi di intervenire vivono in **decoratori
separati**, non dentro il motore. Il motore non deve sapere che esistono degli
hook.

## Oggetti di dominio

- `Money` — interi in centesimi, valuta esplicita, mai `float`. **Nostro**, non
  quello di OxyProfit: due plugin non condividono classi.
- `OrderTerms` — minimo, multiplo e confezione, con `round_up()` che restituisce
  sempre una quantità che il fornitore può accettare, e `accepts()` che lo
  verifica. Multiplo e confezione sono **due vincoli**, non uno: chi vende in
  confezioni da sei e a multipli di dieci accetta solo i multipli di trenta, e
  prendere il maggiore dei due proporrebbe un ordine impossibile da evadere.
  Il minimo viene arrotondato al passo come tutto il resto — «almeno dieci, in
  confezioni da quattro» fa dodici.
- `PurchaseOrderNumber` — la numerazione, filtrabile.
- `Requirement` — fabbisogno di una riga: stock, obiettivo, in arrivo,
  suggerito.
- Stati come `enum` (`PurchaseOrderStatus`), mai stringhe sparse nel codice.

## Dove finisce il gratuito e comincia il Pro, in concreto

`RequirementStrategy` risponde a **una** domanda: quante unità mancano.
L'arrotondamento ai termini del fornitore e lo stato della riga stanno in
`RequirementCalculator`, fuori dalla strategia. È questo che rende vera la
regola 1 di `03_FREE_VS_PRO.md` — il Pro sostituisce un numero, non porta con sé
una seconda copia delle formule che potrebbe smettere di andare d'accordo con la
prima.

Il gratuito è `TargetStockStrategy`: `obiettivo − disponibile − in arrivo`.

**Deviazione dichiarata dalla specifica.** Il §6 mette «merce già ordinata e non
ricevuta» fra le cose PRO. Qui si sottrae anche nel gratuito, perché non farlo
significa suggerire di riordinare merce già in viaggio: si ordina due volte e si
paga due volte. Quello che resta davvero PRO è prevedere il **consumo durante il
lead time**, che è la parte difficile.

**Da dove vengono i numeri.** La scorta minima è `_low_stock_amount` di
WooCommerce, per articolo, con `woocommerce_notify_low_stock_amount` dietro:
nessun campo nuovo da riempire. L'obiettivo è quella soglia per un
moltiplicatore (`requirement_target_multiplier`, 2 di default, più il filtro
`oxysuppliers_target_multiplier`), perché WooCommerce sa quando avvisarti e non
fino a quanto riempire.

## Le vendite arrivano tardi, e va detto

I venduti 7/30/90 si leggono da `wc_order_product_lookup`, che è indicizzata per
questo ed è quella che legge WooCommerce Analytics. Ma **si popola in modo
asincrono**, con Action Scheduler: su un negozio dove non è mai girata, la
tabella è vuota mentre gli ordini ci sono tutti.

Una schermata che in quel caso mostra «venduti 0» sta dicendo «non ordinare
niente». `CatalogueRepository::sales_data_is_stale()` se ne accorge — lookup
vuota ma ordini presenti — e la schermata lo dice, col link allo strumento di
importazione di WooCommerce.

```php
interface RequirementStrategy {
    public function needed( RequirementContext $context ): int;
    public function name(): string;
}
```

La strategia si sceglie con `apply_filters( 'oxysuppliers_requirement_strategy', ... )`,
**applicato nella composizione** (`Plugin.php`) e non dentro il motore. Un solo
filtro, un solo vincitore, e quello che non è una strategia viene scartato
invece che creduto: un add-on scritto male non deve poter lasciare la schermata
senza niente a cui chiedere.

## Il menu: una voce sola, con dei tab

La specifica (§27) chiede WooCommerce → Acquisti → sei pagine. Il menu di
WordPress ha **due soli livelli**, quindi il secondo livello sono dei tab su una
pagina sola: sei voci sotto WooCommerce spingerebbero via tutto il resto, e
WooCommerce stesso risolve la stessa cosa allo stesso modo.

Due conseguenze che vanno tenute a mente aggiungendo un tab:

- ogni tab si mostra **solo a chi ha la sua capability**, quindi l'elenco dei
  tab visibili è anche la risposta a «cosa posso fare qui»;
- la voce di menu si registra con la capability del **primo tab che quell'utente
  può aprire**, perché `add_submenu_page()` ne accetta una sola: chi gestisce i
  fornitori ma non vede gli ordini d'acquisto deve comunque trovare il menu.

## Il pannello sulla scheda prodotto: niente JavaScript

Il listino di un articolo è una tabella di righe ripetute, che di solito si fa
con uno script che aggiunge e toglie righe. Qui no: si disegnano le righe che
esistono più una vuota, e si salva col bottone che il negozio preme già. Una
riga si toglie spuntando una casella.

Costa un giro di salvataggio in più per aggiungere due fornitori insieme, e in
cambio non c'è niente da compilare, niente da spedire e niente che si rompa
quando WooCommerce cambia il modo di clonare le righe delle variazioni.

## HPOS

Il plugin **non tocca mai** le tabelle degli ordini di WooCommerce, né le legacy
né quelle HPOS. Ogni lettura di ordini di vendita (per le vendite 7/30/90 giorni
della dashboard) passa da `wc_get_orders()` / `WC_Order_Query`, che funzionano
in entrambe le configurazioni. La compatibilità si dichiara con
`FeaturesUtil::declare_compatibility( 'custom_order_tables', ... )` su
`before_woocommerce_init`.

L'ordine fornitore **non è un ordine WooCommerce** e non è un custom post type:
è un oggetto nostro su tabelle nostre. Non ha nulla da guadagnare dal ciclo di
vita dei post e avrebbe tutto da perdere in prestazioni sulle liste.

## Prestazioni della dashboard

La dashboard fabbisogni è la schermata che può uccidere il plugin su un catalogo
grosso. Due vincoli di progetto:

- le vendite 7/30/90 giorni si leggono da `wc_order_product_lookup`, la tabella
  di riepilogo che WooCommerce mantiene da sé, **non** scorrendo gli ordini;
- il calcolo del suggerimento è per riga e non fa query: riceve tutto dal
  contesto. Chi lo chiama carica i dati in blocco, una query per pagina di
  risultati, mai una query per prodotto.

Se una riga non può essere calcolata (nessun fornitore, nessun costo) si mostra
come tale: è uno dei filtri richiesti dalla specifica, non un errore da
nascondere.

## Autoload

Composer con `autoload.classmap` **generato in fase di pacchetto**
(`composer dump-autoload --classmap-authoritative --no-dev`): niente
`vendor/composer/InstalledVersions` da spedire più del necessario, e nessuna
dipendenza runtime di terzi se non quella del PDF.

## Come si scrive

- WordPress Coding Standards, PHPCS pulito, PHPStan livello 8.
- Tutte le stringhe traducibili, text domain `oxysuppliers-for-woocommerce`.
- Ogni scrittura su `$wpdb` con `prepare()`. Dove uno sniff non si applica, il
  motivo va **nel codice** con `// phpcs:ignore <Sniff> -- motivo`: Plugin Check
  ignora il `phpcs.xml.dist` del progetto e legge solo il codice.
- Niente file nascosti nel pacchetto: i `.gitkeep` vanno in `export-ignore`.
- Nessuna toolchain JavaScript se non serve davvero. Il pacchetto distribuisce
  il sorgente che è stato scritto, che è quello che chiede la revisione.
