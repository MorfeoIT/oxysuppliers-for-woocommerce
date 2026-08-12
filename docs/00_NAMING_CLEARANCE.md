# Verifica del nome — OxySuppliers

Fatta il 12/08/2026, seguendo la procedura nata dai quattro nomi bruciati di
OxyProfit («WooCommerce Profit Intelligence», «Profit Intelligence», «Margin
Lens», «Stadera»).

## Nome scelto

**OxySuppliers – Suppliers & Purchase Orders for WooCommerce**
Slug `oxysuppliers-for-woocommerce`.

La parte distintiva è **Oxy-**, il prefisso di casa (Oxysoft, oxywp.com,
OxyMessenger, OxyProfit, OxyArea, OxyWait). «Suppliers» e «Purchase Orders» sono
descrittivi: nessuno può possederli e nessuno può contestarli. È esattamente il
contrario del percorso di OxyProfit, dove si cercava un marchio isolato.

Il suffisso `-for-woocommerce` non è cosmetico: la linea guida 17 di
WordPress.org vieta di **iniziare** il nome con un marchio altrui, mentre «for
WooCommerce» in coda è la forma ammessa.

## Controlli eseguiti (12/08/2026)

| Controllo | Esito |
|---|---|
| `api.wordpress.org/plugins/info/1.0/oxysuppliers-for-woocommerce.json` | 404 → slug **libero** |
| idem per `oxysuppliers` e `oxy-suppliers` | 404 → liberi |
| `query_plugins` ricerca «oxysuppliers» | **nessun risultato** |
| `query_plugins` ricerca «oxy» | 7 risultati, nessuna collisione: Oxyplug (Prefetch, Howto Maker), Better Editor for Oxygen, OXY Re-Login Window, OxyGridLayout by OxyNinja, più due estranei |

Da completare prima della sottomissione, con la stessa lista usata su OxyProfit:
`packagist.org/search.json`, `registry.npmjs.org`, `api.github.com/search/repositories`
(**aprendo i risultati**, non contandoli), `rdap.verisign.com` sui domini
`oxysuppliers.*`, e una ricerca web sulla **parola nuda**, mai più nomi in OR.

## Il rischio vero non è questo nome

Il rischio è il **prefisso Oxy-**, e riguarda tutta la famiglia.

Soflyy, che fa il page builder **Oxygen**, pubblica su
`oxygenbuilder.com/brand/` una policy che dice testualmente *«Do not use
"oxygen" or "oxy" in product names»* e, della policy della WordPress Foundation,
*«WE WILL ENFORCE THE EXACT SAME POLICY.»*

Tre cose vere insieme:

1. **È una policy privata, non un diritto.** Non risulta un marchio «Oxygen»
   registrato da Soflyy: la registrazione USPTO 87799894 per OXYGEN in classe 9
   è di Oxygen, Inc., una fintech estranea a entrambi.
2. **È comunque il rischio più concreto**, per dove cadrebbe: WordPress.org
   agisce sulle segnalazioni di marchio e **lo slug non si cambia dopo
   l'approvazione**. L'esposizione è asimmetrica — a loro costa una email, a noi
   il nome.
3. Esiste un **precedente, non un permesso**: nella directory c'è
   `oxy-relogin-window`, che con Oxygen non c'entra nulla.

A favore: «Oxy» è la radice di **Oxysoft**, il nome dell'azienda; i plugin OxyWP
non sono addon di Oxygen né si presentano come tali; e oxywp.com esiste già.

C'è poi la somiglianza con **oxysales, UAB / Oxylabs**, che rivendica «OXY» da
solo in classi 9 e 42 (EUTM 017875311, domanda USA 88853021) con enforcement
documentato. Settori lontani (proxy/scraping contro acquisti e-commerce), ma da
mettere in conto nella ricerca vera.

## Conseguenza operativa

**Oxy Suppliers non si sottomette da solo a WordPress.org.** Vale la decisione
del 12/08/2026 presa su OxyArea: la famiglia si sottomette insieme, perché il
primo plugin approvato è quello che fissa in pratica il prefisso. Il prezzo
dell'attesa è che lo slug resta prendibile da chiunque fino al giorno della
sottomissione — WordPress.org vieta di proporre un plugin vuoto per prenotare un
nome.

I registri ufficiali (TMview, EUIPO, UIBM, WIPO, USPTO) **non sono
interrogabili da qui**: rifiutano le chiamate che non arrivano da un browser.
L'unico che risponde a uno script è UIBM. Il controllo di somiglianza va fatto a
mano o da un consulente, ed è l'ultimo blocco non tecnico della famiglia.
