# OxySuppliers – Suppliers & Purchase Orders for WooCommerce

Ciclo di approvvigionamento nativo dentro WooCommerce: anagrafica fornitori,
associazione prodotto-fornitore, fabbisogni, ordini di acquisto, PDF, invio al
fornitore, ricezione merce e aggiornamento di stock e costi.

Risponde a una domanda sola: **cosa devo ordinare, a quale fornitore e in quale
quantità?**

## Identità tecnica (bloccata)

| | |
|---|---|
| Slug / text domain | `oxysuppliers-for-woocommerce` |
| Namespace PHP | `Oxysoft\OxySuppliers` |
| Prefisso (tabelle, opzioni, hook, capability, cron) | `oxysuppliers_` |
| Costanti | `OXYSUPPLIERS_` |
| REST | `/wp-json/oxysuppliers/v1/` |
| Lingua del codice e delle stringhe | inglese; l'italiano è una traduzione (`languages/`) |

**Mai `oxy_`**: è troppo corto, si confonde con l'ecosistema Oxygen Builder e
collide con gli altri plugin della famiglia. La specifica originale
(`docs/00_SPEC_ORIGINALE.md`) usa `oxy_`: è stata corretta ovunque nei documenti
di progetto.

## Famiglia

Fa parte di **OxyWP** insieme a OxyProfit, OxyArea, OxyWait e Easy Mobile
Product Upload. Nessun dominio dedicato: la pagina prodotto vive su
`oxywp.com/plugins/oxysuppliers-for-woocommerce/`.

Due plugin separati, due repository:

- `oxysuppliers-for-woocommerce` — FREE, privato oggi, **pubblico al rilascio**;
- `oxysuppliers-for-woocommerce-pro` — PRO, privato sempre.

Nel FREE non deve esistere codice PRO dormiente sbloccato da una licenza: è la
linea guida 5 di WordPress.org sul trialware.

## Documenti

| File | Cosa contiene |
|---|---|
| `docs/00_SPEC_ORIGINALE.md` | la specifica di partenza, com'è arrivata |
| `docs/00_NAMING_CLEARANCE.md` | verifica del nome e rischio marchio |
| `docs/01_ARCHITETTURA.md` | strati, dipendenze, regole di scrittura |
| `docs/02_MODELLO_DATI.md` | tabelle, indici, migrazioni |
| `docs/03_FREE_VS_PRO.md` | confine commerciale e tecnico fra i due plugin |
| `docs/04_SICUREZZA.md` | capability, nonce, race condition, integrità stock |
| `docs/05_INTEGRAZIONE_OXYPROFIT.md` | come il costo arriva a OxyProfit |
| `docs/06_PIANO_TEST.md` | banco di prova e cosa si prova dove |
| `docs/07_PIANO_SPRINT.md` | gli otto sprint e i criteri di uscita |

## Stato

**13/08/2026 — Sprint 1 e 2 chiusi**, verdi in CI e provati su un negozio vero.

| | |
|---|---|
| Sprint 1 | fondamenta, otto tabelle, capability, anagrafica fornitori, audit log |
| Sprint 2 | listini prodotto-fornitore, arrotondamento delle quantità, fornitore preferenziale, pannello sulla scheda prodotto |
| Prove | 51 unit su PHP 8.1→8.4, 35 dentro WordPress 7.0.4, 50 via HTTP, 21 sul pannello prodotto |
| Qualità | PHPCS, PHPStan livello 8 e Plugin Check puliti |

Banco di prova: <https://test.44123.it/oxysuppliers> (WooCommerce 11, HPOS
attivo). Deploy con `scripts\deploy-test-site.ps1`.

Prossimo passo: Sprint 3, la dashboard dei fabbisogni.
