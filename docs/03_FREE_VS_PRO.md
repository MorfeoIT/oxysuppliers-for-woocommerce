# FREE e PRO

Due plugin, due repository, due pacchetti. Non un flag.

| | FREE | PRO |
|---|---|---|
| Slug | `oxysuppliers-for-woocommerce` | `oxysuppliers-for-woocommerce-pro` |
| Repo | MorfeoIT, privato oggi, **pubblico al rilascio** | MorfeoIT, **privato sempre** |
| Distribuzione | WordPress.org | appstore3000 |
| Namespace | `Oxysoft\OxySuppliers` | `Oxysoft\OxySuppliersPro` |

## Il confine

**FREE** (§20 della specifica): anagrafica fornitori, associazione
prodotto-fornitore con costo/MOQ/multiplo/lead time, fornitore preferenziale,
scorta minima, dashboard fabbisogni con la formula `obiettivo − disponibile`,
creazione manuale del PO, creazione del PO dai fabbisogni, PDF, invio email
manuale, ricezione totale e parziale, aggiornamento stock, storico PO, HPOS,
i quattro report base.

**Il FREE deve essere pienamente utilizzabile.** Un negozio piccolo con un
fornitore ci lavora tutti i giorni e non gli manca niente.

**PRO**: suggerimento basato su vendite e lead time, generazione automatica di
bozze, costo medio ponderato, storico costi con report, confronto fornitori,
workflow approvativo, notifiche e reminder, report avanzati, allegati,
import/export avanzato, API/webhook, referenti e indirizzi multipli.

## Prezzo e licenza (uguali per tutta la famiglia)

| | |
|---|---|
| Licenza Pro | **32,70 €** + IVA 22% (`prezzo_cent` 3270), perpetua, **1 postazione** |
| Rinnovo aggiornamenti | **16,35 €** + IVA 22% (`prezzo_cent` 1635), 12 mesi |
| Alla scadenza | il plugin **continua a funzionare**, smettono solo gli aggiornamenti |

Sul negozio il rinnovo va marcato `nonIniziale`. E la riga del prodotto non è
solo il prezzo: servono anche `prefisso_licenza`, `attivazioni_max` e
`mesi_aggiornamenti`. Si controllano **confrontando la riga con quella del
prodotto di riferimento, colonna per colonna** — il seed di OxyProfit Pro aveva
il prezzo giusto e il contratto vuoto, e da fuori sembrava a posto.

Il livello licenze si **copia** dalla libreria di famiglia con namespace e
prefissi propri (`oxysuppliers_license_*`). Non si dipende da un altro Pro: chi
compra solo questo non deve ritrovarsi legato a un prodotto che non ha comprato.
E due Pro che copiano la stessa libreria senza prefisso si sovrascrivono le
opzioni a vicenda.

## Come si scrive il PRO — quattro regole, non consigli

Nascono da difetti veri, costati un sito giù e una promessa tradita su OxyProfit.

**1. Il Pro non contiene formule, contiene un provider.** Tutto il calcolo sta
nel gratuito, scritto e provato una volta sola. Due copie di un calcolo sono due
calcoli, e il giorno che non vanno d'accordo nessuno sa quale ha ragione.

**2. Il Pro non deve mai spegnere il gratuito.** Il filtro
`oxysuppliers_requirement_strategy` tiene **un** provider: quello del Pro
*sostituisce* quello libero. Se il Pro rispondesse solo per le funzioni a
pagamento, installarlo senza licenza spegnerebbe anche il calcolo gratuito.
`grants()` concede **sempre** le funzioni free, poi le altre solo con licenza
valida.

**3. La classe che implementa un'interfaccia del gratuito sta in un file a
parte**, incluso solo dopo:

```php
if ( interface_exists( 'Oxysoft\\OxySuppliers\\Engine\\RequirementStrategy' ) ) {
    require_once __DIR__ . '/Strategy/DemandForecastStrategy.php';
}
```

PHP risolve l'interfaccia quando **carica** il file, non quando la classe serve:
un controllo dentro `boot()` arriva dopo il danno, ed è errore fatale.
`Requires Plugins` governa l'**attivazione**, non l'ordine degli include — e
alfabeticamente `…-pro` viene prima di `…-woocommerce`.

**4. `interface_exists()` col nome per esteso, come stringa.** Senza lo `use`,
`RequirementStrategy::class` si risolverebbe nel namespace del Pro, cioè in
niente, e il controllo risponderebbe sempre di no.

## Nessun calcolo dietro la licenza

Si verifica, non si dichiara: impronta `sha1` delle righe della dashboard
fabbisogni **prima e dopo** aver attivato e staccato la licenza. Deve essere
identica.

**Una prova su dati vuoti non è una prova.** Un'impronta `0:` confrontata con
un'altra `0:` passa sempre. Prima si semina un negozio piccolo ma vero
(prodotti, fornitori, ordini su più mesi, giacenze diverse), poi si confronta.

## Sportelli fuori dalla basic auth

`api/licenze.php` e `api/scarica.php` sul negozio non li chiama un browser con
le credenziali di staging: li chiama il plugin sul sito di un cliente. Dietro
l'autenticazione rispondono 401 e nessuna licenza si attiva da nessuna parte.
Router Traefik dedicato senza middleware, solo per quei due.
