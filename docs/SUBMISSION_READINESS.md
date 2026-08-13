# OxySuppliers — prontezza per WordPress.org

**Versione:** 0.1.0
**Valutato il:** 14 agosto 2026
**Provato su:** WordPress 7.0.4, WooCommerce 11.0.1, PHP 8.3.33, sul banco
`https://test.44123.it/oxysuppliers`
**Verdetto:** il codice è pronto da mandare. **Una cosa non lo è, e non è
codice:** la decisione sul prefisso `Oxy-`. Vedi §7.

Questo documento è scritto per chi deve decidere se premere invio, quindi dice
cosa è stato controllato, come, e cosa non lo è stato.

---

## 1. Quello che il plugin promette di fare

Ogni riga è segnata con **come** è stata verificata, non con quanta fiducia
ispira.

| Passo | Stato | Come è stato controllato |
|---|---|---|
| Installare e attivare senza un avviso | fatto | Installato dallo zip costruito, con `WP_DEBUG` acceso: nessun avviso, nessuna notice |
| Aggiungere un fornitore | fatto | 21 verifiche su HTTP, dai moduli veri, con tre utenti diversi |
| Collegare un articolo a uno o più fornitori | fatto | Pannello sulla scheda prodotto: verifiche su HTTP e `scripts/verify-product-panel.php` |
| Vedere cosa riordinare | fatto | `scripts/verify-requirements.php`: **sette query sia per 5 righe sia per 200** |
| Creare un ordine fornitore | fatto | `scripts/verify-orders.php`; due ordini salvati nello stesso istante prendono due numeri diversi |
| Mandarlo in PDF per email | fatto | `scripts/verify-pdf-email.php`: il PDF è riletto e contiene le righe; l'email è catturata |
| Ricevere la merce, anche in più volte | fatto | `scripts/verify-receipts.php` e su HTTP: **lo stesso modulo inviato due volte muove la giacenza una volta sola** |
| Correggere una ricezione sbagliata | fatto | Una scrittura opposta, mai una cancellazione: le due restano entrambe |
| Sapere cosa è già in viaggio | fatto | `scripts/verify-sprint7.php`: 47 verifiche |
| Importare un listino da CSV | fatto | Su HTTP con un file vero: BOM, punto e virgola, virgola decimale |
| Disinstallare senza perdere niente | fatto | `scripts/verify-uninstall.php`: 18 verifiche, §5 |

## 2. Plugin Check

```
Success: Checks complete. No errors found.
```

**Zero errori, zero avvisi**, eseguito sul pacchetto *installato dallo zip*, non
sulla cartella di sviluppo. La differenza conta: la prima volta la revisione
segnalava i miei script di banco, che nel pacchetto non ci sono mai stati.

Due cose imparate qui, già pagate da OxyArea e ripagate qui:

- **Plugin Check ignora `phpcs.xml.dist`.** Le esclusioni del progetto non
  valgono in revisione: tutte le deroghe stanno nel codice come `phpcs:ignore`,
  che leggono tutti e due gli strumenti.
- **I file nascosti vengono rifiutati**, anche quando arrivano da una dipendenza
  Composer. `thecodingmachine/safe` spedisce dei `.gitkeep`; ora il pacchetto li
  toglie.

## 3. Il cancello del rilascio

`docs/06_PIANO_TEST.md` rifiuta un rilascio con una qualsiasi di queste. Ognuna
è stata **cercata**, non data per assente.

| Cancello | Stato | Prova |
|---|---|---|
| Doppia ricezione della stessa merce | chiuso | Lo stesso modulo inviato due volte su HTTP: giacenza −2 → 8, poi 8. Quattro difese: chiave di idempotenza con indice unico, blocco sull'ordine, quantità riletta dentro la transazione, transazione |
| Numeri d'ordine duplicati | chiuso | Due ordini creati nello stesso istante prendono due numeri |
| Ricevere più di quanto ordinato | chiuso | Rifiutato, e **non scrive niente** |
| Stock disallineato | chiuso | Mosso dopo il commit, con l'incremento atomico di WooCommerce, mai leggi-e-riscrivi |
| Perdita di dati alla disinstallazione | chiuso | §5 |
| SQL injection | chiuso per costruzione | Ogni valore preparato; le uniche interpolazioni sono nomi di tabella da costanti nostre, ognuna annotata |
| XSS memorizzato | chiuso | Un indirizzo `ftp://` e uno `javascript:` scritti nei campi tornano indietro come testo |
| Accesso senza permesso | chiuso | Ogni schermata, ogni azione POST e ogni rotta REST hanno il loro controllo; provati come amministratore, magazziniere ed editore |
| PDF raggiungibile da chi non deve | chiuso | Un editore con lo stesso indirizzo non lo ottiene; senza nonce nemmeno l'amministratore |
| Import che scrive senza chiedere | chiuso | L'anteprima non tocca il database: righe prima e dopo identiche |

## 4. Conformità alle linee guida

| Linea guida | Stato |
|---|---|
| Niente trialware; il gratuito è completo in sé | Un negozio con un fornitore ci compra davvero. Nessuna chiave di licenza, nessun controllo disattivato. La merce in arrivo era prevista a pagamento nella specifica ed è qui: non sottrarla vuol dire far ricomprare merce già in viaggio |
| Compatibile GPL | GPL-2.0-or-later, dichiarata nell'intestazione, in `readme.txt`, in `composer.json`, e il testo è nel pacchetto |
| Sorgente leggibile | Nessun passo di build. Il CSS spedito è quello scritto. `vendor/` contiene una sola dipendenza di runtime (Dompdf) e il pacchetto porta `composer.json` e `composer.lock` che dicono cosa c'è dentro |
| Pronto per la traduzione | 330 stringhe, un solo dominio, `languages/oxysuppliers-for-woocommerce.pot` generato con `wp i18n make-pot` |
| Schermate | Sette, in `.wordpress-org/`, con le didascalie in `readme.txt`. Prese da un'installazione vera con `scripts/screenshots.mjs`, non disegnate: non possono allontanarsi da quello che il plugin fa |
| Nessun servizio esterno non dichiarato | Il plugin non contatta niente. L'unica email che parte è quella che l'utente preme per mandare, al fornitore che ha scritto lui |
| Nessun tracciamento | Nessuno |
| Intestazioni corrette | Nome, URI, descrizione, versione, requisiti, autore, licenza, dominio di testo, percorso del dominio |
| HPOS | Dichiarata compatibile; il plugin non tocca mai le tabelle degli ordini di WooCommerce |

## 5. Cosa succede disinstallando

La domanda vale la pena porla perché sbagliarla è silenzioso e definitivo: te ne
accorgi quando qualcuno ha già perso un anno di ordini.

`scripts/verify-uninstall.php` esegue `uninstall.php` **esattamente come lo
esegue WordPress** — in un processo a parte, con `WP_UNINSTALL_PLUGIN` definita
— e chiede tutte e due le cose:

- **Di serie non si perde niente.** Otto tabelle, le impostazioni e un fornitore
  vero sono ancora lì dopo la disinstallazione. Un ordine fornitore è un
  documento.
- **Se qualcuno lo chiede davvero, si perde tutto.** Con l'impostazione accesa:
  otto tabelle su otto sparite, opzioni cancellate, permessi tolti dai ruoli.

18 verifiche, tutte verdi. Due sono state scritte male la prima volta e hanno
insegnato qualcosa: un'opzione mai salvata non si può perdere (quindi va prima
salvata, o la prova non prova niente), e i ruoli vanno riletti dal database,
perché quelli in memoria sono di questo processo e la disinstallazione gira in
un altro.

## 6. Cosa hanno trovato le schermate

Le sette immagini non sono un adempimento: hanno trovato un difetto vero.

**Il pannello Fornitori sulla scheda prodotto non era mai stato vestito.** Il
foglio di stile si caricava sulle schermate il cui hook contiene lo slug del
plugin, e la schermata prodotto di WooCommerce non è una di quelle. Le regole di
WooCommerce, scritte per un campo per riga, schiacciavano ogni campo del listino
a pochi pixel: il codice del fornitore, il costo, il minimo, il multiplo — tutti
nella pagina, nessuno leggibile.

Nessuna prova lo aveva visto, e nessuna poteva: le verifiche su HTTP cercano il
testo nell'HTML, e il testo c'era. Un campo troppo stretto per mostrare quello
che contiene è vuoto per chi lo guarda, e questo lo dice solo un'immagine.

## 7. Prima di mandarlo

Una cosa sola, e non è codice.

**Il prefisso `Oxy-`.** La politica sui marchi pubblicata da Soflyy per Oxygen
dice testualmente di non usare «oxygen» o «oxy» nei nomi dei prodotti. Non
risulta un marchio Soflyy registrato, quindi è una politica privata e non un
diritto — ma WordPress.org interviene sulle segnalazioni di marchio, e **lo slug
non si cambia dopo l'approvazione**: l'esposizione è asimmetrica.

Riguarda tutta la famiglia OxyWP, non questo plugin. È una decisione
commerciale da prendere, non un difetto da correggere. Vedi
`docs/00_NAMING_CLEARANCE.md` e la stessa sezione in OxyArea, dove è raccontata
per esteso.

**Conseguenza pratica: OxySuppliers non si sottomette da solo.** Il primo
plugin approvato è quello che decide il prefisso per tutti, e deciderlo senza
saperlo è il modo peggiore. La famiglia va insieme.

Quello che questo costa: uno slug non si può prenotare. WordPress.org è
esplicito nel dire che non si manda un plugin vuoto per tenere un nome, quindi
`oxysuppliers-for-woocommerce` resta libero per chiunque fino al giorno in cui
lo si manda davvero.

Quello che **non** blocca: tutto il resto di questo documento è finito e
verificato. Il plugin è rilasciabile; semplicemente non lo si sta rilasciando.

## 8. Cosa non è stato verificato

Scritto piano, perché una cosa non verificata non è una cosa che funziona.

1. **`CatalogueRepository` non è coperto dalla CI.** La suite di integrazione
   non installa WooCommerce, e quella classe legge le tabelle di WooCommerce.
   È coperta sul banco, da `scripts/verify-requirements.php`, dove il conteggio
   delle query è anche l'unico posto in cui si può misurare. È una lacuna
   dichiarata, non una dimenticanza.
2. **La virgola decimale nei costi si prova solo sul banco**, perché a
   normalizzarla è `wc_format_decimal` e in CI WooCommerce non c'è.
3. **Nessuna traduzione italiana è spedita.** C'è il `.pot`; le traduzioni dei
   plugin ospitati su WordPress.org si fanno su translate.wordpress.org, e
   spedire un `.mo` proprio ci va contro. Come OxyArea.
4. **Il plugin non è stato provato su un negozio grande.** Il banco ha otto
   articoli e quindici ordini. La schermata dei fabbisogni è stata misurata a
   200 righe e costa le stesse sette query, ma settemila articoli sono un'altra
   domanda.
5. **Una persona sola ha guardato questo codice.** Una revisione di sicurezza
   fatta da chi non l'ha scritto non c'è stata. Le suite qui sotto restringono
   quello che quella revisione dovrebbe prendere per buono; non la sostituiscono.

## 9. I numeri

| | |
|---|---|
| File nel pacchetto distribuito | 777, 2,9 MB compressi |
| Test unitari | 89, senza WordPress |
| Test dentro WordPress | 81, suite di integrazione |
| Versioni di PHP provate in CI | 8.1, 8.2, 8.3, 8.4 |
| Verifiche sul negozio seminato | 47 (Sprint 7) + 18 (disinstallazione) + quelle degli sprint precedenti |
| Verifiche su HTTP | 117, con tre utenti diversi |
| `php -l` su ogni file spedito | 606 file, tutti puliti |
| PHPCS, standard WordPress | pulito |
| PHPStan livello 8 | pulito |
| Plugin Check | 0 errori, 0 avvisi |

## 10. Cosa viene dopo

Il PRO comincia solo quando le API del gratuito sono ferme. Il punto di innesto
esiste ed è esercitato — `oxysuppliers_requirement_strategy`, applicato nella
composizione e non dentro il motore — ma non è mai stato usato da un secondo
plugin. La prima cosa che il PRO insegnerà è quale di quei punti è sbagliato.
