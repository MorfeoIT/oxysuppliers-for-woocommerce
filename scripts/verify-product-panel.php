<?php
// Drives the Suppliers panel the way the product screen does, on the test
// bench, against real WooCommerce products.
//
//   wp eval-file verify-product-panel.php
//
// Two rules of this file, both learned the hard way: it must begin with <?php
// because wp eval-file prepends a closing tag, and it must not declare
// strict_types, which would then no longer be the first statement.

if ( ! function_exists( 'wc_get_product' ) ) {
	echo "WooCommerce non caricato\n";
	return;
}

use Oxysoft\OxySuppliers\Admin\ProductSupplierPanel;
use Oxysoft\OxySuppliers\Domain\Supplier;
use Oxysoft\OxySuppliers\Domain\SupplierProductValidator;
use Oxysoft\OxySuppliers\Persistence\SupplierProductRepository;
use Oxysoft\OxySuppliers\Persistence\SupplierRepository;
use Oxysoft\OxySuppliers\Service\AuditLogger;

$GLOBALS['oxs_passed'] = 0;
$GLOBALS['oxs_failed'] = 0;

/**
 * Counters live in $GLOBALS: in wp eval-file a plain variable does not survive
 * into a function, and a run that reports "0 passed, 0 failed" is not telling
 * you it went well.
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

$suppliers = new SupplierRepository();
$lines     = new SupplierProductRepository();
$panel     = new ProductSupplierPanel( $lines, $suppliers, new SupplierProductValidator(), new AuditLogger() );

// An administrator, because the panel refuses to write for anybody else.
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admin ? $admin[0]->ID : 1 );

echo "== preparo due fornitori e un prodotto ==\n";

foreach ( $lines->for_item( 0 ) as $ignored ) {
	unset( $ignored );
}

$abc = $suppliers->insert(
	Supplier::from_fields(
		array(
			'company_name'   => 'ABC Forniture Srl',
			'currency'       => 'EUR',
			'lead_time_days' => '3',
		)
	)
);

$def = $suppliers->insert(
	Supplier::from_fields(
		array(
			'company_name'   => 'DEF Spa',
			'currency'       => 'EUR',
			'lead_time_days' => '7',
		)
	)
);

oxs_check( 'due fornitori creati', $abc > 0 && $def > 0, "abc={$abc} def={$def}" );

$product_id = wc_get_product_id_by_sku( 'MOUSE-X' );
oxs_check( 'il prodotto di prova esiste', $product_id > 0 );

if ( 0 === $product_id ) {
	return;
}

// Whatever a previous run left behind.
foreach ( $lines->for_item( $product_id ) as $old ) {
	$lines->delete( $old->id );
}

echo "\n== salvo due listini dallo stesso modulo del prodotto ==\n";

$nonce = wp_create_nonce( 'oxysuppliers_save_product_lines' );

$_POST = array(
	'oxysuppliers_product_nonce' => $nonce,
	'oxysuppliers_lines'         => array(
		'rows' => array(
			'new'  => array(
				'supplier_id'    => (string) $abc,
				'supplier_sku'   => 'MX-123',
				'unit_cost'      => '11,80',
				'min_order_qty'  => '10',
				'order_multiple' => '5',
				'pack_qty'       => '1',
				'lead_time_days' => '3',
			),
			'new2' => array(
				'supplier_id'    => (string) $def,
				'supplier_sku'   => '7719',
				'unit_cost'      => '11,20',
				'min_order_qty'  => '20',
				'order_multiple' => '10',
				'pack_qty'       => '1',
				'lead_time_days' => '7',
			),
		),
	),
);

$panel->save_product( $product_id );

$stored = $lines->for_item( $product_id );
oxs_check( 'due righe salvate', 2 === count( $stored ), 'trovate ' . count( $stored ) );

// The store writes amounts with a comma; the domain only accepts a full stop.
// This is the seam where that gets settled, and it only shows up on a real
// shop with a real locale.
$by_supplier = array();

foreach ( $stored as $line ) {
	$by_supplier[ $line->supplier_id ] = $line;
}

oxs_check(
	'la virgola del negozio e\' diventata un numero',
	isset( $by_supplier[ $abc ] ) && 1180 === $by_supplier[ $abc ]->unit_cost->minor,
	isset( $by_supplier[ $abc ] ) ? (string) $by_supplier[ $abc ]->unit_cost->minor : 'riga mancante'
);

oxs_check(
	'il codice del fornitore e\' il suo, non il nostro',
	isset( $by_supplier[ $abc ] ) && 'MX-123' === $by_supplier[ $abc ]->supplier_sku
);

oxs_check(
	'ognuno con i suoi termini',
	isset( $by_supplier[ $def ] )
		&& 20 === $by_supplier[ $def ]->terms->minimum
		&& 10 === $by_supplier[ $def ]->terms->multiple
);

oxs_check(
	'senza preferito comanda il piu\' economico',
	$lines->preferred_for( $product_id )->supplier_id === $def
);

echo "\n== salvo di nuovo lo stesso fornitore: e' una modifica ==\n";

$_POST['oxysuppliers_lines']['rows'] = array(
	(string) $by_supplier[ $abc ]->id => array(
		'supplier_id'    => (string) $abc,
		'supplier_sku'   => 'MX-123-B',
		'unit_cost'      => '10,50',
		'min_order_qty'  => '10',
		'order_multiple' => '5',
		'pack_qty'       => '1',
		'lead_time_days' => '3',
	),
);

$panel->save_product( $product_id );

$stored = $lines->for_item( $product_id );
oxs_check( 'le righe restano due', 2 === count( $stored ), 'trovate ' . count( $stored ) );

$edited = $lines->find( $by_supplier[ $abc ]->id );
oxs_check( 'il costo e\' cambiato', 1050 === $edited->unit_cost->minor, (string) $edited->unit_cost->minor );
oxs_check( 'e anche il codice', 'MX-123-B' === $edited->supplier_sku );

echo "\n== scelgo il preferito ==\n";

$_POST['oxysuppliers_lines']['preferred'] = (string) $by_supplier[ $abc ]->id;
$panel->save_product( $product_id );

$preferred = $lines->preferred_for( $product_id );
oxs_check( 'il preferito e\' quello scelto', $preferred->supplier_id === $abc );
oxs_check( 'ed e\' davvero marcato', $preferred->is_preferred );

$others = 0;

foreach ( $lines->for_item( $product_id ) as $line ) {
	if ( $line->is_preferred ) {
		++$others;
	}
}

oxs_check( 'ed e\' l\'unico', 1 === $others, "marcati: {$others}" );

echo "\n== una riga senza fornitore non e' un errore, e' una riga vuota ==\n";

$before = count( $lines->for_item( $product_id ) );

$_POST['oxysuppliers_lines'] = array(
	'rows' => array(
		'new' => array(
			'supplier_id' => '0',
			'unit_cost'   => '99,00',
		),
	),
);

$panel->save_product( $product_id );
oxs_check( 'niente di nuovo', count( $lines->for_item( $product_id ) ) === $before );

echo "\n== senza nonce non si scrive niente ==\n";

$_POST = array(
	'oxysuppliers_lines' => array(
		'rows' => array(
			'new' => array(
				'supplier_id' => (string) $abc,
				'unit_cost'   => '1,00',
			),
		),
	),
);

$panel->save_product( $product_id );
$after = $lines->find( $by_supplier[ $abc ]->id );
oxs_check( 'il costo non e\' stato toccato', 1050 === $after->unit_cost->minor, (string) $after->unit_cost->minor );

echo "\n== e nemmeno chi non ha il permesso ==\n";

$editor = get_users( array( 'role' => 'editor', 'number' => 1 ) );

if ( $editor ) {
	wp_set_current_user( $editor[0]->ID );

	$_POST = array(
		'oxysuppliers_product_nonce' => wp_create_nonce( 'oxysuppliers_save_product_lines' ),
		'oxysuppliers_lines'         => array(
			'rows' => array(
				'new' => array(
					'supplier_id' => (string) $abc,
					'unit_cost'   => '1,00',
				),
			),
		),
	);

	$panel->save_product( $product_id );
	$after = $lines->find( $by_supplier[ $abc ]->id );
	oxs_check( 'un redattore non cambia i costi', 1050 === $after->unit_cost->minor );

	wp_set_current_user( $admin ? $admin[0]->ID : 1 );
}

echo "\n== le variazioni sono articoli a se' ==\n";

$variable_id = wc_get_product_id_by_sku( 'TSHIRT' );
$variable    = $variable_id ? wc_get_product( $variable_id ) : null;
$children    = $variable ? $variable->get_children() : array();

if ( count( $children ) >= 2 ) {
	foreach ( $children as $child ) {
		foreach ( $lines->for_item( $variable_id, (int) $child ) as $old ) {
			$lines->delete( $old->id );
		}
	}

	$_POST = array(
		'oxysuppliers_product_nonce'   => wp_create_nonce( 'oxysuppliers_save_product_lines' ),
		'oxysuppliers_variation_lines' => array(
			0 => array(
				'rows' => array(
					'new' => array(
						'supplier_id' => (string) $abc,
						'unit_cost'   => '4,50',
						'pack_qty'    => '6',
					),
				),
			),
		),
	);

	$panel->save_variation( (int) $children[0], 0 );

	$on_variation = $lines->for_item( $variable_id, (int) $children[0] );
	$on_parent    = $lines->for_item( $variable_id, 0 );
	$on_sibling   = $lines->for_item( $variable_id, (int) $children[1] );

	oxs_check( 'la riga finisce sulla variazione', 1 === count( $on_variation ) );
	oxs_check( 'e non sul prodotto padre', 0 === count( $on_parent ) );
	oxs_check( 'e nemmeno sulla sorella', 0 === count( $on_sibling ) );

	if ( $on_variation ) {
		oxs_check( 'con la confezione da sei', 6 === $on_variation[0]->terms->pack );
		oxs_check( 'che arrotonda 15 a 18', 18 === $on_variation[0]->terms->round_up( 15 ) );
	}
} else {
	echo "  (nessun prodotto variabile da provare)\n";
}

echo "\n== ===============================\n";
printf( "== superati: %d   falliti: %d\n", $GLOBALS['oxs_passed'], $GLOBALS['oxs_failed'] );
