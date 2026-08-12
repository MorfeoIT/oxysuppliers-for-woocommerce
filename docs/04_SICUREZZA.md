# Sicurezza e integrità

La priorità numero uno di questo plugin non è una funzione: è che **lo stock
resti giusto**. Tutto il resto viene dopo.

## Capability

Sette, tutte prefissate. La specifica (§24) le propone con `oxy_`: corrette.

| Capability | Permette |
|---|---|
| `oxysuppliers_manage_suppliers` | creare e modificare fornitori e listini |
| `oxysuppliers_view_purchase_orders` | vedere ordini fornitore e ricezioni |
| `oxysuppliers_create_purchase_orders` | creare e modificare PO |
| `oxysuppliers_send_purchase_orders` | inviare il PO al fornitore |
| `oxysuppliers_receive_purchase_orders` | registrare ricezioni |
| `oxysuppliers_manage_settings` | impostazioni del plugin |
| `oxysuppliers_view_reports` | report |

Concesse all'attivazione a `administrator` e `shop_manager`, e — trappola già
pagata su OxyProfit — **anche agli aggiornamenti**: le capability nuove di una
versione successiva non arrivano mai se si concedono solo in `activate()`.
Il `Migrator` le riconcilia a ogni cambio di versione.

Nessuna capability si controlla con `is_admin()` o con il ruolo: sempre
`current_user_can()`, sull'oggetto quando c'è.

## Nonce e input

Nonce dedicato per ogni operazione che scrive (`oxysuppliers_save_supplier`,
`oxysuppliers_receive_<po_id>`, …). Sanitizzazione in ingresso, escaping in
uscita, `$wpdb->prepare()` sempre — anche per i nomi di colonna in un
ordinamento, che vanno passati per una **allowlist**, mai interpolati.

I PO non esistono sul frontend. Il PDF non è un file in `uploads` con un nome
indovinabile: si serve da un endpoint che controlla la capability, con
`Content-Disposition` e nessuna esecuzione possibile. Nessun file caricato
finisce in una cartella eseguibile.

REST: ogni rotta ha il suo `permission_callback`, e non è mai
`__return_true`.

## Doppia ricezione: come si impedisce davvero

È il punto in cui questo plugin può fare un danno irreparabile. Un negozio con
una giacenza sbagliata vende quello che non ha.

**Il livello che conta è il database, non l'interfaccia.** Disabilitare il
pulsante dopo il clic non serve: non copre il ricaricamento della pagina, il
tasto indietro, la doppia scheda, il timeout con retry, né due magazzinieri sul
telefono nello stesso momento.

Quattro difese, in quest'ordine:

1. **Chiave di idempotenza unica.** Il form di ricezione porta una
   `idempotency_key` generata al **rendering** della schermata (non
   all'invio). `receipts.idempotency_key` ha un indice unico: il secondo INSERT
   fallisce, e il fallimento si legge come «già fatto», mostrando la ricezione
   esistente invece di un errore.
2. **Blocco sul PO per la durata della registrazione.** Un `UPDATE … SET
   lock_token = … WHERE id = ? AND lock_token IS NULL` che restituisce 0 righe
   dice che qualcun altro sta scrivendo. Non `GET_LOCK()`, che si comporta
   diversamente sui gestiti; non un transient, che può essere su un object
   cache non condiviso fra processi.
3. **Nessuna quantità ricevuta oltre l'ordinato**, salvo tolleranza esplicita
   nelle impostazioni. Il controllo si fa **dentro** la transazione, rileggendo
   la somma delle ricezioni, non il valore che aveva la pagina quando è stata
   aperta.
4. **Transazione sulle nostre tabelle** (`START TRANSACTION` / `COMMIT`), con
   l'aggiornamento dello stock WooCommerce **fuori** e dopo il commit, perché
   non è nostro e non partecipa alla nostra transazione.

## Aggiornamento dello stock

- Sempre `wc_update_product_stock( $product, $qty, 'increase' )`, che fa un
  incremento atomico in SQL. **Mai** leggere lo stock, sommare in PHP e
  riscriverlo: fra la lettura e la scrittura ci sta un ordine di un cliente.
- Solo per i prodotti con `manage_stock` attivo. Per gli altri si registra la
  ricezione e si scrive nel log che lo stock non è stato toccato, con il motivo.
- **Prima e dopo nel log, sempre.** Nessuna modifica di stock senza una riga che
  dica chi, quando, da quanto a quanto e per quale ricezione.
- L'annullamento è un **movimento inverso**, mai una cancellazione: ricezione
  compensativa con quantità negative, che decrementa lo stock e lascia entrambe
  le righe nella storia.

## Costi

Il costo che conta è quello **realmente fatturato** alla ricezione, non quello
scritto sull'ordine. Quando differisce, la differenza va registrata: è la
domanda a cui il cliente vorrà rispondere («quanto mi è costato davvero?»).

Attenzione alla lezione presa su OxyProfit: **correggere un costo non è
appendere un evento**. Dettaglio in `05_INTEGRAZIONE_OXYPROFIT.md`.

## Email

L'invio al fornitore è un'azione irreversibile con un destinatario esterno.
Quindi: conferma esplicita, destinatario mostrato **prima** dell'invio, e
registrazione di data, utente, destinatario ed eventuale reinvio. Nessun invio
automatico nel FREE, e nel PRO solo con un'opzione accesa a mano.

Nel banco di prova la posta **non si spedisce, si cattura**: un mu-plugin scrive
in `wp-content/mail.log`. Gli indirizzi di prova sono `@example.test`, che per
progetto non risolve.

## Cosa non fa il plugin

Non registra fatture, non gestisce pagamenti, non fa prima nota, non fa MRP, non
è un WMS, non parla EDI. Sono fuori scope dichiarati (§30) e restano fuori: un
ERP nascosto dentro WordPress è il modo più veloce di rendere il plugin
inutilizzabile per chi lo voleva semplice.
