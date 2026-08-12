# Piano di test

Tre livelli, e nessuno dei tre sostituisce gli altri. Su OxyProfit gli
integration check su un negozio vero hanno trovato **sei difetti di classe
diversa** che nessun test unit aveva visto; su OxyArea il difetto peggiore
viveva nell'unico posto che nessuno strumento guardava, cioè cosa succede dopo
un redirect.

## 1. Unit — senza WordPress, senza database

Coprono `Domain` e `Engine`: arrotondamento a minimo/multiplo/confezione,
formula del fabbisogno, valuta e denaro, macchina a stati del PO, calcolo dei
totali di riga, residui.

Girano su PHP 8.1→8.4 in GitHub Actions e in locale (PHP 8.3 in
`C:\Users\manol\tools\php83`, già nel PATH utente).

## 2. Integration — dentro un WordPress vero

`wp-phpunit/wp-phpunit` + `yoast/phpunit-polyfills`. Due trappole che costano
un'ora ciascuna e sono già state pagate:

- **PHPUnit va bloccato a `^9.6`, non 10.** La libreria di test di WordPress
  chiama `PHPUnit\Util\Test::parseTestMethodAnnotations()`, che la 10 ha
  rimosso: con la 10 le suite non partono proprio. Serve anche
  `--dont-report-useless-tests` e uno `phpunit.xml.dist` con lo schema 9.6.
- **La variabile che accende WordPress nel bootstrap è
  `WP_PHPUNIT__TESTS_CONFIG`, mai `WP_PHPUNIT__DIR`**: quest'ultima risulta
  sempre impostata perché il pacchetto la scrive da un autoload di Composer, e
  usarla come interruttore fa provare a caricare WordPress anche alla suite
  unit.

Un solo `tests/bootstrap.php` serve entrambe le suite: se
`WP_PHPUNIT__TESTS_CONFIG` non c'è, fa `return` dopo l'autoloader e la suite
unit gira anche su una macchina senza database.

**Mai puntare la libreria di test al database di un sito**: svuota e ricrea ogni
tabella a ogni esecuzione. In CI è un container di servizio.

## 3. Banco di prova — un negozio vero

`https://test.44123.it/oxysuppliers` sul server Hestia `ph.oxysoft.it`, database
dedicato creato con `v-add-database`, WP_DEBUG con log su file, `plugin-check`
attivo.

WordPress pulito **più WooCommerce e nient'altro**: un banco di prova esiste per
rendere ovvio di chi è la colpa, e ogni plugin in più è un sospettato. HPOS
attivo, valuta EUR.

Il banco va **seminato con un negozio piccolo ma vero**: prodotti semplici e
variabili, due fornitori sullo stesso articolo con listini diversi, giacenze
sotto e sopra la scorta, ordini di vendita distribuiti su più mesi (servono per
le vendite 7/30/90 giorni). Una prova su dati vuoti non è una prova.

Posta: **catturata, non spedita**, con un mu-plugin che scrive in
`wp-content/mail.log`. Indirizzi `@example.test`.

## Cosa si prova solo qui

- ricezione parziale, poi completamento, poi tentativo di ricezione oltre
  l'ordinato;
- **doppio invio del form di ricezione** (stessa `idempotency_key`): una sola
  riga, stock incrementato una volta sola;
- due ricezioni concorrenti sullo stesso PO;
- annullamento di una ricezione e ripristino esatto dello stock, con entrambe le
  righe nel log;
- prodotto senza `manage_stock`: ricezione registrata, stock non toccato,
  motivo nel log;
- prodotto variabile: la riga colpisce la variazione, non il padre;
- PDF: si scarica, non è raggiungibile da chi non ha la capability;
- una utenza `shop_manager` e una `editor`: la seconda non vede niente.

## Trappole di WP-CLI già incontrate

`wp eval-file` valuta il file preceduto da `?>`: il file **deve** iniziare con
`<?php` e **non può** contenere `declare(strict_types=1)`. E `wp eval` con
codice inline non sopravvive ai backslash dei namespace: usare sempre un file.

Un test che stampa `0 passati, 0 falliti` non sta dicendo che è andato bene: in
`wp eval-file` i contatori vanno in `$GLOBALS`, e le asserzioni sui menu admin
da WP-CLI non valgono niente.

## Plugin Check

`wp plugin check oxysuppliers-for-woocommerce`. **Ignora il `phpcs.xml.dist` del
progetto** e applica il proprio ruleset: ogni esclusione messa nel ruleset fa
apparire il codice pulito in locale e fallire la revisione. Dove uno sniff
davvero non si applica, il motivo va nel codice con `// phpcs:ignore`.

Il `phpcs.xml.dist` non deve mai essere più permissivo di Plugin Check. Da
verificare anche la descrizione breve del `readme.txt`: massimo 150 caratteri.

## Verifica del web, mai col browser integrato

I controlli sulle pagine si fanno con **curl** (e jsdom dove serve leggere il
DOM). Il browser integrato manda in crash il PC.

## CI

GitHub Actions a ogni push: PHPCS, PHPStan livello 8, unit su quattro versioni
di PHP, integration su un container MySQL, e un lavoro che **costruisce il
pacchetto con `git archive` e lo ispeziona**. Quel lavoro su OxyArea si è
ripagato al primo giro: il plugin dichiarava «GPLv2 or later» in due posti e non
spediva la licenza.
