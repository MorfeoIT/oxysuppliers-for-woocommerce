# Architettura

Stessa struttura di OxyProfit, per un motivo pratico: è già stata provata su un
negozio vero e i suoi difetti sono già stati pagati.

## Strati

```
src/
  Domain/          PHP puro. Nessuna chiamata a WordPress, nessun $wpdb.
  Engine/          Le decisioni: fabbisogno, quantità suggerita, riconciliazione.
  Infrastructure/  Repository su $wpdb, ponte verso WooCommerce, cron, mail.
  Admin/           Schermate, list table, form, asset.
  Rest/            Controller REST oxysuppliers/v1.
  Pdf/             Generazione del PDF dell'ordine.
  Plugin.php       Composizione: è qui che gli strati si incontrano.
```

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
- `Quantity` — intero, con `round_to_order_multiple()` che applica minimo,
  multiplo e confezione **in quest'ordine** e restituisce sempre un valore che
  il fornitore può accettare.
- `PurchaseOrderNumber` — la numerazione, filtrabile.
- `Requirement` — fabbisogno di una riga: stock, obiettivo, in arrivo,
  suggerito.
- Stati come `enum` (`PurchaseOrderStatus`), mai stringhe sparse nel codice.

## Fabbisogno: un'interfaccia, due implementazioni

```php
interface RequirementStrategy {
    public function suggest( RequirementContext $context ): Quantity;
}
```

Il FREE ne registra una: `TargetStockStrategy` (§6 della specifica,
`obiettivo − disponibile`, poi minimo/multiplo/confezione). Il PRO ne registra
un'altra, che considera consumo medio, lead time e merce in arrivo.

La strategia si sceglie con `apply_filters( 'oxysuppliers_requirement_strategy', ... )`.
**Un solo filtro, un solo vincitore**: è il seme di come il PRO si innesta, e le
regole per scriverlo bene stanno in `03_FREE_VS_PRO.md`.

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
