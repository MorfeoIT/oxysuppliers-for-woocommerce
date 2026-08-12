# Modello dati

Tabelle custom con `$wpdb->prefix`, create e aggiornate da un `Migrator` con
`dbDelta()` e una `oxysuppliers_db_version` in `wp_options`. Le tabelle di
WooCommerce **non si toccano mai**.

> La specifica originale (§21) propone `{prefix}oxy_suppliers`. Corretto in
> `{prefix}oxysuppliers_*`: `oxy_` è troppo corto, si confonde con l'ecosistema
> Oxygen e collide con gli altri plugin della famiglia.

## Le otto tabelle

| Tabella | Contiene |
|---|---|
| `{prefix}oxysuppliers_suppliers` | anagrafica fornitori |
| `{prefix}oxysuppliers_supplier_products` | relazione prodotto/variazione ↔ fornitore |
| `{prefix}oxysuppliers_purchase_orders` | testata dell'ordine fornitore |
| `{prefix}oxysuppliers_purchase_order_items` | righe dell'ordine |
| `{prefix}oxysuppliers_receipts` | testata della ricezione |
| `{prefix}oxysuppliers_receipt_items` | righe ricevute |
| `{prefix}oxysuppliers_cost_history` | variazioni di costo (scrive il FREE, legge il PRO) |
| `{prefix}oxysuppliers_logs` | audit log |

## Regole comuni

**Il denaro è un intero.** `*_cents BIGINT` più una colonna `currency CHAR(3)`
accanto. Mai `DECIMAL` letto in PHP come `float`, mai un importo senza la sua
valuta a fianco: un ordine in USD e uno in EUR non si sommano, e il posto dove
se ne accorge è il totale, cioè troppo tardi.

**Le quantità sono interi.** Se un giorno servissero i decimali (merce a peso)
si introduce una scala fissa, non un `FLOAT`.

**Chiavi esterne logiche, non vincoli.** `dbDelta()` non gestisce le FOREIGN
KEY e molte installazioni condivise non le reggono: l'integrità la fa il codice,
con gli indici che servono.

**Niente `ON DELETE CASCADE` implicito.** Un fornitore cancellato non porta via
gli ordini che gli sono stati fatti: si disattiva (`status`), non si cancella.
Cancellare un fornitore con ordini è vietato dal codice.

## Colonne che meritano una nota

### `supplier_products`

Chiave unica su `(supplier_id, product_id, variation_id)` — un fornitore ha un
solo listino per un articolo. `variation_id` è `0`, non `NULL`, per i prodotti
semplici: `NULL` in un indice unico non impedisce i duplicati.

Vive qui la terna che decide la quantità ordinabile: `min_order_qty`,
`order_multiple`, `pack_qty`. E `is_preferred TINYINT(1)` con un indice
`(product_id, variation_id, is_preferred)`: la dashboard lo interroga per ogni
riga.

### `purchase_orders`

`po_number VARCHAR(32)` con indice **unico**. La numerazione si genera in
transazione e si scrive con l'unicità come rete di sicurezza: due utenti che
salvano nello stesso secondo devono ottenere due numeri, e il secondo INSERT
deve fallire e riprovare, non sovrascrivere.

`status VARCHAR(20)` (non un `ENUM`: aggiungere uno stato PRO non deve
richiedere un `ALTER TABLE`), con indice su `(status, expected_date)` per il
report degli ordini in ritardo.

Totali memorizzati (`subtotal_cents`, `tax_cents`, `total_cents`) e ricalcolati
al salvataggio dalle righe. Sono una cache, non la verità: un controllo di
integrità li riconcilia.

### `purchase_order_items`

`qty_ordered` e `qty_received` interi. `qty_received` è **derivabile** dalla
somma delle righe di ricezione: si tiene sulla riga perché la lista la legge
mille volte, ma la somma resta la verità e un controllo periodico le confronta.
Il residuo non si memorizza affatto: è `qty_ordered − qty_received`, e un terzo
numero che può divergere dagli altri due è solo un modo in più di sbagliare.

`unit_cost_cents`, `discount_percent`, `tax_rate` e `line_total_cents`.

### `receipts` e `receipt_items`

`receipts.idempotency_key VARCHAR(64)` con indice **unico**: è la protezione
contro la doppia ricezione richiesta dalla specifica (§12). La chiave nasce nel
form ed è la stessa se l'utente preme «conferma» due volte, ricarica la pagina o
il browser ritenta la POST. Dettaglio in `04_SICUREZZA.md`.

`receipts.reverses_receipt_id` — un annullamento è una **ricezione
compensativa** con quantità negative che punta all'originale. Non si cancella
una ricezione e non si modifica il log: la lezione arriva da OxyProfit, dove
correggere in luogo faceva divergere due numeri in silenzio.

`receipt_items.actual_unit_cost_cents` — il costo davvero fatturato, che può
differire da quello ordinato (§14). È questo che va a OxyProfit, non il costo
dell'ordine.

### `cost_history`

Scrive il FREE (registrare un fatto costa niente e serve al log), legge il PRO
(i report di andamento costo sono a pagamento). Righe: data, fornitore,
prodotto, vecchio costo, nuovo costo, PO sorgente.

Questo **non è** codice PRO dormiente nel FREE: il FREE non mostra nessun report
di storico e non ha schermate spente da riaccendere. Scrive un registro che usa
per l'audit; il PRO ci costruisce sopra delle viste che il FREE non contiene.

### `logs`

Chi, quando, cosa, prima e dopo. Ogni variazione di stock ci passa. Non è
cancellabile dall'interfaccia. Ha una rotazione configurabile — un audit log che
riempie il disco viene disattivato dal cliente, e allora non serve a niente.

## Disinstallazione

`uninstall.php` **non distrugge per difetto**. Le tabelle restano a meno che
l'amministratore non abbia esplicitamente acceso «rimuovi tutti i dati» nelle
impostazioni. Un ordine fornitore è un documento: nessuno si aspetta che sparisca
disattivando un plugin.

## Multisite

Tabelle per sito (`$wpdb->prefix`, non `base_prefix`). L'obiettivo dichiarato
della specifica è «almeno senza errori fatali»: le migrazioni girano al primo
caricamento su ogni sito, non solo all'attivazione, perché
`activate_<plugin>` su una attivazione di rete non passa per i singoli siti.
