# Piano sprint

Gli otto sprint della specifica (§28), con i criteri di uscita che li rendono
verificabili. Uno sprint è chiuso quando i suoi criteri sono **eseguiti**, non
quando il codice sembra fatto.

## Sprint 1 — fondamenta

Bootstrap, autoload, container dei servizi, `Migrator` con `dbDelta()` e
versione dello schema, capability concesse **all'attivazione e agli
aggiornamenti**, `uninstall.php` non distruttivo, dichiarazione di
compatibilità HPOS, anagrafica fornitori completa (lista, creazione, modifica,
disattivazione).

*Chiuso quando:* le otto tabelle esistono, un fornitore si crea e si rimodifica,
un fornitore con ordini non si può cancellare, `shop_manager` entra ed `editor`
no, WP_DEBUG non stampa niente.

## Sprint 2 — prodotto ↔ fornitore

Relazione con costo, valuta, codice fornitore, MOQ, multiplo, confezione, lead
time, fornitore preferenziale. Pannello **Fornitori** nella schermata prodotto,
funzionante anche sulle variazioni. Un solo listino per coppia
fornitore/articolo.

*Chiuso quando:* due fornitori sullo stesso prodotto con listini diversi, uno
preferenziale; l'arrotondamento a minimo/multiplo/confezione è coperto dai test
unit compresi i casi limite (multiplo 0, minimo maggiore del fabbisogno).

## Sprint 3 — dashboard fabbisogni

Le colonne e i filtri della specifica (§5), la formula
`obiettivo − disponibile`, le vendite 7/30/90 giorni da
`wc_order_product_lookup`, azioni massive, esportazione CSV.

*Chiuso quando:* su un catalogo seminato con qualche migliaio di righe la pagina
risponde in un tempo accettabile e il numero di query **non cresce con le
righe**; i filtri «senza fornitore» e «senza costo» mostrano davvero i casi
incompleti invece di nasconderli.

**Fatto il 13/08/2026.** Misurato sul banco: **sette query per cinque righe e
sette per duecento**.

Due cose decise strada facendo, scritte qui perché non si deducono dal codice:

- **la merce in arrivo si sottrae anche nel gratuito**, contro il §6. Non farlo
  significa suggerire di riordinare merce già in viaggio. Resta PRO la parte
  difficile, cioè prevedere il consumo durante il lead time;
- **la scorta minima non è un campo nostro**: è `_low_stock_amount` di
  WooCommerce, e l'obiettivo è quella soglia per un moltiplicatore
  configurabile. WooCommerce sa quando avvisarti, non fino a quanto riempire.

E una cosa che il banco ha insegnato: la tabella dei venduti di WooCommerce si
riempie **in modo asincrono**, quindi può essere vuota mentre gli ordini ci sono.
La schermata se ne accorge e lo dice, invece di mostrare zeri.

## Sprint 4 — ordine fornitore

Testata e righe, numerazione unica e filtrabile, macchina a stati (bozza → da
inviare → inviato → confermato → parzialmente ricevuto → ricevuto → annullato),
creazione manuale e creazione dai fabbisogni con **raggruppamento automatico per
fornitore**.

*Chiuso quando:* due salvataggi simultanei producono due numeri diversi; una
transizione di stato non prevista viene rifiutata dal dominio, non
dall'interfaccia.

**Fatto il 13/08/2026.** Tutti e due i criteri provati, in CI e sul banco.

Il numero **non si prenota**: si propone guardando il più alto già usato
quest'anno, e a decidere è l'**indice unico**. Due salvataggi nello stesso
istante propongono lo stesso numero, un INSERT fallisce, e chi ha perso la corsa
ne chiede un altro. Un contatore in un'opzione perderebbe uno dei due, e in
silenzio.

La macchina a stati sta in `PurchaseOrderStatus::allowed_next()`, non nella
schermata: i bottoni si disegnano da lì, e una mossa raggiunta in altro modo —
una pagina vecchia, un indirizzo scritto a mano — viene rifiutata prima che si
scriva qualcosa. Due regole meno ovvie: un ordine **annullato resta annullato**
(riaprirlo nasconderebbe che è stato annullato), e uno **ricevuto può tornare
parzialmente ricevuto**, perché annullare una ricezione deve poter lasciare
l'ordine a dire qualcosa di vero.

Da qui la colonna «in arrivo» dei fabbisogni smette di essere sempre zero: una
**bozza non conta** (è un pensiero, non un ordine, e contarla impedirebbe di
ordinare quello che si stava per ordinare), un ordine inviato sì, e uno
annullato smette.

## Sprint 5 — PDF, email, log

PDF con template sovrascrivibile, servito da un endpoint protetto. Invio email
con destinatario mostrato prima, CC/BCC, oggetto e testo configurabili,
registrazione di invio e reinvio. Audit log con rotazione.

*Chiuso quando:* il PDF non è raggiungibile senza capability; l'email finisce in
`mail.log` sul banco; il reinvio è distinguibile dal primo invio nel log.

## Sprint 6 — ricezioni

Il cuore del plugin. Ricezione totale, per riga, parziale. Chiave di
idempotenza, blocco sul PO, transazione, incremento atomico dello stock,
annullamento come movimento inverso, costo effettivo registrato.

*Chiuso quando:* tutti i casi elencati in `06_PIANO_TEST.md` § «Cosa si prova
solo qui» sono verdi sul banco di prova, doppio invio e concorrenza compresi.
**Questo sprint non si chiude sui soli test unit.**

## Sprint 7 — merce in arrivo, report, integrazione

Quantità ordinata e ETA sul prodotto (§15), i quattro report FREE, hook e filtri
pubblici, sorgente di costo per OxyProfit in un file a parte con
`interface_exists`, REST `oxysuppliers/v1`, import CSV con **anteprima
obbligatoria**.

*Chiuso quando:* con OxyProfit **assente** non cambia nulla e non c'è nessun
errore; con OxyProfit presente il costo della ricezione arriva; una correzione
di costo non viene raccontata come un fatto nuovo.

## Sprint 8 — prontezza al rilascio

`readme.txt` con `Tested up to:` **realmente provato**, `.pot` generato con
`wp i18n make-pot` prima del pacchetto, traduzione italiana, `LICENSE` nel
pacchetto, screenshot da un'installazione vera, Plugin Check pulito, PHPStan 8 e
PHPCS puliti, `.gitattributes` con gli `export-ignore`, zip **costruito su
Linux** (`zip -r`) e verificato con `unzip -l` che i percorsi usino la barra
normale.

*Chiuso quando:* esiste `docs/SUBMISSION_READINESS.md` che dice cosa è pronto e
cosa manca, come su OxyArea.

## Dopo: non si sottomette da soli

Il rilascio su WordPress.org resta in coda con il resto della famiglia OxyWP
(vedi `00_NAMING_CLEARANCE.md`). Quando si rilascia davvero, il rilascio **non è
finito** finché non sono fatte insieme tre cose:

1. i tre siti — oxywp.com (fonte, inglese), oxysoft.it e appstore3000
   (traduzione italiana), ognuno con la sua pagina prodotto, i suoi bottoni e il
   suo hreflang reciproco;
2. la versione di WordPress con cui si è provato **davvero**, in
   `dati-condivisi/plugin-compat.json` e nel `Tested up to:` del `readme.txt`
   (se divergono comanda il readme, che è quello che legge WordPress.org);
3. la roadmap pubblica — cosa è appena uscito e cosa sta arrivando, **cosa** e
   non **quando**.

Dopo ogni modifica del pacchetto: rifare lo zip e riscrivere
`sha256_oxysuppliers-for-woocommerce` nelle impostazioni del negozio, altrimenti
il plugin scarica un file che non corrisponde e lo butta via.
