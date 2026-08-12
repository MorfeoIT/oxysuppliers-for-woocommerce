# Integrazione con OxyProfit

> La specifica (§14) parla di «Oxy Margin». Quel prodotto non esiste con quel
> nome: si chiama **OxyProfit – Real Profit for WooCommerce**, slug
> `oxyprofit-for-woocommerce`, namespace `Oxysoft\OxyProfit`. «Margin Lens» era
> il nome vecchio, abbandonato perché già preso.

Il flusso desiderato è:

**Oxy Suppliers → nuovo costo alla ricezione → OxyProfit → margine aggiornato**

## L'attacco esiste già

OxyProfit non va modificato: espone il punto giusto in `src/Plugin.php`.

```php
apply_filters( 'oxyprofit_cost_sources', $sources ); // list<Engine\CostSource>
```

`CostSource` è un'interfaccia di due metodi:

```php
public function unit_cost( CostQuery $query ): ?Money;
public function name(): string;
```

e `CostQuery` porta `product_id`, `variation_id`, `type`, `date`, `currency`,
`category_id`, più valuta base e cambio scalato. Le sorgenti si consultano **in
ordine di priorità** e OxyProfit scarta con `instanceof` tutto ciò che non è una
`CostSource`, quindi un'integrazione scritta male non può rompergli il motore.

Una regola dell'interfaccia va rispettata alla lettera: **`null` quando non si
sa, mai zero.** Un costo zero è una risposta; l'assenza non lo è, e confonderle
gonfia il profitto.

## Come si aggancia, senza dipendere

Vale la regola 3 di `03_FREE_VS_PRO.md`, qui a maggior ragione perché parliamo
di **un altro prodotto, che il cliente può non avere comprato**.

```php
// src/Plugin.php — la registrazione
add_action( 'plugins_loaded', array( Integrations\OxyProfit::class, 'maybe_register' ), 20 );

// src/Integrations/OxyProfit.php
public static function maybe_register(): void {
    if ( ! interface_exists( 'Oxysoft\\OxyProfit\\Engine\\CostSource' ) ) {
        return;
    }
    require_once __DIR__ . '/OxyProfitCostSource.php'; // file a parte
    add_filter( 'oxyprofit_cost_sources', ... );
}
```

Il file che contiene `class OxyProfitCostSource implements CostSource` **sta in
un file a parte** e si include solo dopo il controllo. PHP risolve
l'interfaccia quando carica il file: un controllo dentro il costruttore arriva
dopo l'errore fatale. E `interface_exists` col **nome per esteso, come
stringa** — senza `use`, `CostSource::class` si risolverebbe nel nostro
namespace, cioè in niente.

Il nostro `Money` non è il loro: la conversione avviene nell'adattatore, ed è
l'unico posto dove i due mondi si toccano.

## La lezione che vale più del codice

Su OxyProfit la chiave di idempotenza di un evento di costo **non contiene
l'importo**, apposta, così un hook che scatta due volte non scrive due volte.
Conseguenza non prevista: quando un costo veniva **corretto**, il nuovo evento
veniva scartato come duplicato, la riga di profitto riscritta, e le due cose
smettevano di concordare in silenzio.

Quindi, quando questo plugin manda un costo:

- **una ricezione nuova** è un evento nuovo;
- **una correzione** (costo fatturato diverso dall'ordinato, ricezione
  annullata, nota di credito) non è un secondo evento appeso: è un **evento
  compensativo più un sostitutivo**, entrambi legati all'originale.

Chi riconcilia sta dalla parte di OxyProfit; il nostro compito è **non
raccontargli una correzione come se fosse un fatto nuovo**.

## Un secondo vincolo, non tecnico

Un ricalcolo è contabilità, una cancellazione è una decisione: **la contabilità
non disfa le decisioni**. Su OxyProfit un ricalcolo riagganciava un cliente
cancellato su richiesta, perché la chiave veniva ricostruita dall'ordine, che
l'indirizzo ce l'ha ancora. Se un giorno questo plugin ricostruisce costi
storici, non deve far riapparire nulla che sia stato rimosso.

## Verso l'esterno

Nella direzione opposta esponiamo i nostri:

- `oxysuppliers_supplier_created`
- `oxysuppliers_purchase_order_created`
- `oxysuppliers_purchase_order_sent`
- `oxysuppliers_receipt_created`
- `oxysuppliers_cost_changed`

e i filtri su algoritmo del fabbisogno, numerazione, PDF, email e aggiornamento
del costo. Sono l'interfaccia stabile su cui si innesta il PRO e, un giorno,
Oxy DDT.
