<?php
// Drives the reordering screen against the seeded shop on the test bench.
//
//   wp eval-file verify-requirements.php
//
// The interesting half of this file is the query count. A screen that is right
// and takes forty queries a page is a screen that will be turned off by the
// first shop with a real catalogue, and nothing but a real database says so.

if ( ! function_exists( 'wc_get_product' ) ) {
	echo "WooCommerce non caricato\n";
	return;
}

use Oxysoft\OxySuppliers\Domain\RequirementStatus;
use Oxysoft\OxySuppliers\Domain\SupplierProduct;
use Oxysoft\OxySuppliers\Domain\Supplier;
use Oxysoft\OxySuppliers\Engine\RequirementCalculator;
use Oxysoft\OxySuppliers\Engine\TargetStockStrategy;
use Oxysoft\OxySuppliers\Persistence\CatalogueRepository;
use Oxysoft\OxySuppliers\Persistence\SupplierProductRepository;
use Oxysoft\OxySuppliers\Persistence\SupplierRepository;

$GLOBALS['oxs_passed'] = 0;
$GLOBALS['oxs_failed'] = 0;

/**
 * Counters in $GLOBALS: in wp eval-file a plain variable does not survive into
 * a function, and "0 passed, 0 failed" is not a result.
 */
function oxs_check( $label, $condition, $detail = '' ) {
	if ( $condition ) {
		++$GLOBALS['oxs_passed'];
		echo "  ok   {$label}\n";

		return;
	}

	++$GLOBALS['oxs_failed'];
	echo "  FAIL {$label}" . ( '' === $detail ? '' : " ({$detail})" ) . "\n";
}

$catalogue  = new CatalogueRepository();
$calculator = new RequirementCalculator( new TargetStockStrategy() );
$lines      = new SupplierProductRepository();
$suppliers  = new SupplierRepository();

echo "== le vendite sono attendibili? ==\n";

$stale_before = $catalogue->sales_data_is_stale();
echo '  stato iniziale: ' . ( $stale_before ? "da importare\n" : "gia' importate\n" );

if ( $stale_before ) {
	oxs_check( 'il plugin se ne accorge e non finge zero', true );
}

echo "\n== una pagina di fabbisogni ==\n";

$rows = $calculator->calculate_all( $catalogue->paginate( array( 'per_page' => 50 ) ) );
oxs_check( 'la pagina non e\' vuota', count( $rows ) > 0, 'righe: ' . count( $rows ) );

$by_sku = array();

foreach ( $rows as $row ) {
	$by_sku[ $row->context->sku ] = $row;
}

// The seeded shop: MOUSE-X was oversold down to a negative number and has a
// low stock threshold of 10, so the target is 20 and the shortage is what is
// missing from there.
if ( isset( $by_sku['MOUSE-X'] ) ) {
	$mouse = $by_sku['MOUSE-X'];

	oxs_check( 'la giacenza e\' quella vera', $mouse->context->stock < 0, (string) $mouse->context->stock );
	oxs_check( 'la scorta minima viene da WooCommerce', 10 === $mouse->context->reorder_point, (string) $mouse->context->reorder_point );
	oxs_check( 'l\'obiettivo e\' il doppio', 20 === $mouse->context->target, (string) $mouse->context->target );
	oxs_check(
		'il fabbisogno e\' obiettivo meno disponibile',
		$mouse->needed === 20 - $mouse->context->available(),
		"needed={$mouse->needed} available={$mouse->context->available()}"
	);
	oxs_check( 'ed e\' segnalato come esaurito o senza fornitore', $mouse->status->needs_attention(), $mouse->status->value );
} else {
	oxs_check( 'MOUSE-X e\' nella pagina', false );
}

// The service has stock management off and must not be on this screen at all:
// there is no number for it to be short of.
oxs_check( 'un articolo senza gestione stock non compare', ! isset( $by_sku['SERV-SETUP'] ) );

// The parent of a variable product is not what gets bought.
oxs_check( 'il padre di un prodotto variabile non compare', ! isset( $by_sku['TSHIRT'] ) );
oxs_check( 'le sue variazioni si', isset( $by_sku['TSHIRT-S'] ) && isset( $by_sku['TSHIRT-L'] ) );

echo "\n== i filtri che mostrano quello che manca ==\n";

$without_supplier = $catalogue->count( array( 'without_supplier' => true ) );
$everything       = $catalogue->count();

oxs_check(
	'senza fornitore ne trova, e sono meno di tutti',
	$without_supplier > 0 && $without_supplier <= $everything,
	"senza={$without_supplier} tutti={$everything}"
);

echo "\n== con un fornitore vero ==\n";

$mouse_id = wc_get_product_id_by_sku( 'MOUSE-X' );

$supplier_id = $suppliers->insert(
	Supplier::from_fields(
		array(
			'company_name'   => 'Fornitore Fabbisogni',
			'currency'       => 'EUR',
			'lead_time_days' => '4',
		)
	)
);

$lines->save(
	SupplierProduct::from_fields(
		array(
			'supplier_id'    => $supplier_id,
			'product_id'     => $mouse_id,
			'variation_id'   => 0,
			'currency'       => 'EUR',
			'unit_cost'      => '11.80',
			'order_multiple' => '10',
			'lead_time_days' => '4',
		)
	)
);

$rows  = $calculator->calculate_all( $catalogue->paginate( array( 'per_page' => 50 ) ) );
$mouse = null;

foreach ( $rows as $row ) {
	if ( 'MOUSE-X' === $row->context->sku ) {
		$mouse = $row;
	}
}

if ( null !== $mouse ) {
	oxs_check( 'il fornitore compare sulla riga', null !== $mouse->context->supplier );
	oxs_check( 'la quantita\' e\' arrotondata al multiplo di dieci', 0 === $mouse->suggested % 10, (string) $mouse->suggested );
	oxs_check( 'e copre il fabbisogno', $mouse->suggested >= $mouse->needed, "sugg={$mouse->suggested} needed={$mouse->needed}" );
	oxs_check( 'il valore dell\'ordine e\' calcolato', null !== $mouse->value, null === $mouse->value ? 'nullo' : $mouse->value->to_decimal() );
	oxs_check( 'e non e\' piu\' senza fornitore', RequirementStatus::NO_SUPPLIER !== $mouse->status, $mouse->status->value );
}

echo "\n== il criterio d'uscita: le query non crescono con le righe ==\n";

global $wpdb;

$before = $wpdb->num_queries;
$calculator->calculate_all( $catalogue->paginate( array( 'per_page' => 5 ) ) );
$small = $wpdb->num_queries - $before;

$before = $wpdb->num_queries;
$calculator->calculate_all( $catalogue->paginate( array( 'per_page' => 200 ) ) );
$large = $wpdb->num_queries - $before;

echo "  query per 5 righe: {$small}\n";
echo "  query per 200 righe: {$large}\n";

oxs_check( 'stesso numero di query', $small === $large, "5 righe={$small}, 200 righe={$large}" );
oxs_check( 'e sono poche', $large <= 12, (string) $large );

echo "\n== ===============================\n";
printf( "== superati: %d   falliti: %d\n", $GLOBALS['oxs_passed'], $GLOBALS['oxs_failed'] );
