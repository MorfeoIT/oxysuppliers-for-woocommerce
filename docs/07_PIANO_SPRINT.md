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

**Fatto il 13/08/2026.** Tutti e tre provati, e in più il PDF è stato riletto
davvero (con `pdftotext` e `pdftoppm` sul banco) per controllare che gli accenti
sopravvivano: un fornitore che si chiama «Però» non deve uscire «Per».

**La libreria PDF è Dompdf, inclusa nel pacchetto** (decisione dell'utente del
13/08/2026). Costo reale, misurato e non stimato: il pacchetto passa da ~0,5 MB
a **~11 MB**, di cui 8 sono Dompdf e i suoi font. Ne sono stati tolti due —
DejaVu Serif e DejaVu Sans Mono, che il documento non chiede mai — per 3,4 MB in
meno. **DejaVu Sans resta**: è quello che porta gli accenti.

Tre decisioni di sicurezza, che sono tali e non preferenze:

- il PDF **non si scrive mai** in `uploads/`. Si genera al volo dietro capability
  e nonce, e l'allegato vive nella cartella temporanea per la durata di un invio;
- il renderer **non può raggiungere la rete** (`setIsRemoteEnabled(false)`) e non
  può leggere fuori da `uploads/` (chroot). Un template è HTML, e dell'HTML che
  può fare richieste è una via d'uscita dall'edificio;
- il logo si **incorpora come data URI**, letto da disco, proprio per non dover
  fare nessuna richiesta.

E una cosa imparata da Plugin Check: se il pacchetto porta una cartella
`vendor/`, deve portare anche il `composer.json` che dice cosa c'è dentro.
Toglierlo fa apparire il plugin come uno che chiede fiducia.

## Sprint 6 — ricezioni

Il cuore del plugin. Ricezione totale, per riga, parziale. Chiave di
idempotenza, blocco sul PO, transazione, incremento atomico dello stock,
annullamento come movimento inverso, costo effettivo registrato.

*Chiuso quando:* tutti i casi elencati in `06_PIANO_TEST.md` § «Cosa si prova
solo qui» sono verdi sul banco di prova, doppio invio e concorrenza compresi.
**Questo sprint non si chiude sui soli test unit.**

**Fatto il 13/08/2026**, e la prova che conta è stata fatta su HTTP vero: lo
stesso identico modulo inviato due volte con curl, la giacenza di MOUSE-X che
passa da −2 a 8 alla prima e **resta 8** alla seconda, con una sola ricezione a
database.

Le quattro difese sono nel codice nell'ordine in cui vanno lette
(`GoodsReceiver`): chiave di idempotenza scritta **per prima**, blocco
sull'ordine, quantità rilette **dentro** la transazione dalle ricezioni e non
dalla copia sulla riga d'ordine, transazione su tutto quello che è nostro. Lo
stock si muove **dopo** il commit e fuori dalla transazione, con l'incremento
atomico di WooCommerce.

**Due difetti trovati, tutti e due invisibili ai test unit:**

- il token del blocco era un UUID da 36 caratteri in una colonna `char(32)`.
  MySQL lo troncava in silenzio, `unlock` non trovava più la riga, e ogni
  ricezione dopo la prima rispondeva «occupato». È esattamente la classe di
  errore descritta in `02_MODELLO_DATI.md` — «MySQL tronca in silenzio» — presa
  in casa propria;
- l'annullamento **non rimetteva a posto la giacenza**, perché chiedeva a una
  copia dell'oggetto letta *prima* che lo stock si muovesse. Ora si rilegge dal
  database. Questo l'ha trovato solo il banco, perché in CI non c'è WooCommerce
  e nessuno stock si muove.

## Sprint 7 — merce in arrivo, report, integrazione

Quantità ordinata e ETA sul prodotto (§15), i quattro report FREE, hook e filtri
pubblici, sorgente di costo per OxyProfit in un file a parte con
`interface_exists`, REST `oxysuppliers/v1`, import CSV con **anteprima
obbligatoria**.

*Chiuso quando:* con OxyProfit **assente** non cambia nulla e non c'è nessun
errore; con OxyProfit presente il costo della ricezione arriva; una correzione
di costo non viene raccontata come un fatto nuovo.

**Fatto il 13/08/2026.** Tutti e tre i criteri provati sul banco, i primi due
sullo stesso sito: `scripts/fake-oxyprofit.php` dichiara il minimo indispensabile
della loro interfaccia, così si cammina anche il ramo "OxyProfit c'è" senza
installare il loro plugin — e il fatto che quel minimo basti è la prova che la
cucitura dipende solo dall'interfaccia pubblicata.

Il terzo criterio è quello che ha trovato il difetto vero, e non era una svista
di scrittura ma un ragionamento sbagliato: per sapere quale costo rimettere,
l'annullamento chiedeva **quanto costa adesso**, e adesso era proprio la cifra da
togliere. Riscriveva quella, e la correzione non correggeva niente. La risposta
giusta era già nella riga della ricezione annullata, nella colonna che dice cosa
aveva sostituito. Da lì è venuta anche la domanda a cui non avevo risposto: se
quella era la **prima** consegna dell'articolo non c'è niente da rimettere, e
scriverci il costo dell'ordine trasformerebbe un prezzo digitato in un prezzo
pagato. Ora la riga dice "non lo sappiamo più" (schema 2, costo che ammette il
nullo), e `cost_on()` tratta le due assenze allo stesso modo.

Due lezioni sull'ambiente di prova, pagate qui:

- **la virgola decimale nei test di integrazione non funziona**, perché a
  normalizzarla è `wc_format_decimal` e in CI non c'è WooCommerce: lì i costi si
  scrivono col punto, e cosa fa la virgola lo prova il banco. Un test che
  falliva mi stava dicendo la verità sull'ambiente, non sul codice;
- **le rotte REST sul banco vivono su `?rest_route=`**, non su `/wp-json/`,
  perché i permalink sono semplici. E via HTTP il solo cookie non basta a farsi
  riconoscere: senza `X-WP-Nonce` anche l'amministratore è uno sconosciuto,
  quindi da fuori si prova che la porta è chiusa e i permessi per ruolo si
  provano da dentro.

## Sprint 8 — prontezza al rilascio

`readme.txt` con `Tested up to:` **realmente provato**, `.pot` generato con
`wp i18n make-pot` prima del pacchetto, traduzione italiana, `LICENSE` nel
pacchetto, screenshot da un'installazione vera, Plugin Check pulito, PHPStan 8 e
PHPCS puliti, `.gitattributes` con gli `export-ignore`, zip **costruito su
Linux** (`zip -r`) e verificato con `unzip -l` che i percorsi usino la barra
normale.

*Chiuso quando:* esiste `docs/SUBMISSION_READINESS.md` che dice cosa è pronto e
cosa manca, come su OxyArea.

**Fatto il 14/08/2026.** `Tested up to: 7.0` non è una speranza: il banco gira
WordPress 7.0.4 e le 117 verifiche su HTTP ci sono passate sopra. Il pacchetto è
costruito su Linux, controllato file per file (33 verifiche: c'è quello che
serve, non c'è quello che non deve uscire, ogni file PHP si compila, i percorsi
dello zip usano la barra normale) e poi **installato dallo zip** come lo
installerebbe un utente. Plugin Check è pulito su quello, non sulla cartella di
sviluppo — differenza che la prima volta si è vista.

Due cose che questo sprint ha trovato, e nessuna delle due era prevista:

- **Il pannello dei fornitori sulla scheda prodotto non era mai stato vestito.**
  Il foglio di stile si carica sulle schermate il cui hook contiene lo slug, e la
  schermata prodotto di WooCommerce non è una di quelle: le regole di WooCommerce
  schiacciavano ogni campo del listino a pochi pixel. Nessuna prova poteva
  vederlo — quelle su HTTP cercano il testo nell'HTML, e il testo c'era. Un campo
  troppo stretto per mostrare quello che contiene è vuoto per chi lo guarda, e
  questo lo dice solo un'immagine. **Le schermate non sono un adempimento.**
- **I file nascosti li spediscono anche le dipendenze.** `.gitattributes` tiene
  fuori i nostri, ma vale solo sui file tracciati: `thecodingmachine/safe` porta
  dei `.gitkeep`, e WordPress.org rifiuta i file nascosti senza chiedersi da dove
  vengano.

La disinstallazione è provata tutte e due le volte, eseguendo `uninstall.php`
come lo esegue WordPress: di serie non si perde niente, con l'impostazione accesa
si perde tutto. Anche qui due prove scritte male hanno insegnato qualcosa —
un'opzione mai salvata non si può perdere, e i ruoli vanno riletti dal database
perché la disinstallazione gira in un altro processo.

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
