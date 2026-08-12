# Oxy Suppliers — Fornitori e Ordini di Acquisto per WooCommerce

## 1. Visione del prodotto

**Nome di lavoro:** Oxy Suppliers  
**Tipo:** Plugin WordPress / WooCommerce  
**Target principale:** piccoli e medi e-commerce, rivenditori, negozi B2B/B2C, aziende con magazzino e fornitori multipli.  
**Obiettivo:** aggiungere a WooCommerce un ciclo di approvvigionamento semplice e nativo, senza trasformare WordPress in un ERP.

Il plugin deve rispondere in modo immediato alla domanda:

> **Cosa devo ordinare, a quale fornitore e in quale quantità?**

Il flusso ideale è:

**Prodotti WooCommerce → Fornitori → Fabbisogno → Proposta d'acquisto → Ordine fornitore → Ricezione merce → Aggiornamento stock/costo**

---

## 2. Problema da risolvere

WooCommerce gestisce bene il ciclo di vendita e le giacenze, ma non offre nativamente una gestione completa del ciclo di acquisto.

Le aziende devono spesso:
- gestire i fornitori fuori da WooCommerce;
- usare Excel per creare ordini di acquisto;
- ricordare manualmente quale fornitore vende un prodotto;
- controllare manualmente le scorte;
- copiare prezzi e quantità;
- aggiornare stock e costi dopo la ricezione;
- mantenere più strumenti non sincronizzati.

Il plugin deve concentrare queste attività nel backend WooCommerce.

---

## 3. Principi di prodotto

1. **Semplice prima di tutto.**
2. Nessun ERP nascosto dentro WordPress.
3. Compatibilità WooCommerce HPOS.
4. Uso delle API ufficiali WooCommerce per ordini e prodotti.
5. Tabelle custom per dati gestionali complessi, evitando post/meta non necessari.
6. Interfaccia coerente con WooCommerce.
7. Tutte le operazioni critiche devono essere tracciabili.
8. Nessuna modifica distruttiva allo stock senza audit log.
9. Architettura pronta a dialogare con altri plugin Oxy.
10. Free realmente utile; PRO dedicata ad automazione e workflow avanzati.

---

# 4. Moduli funzionali

## 4.1 Anagrafica fornitori

Creare una sezione:

**WooCommerce → Fornitori**

Campi minimi:
- ID fornitore
- ragione sociale
- nome commerciale
- P.IVA
- codice fiscale
- indirizzo
- CAP
- città
- provincia
- nazione
- email ordini
- email amministrativa
- telefono
- referente
- sito web
- condizioni di pagamento
- valuta
- giorni medi di consegna
- minimo d'ordine
- note
- stato attivo/non attivo

Campi PRO:
- più referenti;
- più indirizzi;
- condizioni di pagamento strutturate;
- IBAN;
- incoterm;
- rating interno;
- allegati/documenti.

---

## 4.2 Associazione prodotti-fornitori

Ogni prodotto o variazione WooCommerce può essere associato a uno o più fornitori.

Per ogni relazione prodotto/fornitore memorizzare:
- fornitore;
- codice/SKU del fornitore;
- descrizione del fornitore;
- prezzo di acquisto;
- valuta;
- quantità minima ordinabile;
- multiplo d'ordine;
- quantità per confezione;
- lead time;
- ultimo costo;
- data ultimo costo;
- fornitore preferenziale;
- note.

Esempio:

| Prodotto | Fornitore | Codice fornitore | Costo | Minimo | Multiplo | Lead time |
|---|---|---|---:|---:|---:|---:|
| Mouse X | ABC Srl | MX-123 | 11,80 € | 10 | 5 | 3 gg |
| Mouse X | DEF Spa | 7719 | 11,20 € | 20 | 10 | 7 gg |

---

# 5. Dashboard approvvigionamenti

Creare una pagina principale che mostri i prodotti che richiedono riordino.

Colonne:
- prodotto;
- SKU;
- stock attuale;
- stock riservato, se disponibile;
- stock disponibile;
- scorta minima;
- livello di riordino;
- vendite 7/30/90 giorni;
- fornitore preferenziale;
- costo;
- lead time;
- quantità suggerita;
- valore dell'ordine suggerito;
- stato.

Filtri:
- fornitore;
- categoria;
- stato stock;
- prodotto;
- SKU;
- data;
- solo prodotti sotto scorta;
- solo prodotti senza fornitore;
- solo prodotti senza costo.

Azioni massive:
- crea proposta ordine;
- assegna fornitore;
- ignora temporaneamente;
- esporta CSV.

---

# 6. Motore di suggerimento quantità

## MVP

Formula configurabile semplice:

`quantità da ordinare = stock obiettivo - stock disponibile`

con rispetto di:
- quantità minima;
- multiplo d'ordine;
- quantità per confezione.

Esempio:
- stock attuale = 3
- stock obiettivo = 18
- fabbisogno = 15
- multiplo fornitore = 10
- quantità suggerita = 20

## PRO

Modalità aggiuntive:
- consumo medio ultimi X giorni;
- copertura desiderata in giorni;
- lead time del fornitore;
- ordini clienti in corso;
- merce già ordinata e non ricevuta;
- stagionalità/manual override.

Formula evoluta indicativa:

`fabbisogno = domanda prevista durante lead time + scorta sicurezza - stock disponibile - merce in arrivo`

Il suggerimento non deve mai creare automaticamente un PO senza una specifica impostazione abilitata dall'amministratore.

---

# 7. Ordini di acquisto

Nuovo oggetto gestionale **Purchase Order / Ordine Fornitore**.

Campi testata:
- ID interno;
- numero PO;
- data;
- fornitore;
- stato;
- valuta;
- riferimento fornitore;
- data prevista consegna;
- indirizzo di consegna;
- condizioni pagamento;
- note interne;
- note per fornitore;
- totale imponibile;
- IVA opzionale;
- totale;
- autore;
- timestamp creazione/modifica.

Righe:
- prodotto WooCommerce;
- variazione;
- SKU interno;
- codice fornitore;
- descrizione;
- quantità ordinata;
- quantità ricevuta;
- quantità residua;
- costo unitario;
- sconto;
- aliquota;
- totale.

---

# 8. Stati ordine fornitore

Stati consigliati:
1. **Bozza**
2. **Da inviare**
3. **Inviato**
4. **Confermato**
5. **Parzialmente ricevuto**
6. **Ricevuto**
7. **Annullato**

PRO:
- In approvazione
- Rifiutato
- In ritardo
- Chiuso con differenze

---

# 9. Generazione ordine fornitore

L'utente deve poter creare un PO:

### Modalità A — manuale
Seleziona fornitore → aggiunge prodotti → quantità/costi → salva.

### Modalità B — da dashboard fabbisogni
Seleziona righe → **Crea ordine fornitore**.

Il sistema deve raggruppare automaticamente i prodotti per fornitore.

### Modalità C — PRO automatica
Regole preconfigurate generano bozze di PO quando vengono raggiunte soglie.

Non inviare automaticamente al fornitore nella prima versione PRO senza un'opzione esplicita.

---

# 10. PDF ordine fornitore

Generare PDF professionale contenente:
- logo;
- dati azienda;
- dati fornitore;
- numero/data ordine;
- indirizzo consegna;
- tabella prodotti;
- codici articolo;
- quantità;
- prezzi, configurabile;
- totali;
- condizioni;
- note.

Funzioni:
- download;
- stampa;
- invio email;
- rigenera PDF.

Template sovrascrivibile/estensibile.

---

# 11. Invio al fornitore

Da PO:
**Invia ordine**

Email configurabile con:
- destinatario automatico;
- CC/BCC;
- oggetto;
- testo;
- PDF allegato.

Registrare:
- data/ora invio;
- utente;
- destinatario;
- eventuale reinvio.

PRO:
- template multipli;
- invio automatico;
- reminder;
- copia ad altri referenti.

---

# 12. Ricezione merce

Schermata semplice per ricevere:
- tutto il PO;
- singole righe;
- quantità parziali.

Esempio:

| Prodotto | Ordinato | Già ricevuto | Ricevuto ora | Residuo |
|---|---:|---:|---:|---:|
| A | 20 | 0 | 15 | 5 |
| B | 10 | 5 | 5 | 0 |

Alla conferma:
- aggiornare quantità ricevuta;
- aggiornare stato PO;
- opzionalmente incrementare stock WooCommerce;
- registrare movimento;
- memorizzare costo effettivo.

Deve esserci protezione contro doppia ricezione.

---

# 13. Aggiornamento stock

Impostazioni:
- aggiorna stock automaticamente alla ricezione: sì/no;
- aggiorna solo prodotti con gestione stock WooCommerce attiva;
- log completo prima/dopo;
- possibilità di annullare una ricezione tramite movimento inverso, non cancellando il log.

---

# 14. Gestione costo

Alla ricezione:
- costo ordinato;
- costo realmente fatturato;
- eventuali spese accessorie.

Opzioni:
- non aggiornare costo prodotto;
- aggiorna ultimo costo;
- calcola costo medio ponderato, PRO;
- passa il costo a Oxy Margin.

Integrazione desiderata:
**Oxy Suppliers → nuovo costo → Oxy Margin → margine aggiornato**

L'integrazione deve avvenire tramite hooks/API interne e non tramite dipendenza rigida.

---

# 15. Merce in arrivo

Ogni prodotto deve poter mostrare:
- stock fisico;
- quantità ordinata ai fornitori;
- quantità attesa;
- prima data consegna prevista.

Esempio:

`Stock: 4 | In arrivo: 30 | ETA: 18/08/2026`

---

# 16. Storico prezzi fornitori

PRO consigliata.

Registrare variazioni:
- data;
- fornitore;
- prodotto;
- vecchio costo;
- nuovo costo;
- PO sorgente.

Report:
- andamento costo;
- variazione %;
- confronto fornitori;
- ultimo/miglior costo.

---

# 17. Confronto fornitori

PRO.

Per un prodotto mostrare:
- prezzi;
- MOQ;
- multipli;
- lead time;
- affidabilità;
- ultimo acquisto.

Il prezzo più basso non deve necessariamente essere selezionato come "migliore": consentire all'utente di scegliere il fornitore predefinito.

---

# 18. Report

Free:
- valore ordini acquisto;
- PO aperti;
- prodotti sotto scorta;
- ordini in ritardo.

PRO:
- acquisti per fornitore;
- variazione costi;
- tempi medi consegna;
- puntualità;
- differenze ordinate/ricevute;
- valore merce in arrivo;
- dipendenza da singolo fornitore.

---

# 19. Notifiche

PRO:
- prodotto sotto livello riordino;
- PO non inviato;
- consegna prevista scaduta;
- ricezione parziale non completata;
- variazione costo superiore a X%;
- fornitore non confermato.

---

# 20. Free vs PRO

## FREE
- anagrafica fornitori;
- associazione prodotto-fornitore;
- costo fornitore;
- fornitore preferenziale;
- scorta minima;
- dashboard fabbisogni base;
- creazione manuale PO;
- creazione PO da fabbisogni;
- PDF;
- email manuale;
- ricezione completa/parziale;
- aggiornamento stock;
- storico PO;
- HPOS.

## PRO
- fornitori multipli avanzati;
- suggerimento quantità basato su vendite/lead time;
- generazione automatica bozze;
- costo medio;
- storico costi;
- comparazione fornitori;
- workflow approvativo;
- notifiche/reminder;
- report avanzati;
- allegati;
- import/export avanzato;
- API/webhook;
- integrazioni con altri plugin Oxy.

La versione FREE deve essere pienamente utilizzabile e non una demo.

---

# 21. Struttura dati suggerita

Tabelle custom, prefisso WordPress:

- `{prefix}oxy_suppliers`
- `{prefix}oxy_supplier_products`
- `{prefix}oxy_purchase_orders`
- `{prefix}oxy_purchase_order_items`
- `{prefix}oxy_purchase_receipts`
- `{prefix}oxy_purchase_receipt_items`
- `{prefix}oxy_supplier_cost_history`
- `{prefix}oxy_purchase_logs`

Usare `$wpdb` e `dbDelta()` nelle migration controllate.

Non modificare direttamente le tabelle WooCommerce.

---

# 22. Compatibilità

Obbligatorie:
- WordPress stabile corrente;
- WooCommerce stabile corrente;
- HPOS;
- prodotti semplici;
- prodotti variabili;
- PHP supportato da WordPress/WooCommerce;
- multisite almeno senza errori fatali.

Da verificare/testare:
- WPML/Polylang;
- WooCommerce Multilingual;
- plugin multi-warehouse;
- plugin cost of goods;
- Oxy Margin.

---

# 23. Sicurezza

- capability dedicate;
- nonce per operazioni amministrative;
- sanitizzazione input;
- escaping output;
- prepared statements;
- nessuna esposizione di PO via frontend;
- protezione PDF;
- controllo autorizzazioni REST;
- audit log;
- niente file eseguibili negli upload;
- protezione CSRF/XSS/SQL injection;
- controlli race condition sulle ricezioni.

---

# 24. Ruoli/capability

Esempi:
- `oxy_manage_suppliers`
- `oxy_view_purchase_orders`
- `oxy_create_purchase_orders`
- `oxy_send_purchase_orders`
- `oxy_receive_purchase_orders`
- `oxy_manage_purchase_settings`
- `oxy_view_purchase_reports`

Administrator e Shop Manager devono ricevere capability coerenti all'attivazione.

---

# 25. API e hooks

Prevedere REST API namespaced:
`/wp-json/oxy-suppliers/v1/...`

Hooks esempio:
- `oxy_supplier_created`
- `oxy_purchase_order_created`
- `oxy_purchase_order_sent`
- `oxy_purchase_receipt_created`
- `oxy_supplier_cost_changed`

Filtri per:
- algoritmo fabbisogno;
- numerazione;
- PDF;
- email;
- aggiornamento costo.

---

# 26. Importazione iniziale

Import CSV fornitori:
- ragione sociale;
- P.IVA;
- email;
- codice prodotto;
- SKU;
- costo;
- MOQ;
- multiplo;
- lead time.

Preview obbligatoria prima dell'import definitivo.

---

# 27. UX

Menu suggerito:

**WooCommerce**
- Acquisti
  - Dashboard
  - Ordini fornitori
  - Fornitori
  - Ricezioni
  - Report
  - Impostazioni

Nel prodotto:
tab/pannello **Fornitori**.

Ridurre al minimo modali e wizard non necessari.

---

# 28. MVP di sviluppo

## Sprint 1
- bootstrap plugin;
- activation/migrations;
- capability;
- anagrafica fornitori.

## Sprint 2
- relazione prodotto-fornitore;
- costo/MOQ/multiplo/lead time;
- UI prodotto.

## Sprint 3
- dashboard fabbisogno;
- algoritmo base;
- filtri.

## Sprint 4
- PO testata/righe;
- numerazione;
- stati.

## Sprint 5
- PDF;
- email;
- log.

## Sprint 6
- ricezioni parziali;
- aggiornamento stock;
- protezione doppia ricezione.

## Sprint 7
- test HPOS;
- sicurezza;
- performance;
- accessibility admin;
- internationalization.

## Sprint 8
- packaging WordPress.org;
- documentazione;
- test uninstall/data retention.

---

# 29. Acceptance criteria MVP

Il plugin è pronto quando:
1. posso creare un fornitore;
2. posso associarlo a un prodotto/variazione;
3. posso memorizzare costo, MOQ e multiplo;
4. vedo i prodotti sotto scorta;
5. ottengo una quantità suggerita;
6. creo un PO;
7. genero un PDF;
8. invio il PO via email;
9. ricevo la merce totalmente o parzialmente;
10. lo stock WooCommerce viene aggiornato correttamente;
11. ogni variazione di stock è tracciata;
12. HPOS funziona senza accesso diretto alle legacy order tables;
13. non esistono fatal/error/warning con WP_DEBUG;
14. i permessi sono rispettati.

---

# 30. Fuori scope MVP

- contabilità fornitori;
- registrazione fatture passive;
- pagamenti;
- prima nota;
- MRP;
- produzione;
- warehouse management completo;
- EDI;
- dropshipping automatico;
- marketplace fornitori.

---

# 31. Differenziazione

Il mercato ha già strumenti molto completi, tra cui ATUM. Oxy Suppliers non deve cercare di batterli sul numero di funzioni.

Posizionamento:

> **Il modo semplice per gestire fornitori, riordini e acquisti direttamente da WooCommerce.**

Vantaggi:
- flusso operativo breve;
- UI italiana/chiara;
- nessuna configurazione da ERP;
- funzionalità essenziali già nella FREE;
- integrazione naturale con Oxy Margin;
- possibilità futura di integrazione Oxy DDT.

---

# 32. Metriche di successo

- tempo medio creazione PO;
- percentuale PO generati da suggerimenti;
- prodotti senza fornitore;
- stock-out evitati;
- accuratezza suggerimenti;
- tasso installazione → primo PO;
- retention plugin a 30/90 giorni.

---

# 33. Note per l'agente di sviluppo

Priorità assolute:
1. integrità stock;
2. HPOS;
3. sicurezza;
4. semplicità UI;
5. nessuna dipendenza da servizi SaaS.

Non implementare funzioni PRO nel core FREE come codice morto utilizzabile tramite semplici flag. Predisporre invece hooks e interfacce stabili per un add-on PRO separato.

Tutte le stringhe devono essere traducibili.
Non usare nomi di tabelle o option generici.
Usare namespace PHP e autoloading.
Seguire WordPress Coding Standards.
