<?php
// Drives the whole path from "what to reorder" to a purchase order, on the
// seeded shop.
//
//   wp eval-file verify-orders.php

if ( ! function_exists( 'wc_get_product' ) ) {
	echo "WooCommerce non caricato\n";
	return;
}

use Oxysoft\OxySuppliers\Domain\PurchaseOrderStatus;
use Oxysoft\OxySuppliers\Domain\Supplier;
use Oxysoft\OxySuppliers\Domain\SupplierProduct;
use Oxysoft\OxySuppliers\Engine\RequirementCalculator;
use Oxysoft\OxySuppliers\Engine\TargetStockStrategy;
use Oxysoft\OxySuppliers\Persistence\CatalogueRepository;
use Oxysoft\OxySuppliers\Persistence\PurchaseOrderRepository;
use Oxysoft\OxySuppliers\Persistence\SupplierProductRepository;
use Oxysoft\OxySuppliers\Persistence\SupplierRepository;
use Oxysoft\OxySuppliers\Service\ProposalBuilder;
use Oxysoft\OxySuppliers\Service\PurchaseOrderNumbers;

$GLOBALS['oxs_passed'] = 0;
$GLOBALS['oxs_failed'] = 0;

/**
 * Counters in $GLOBALS: in wp eval-file a plain variable does not survive into
 * a function.
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

global $wpdb;

// A clean slate for the plugin's own tables: this script is about what the
// plugin does, not about what a previous run left behind.
$wpdb->query( 'DELETE FROM oxs_oxysuppliers_purchase_order_items' );
$wpdb->query( 'DELETE FROM oxs_oxysuppliers_purchase_orders' );
$wpdb->query( 'DELETE FROM oxs_oxysuppliers_supplier_products' );
$wpdb->query( 'DELETE FROM oxs_oxysuppliers_suppliers' );

$suppliers  = new SupplierRepository();
$listings   = new SupplierProductRepository();
$orders     = new PurchaseOrderRepository( new PurchaseOrderNumbers() );
$catalogue  = new CatalogueRepository();
$calculator = new RequirementCalculator( new TargetStockStrategy() );
$proposals  = new ProposalBuilder( $orders, $suppliers );

echo "== due fornitori con i loro listini ==\n";

$abc = $suppliers->insert(
	Supplier::from_fields(
		array(
			'company_name'   => 'ABC Forniture Srl',
			'currency'       => 'EUR',
			'lead_time_days' => '3',
			'payment_terms'  => '30 giorni fine mese',
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

// MOUSE-X and SSD-1TB from ABC, HUB-USB4 from DEF: three shortages, two
// suppliers, and the answer has to be two orders.
$plan = array(
	array( $abc, 'MOUSE-X', '11.80', 10 ),
	array( $abc, 'SSD-1TB', '62.00', 1 ),
	array( $def, 'HUB-USB4', '19.90', 5 ),
);

foreach ( $plan as $row ) {
	list( $supplier_id, $sku, $cost, $multiple ) = $row;

	$listings->save(
		SupplierProduct::from_fields(
			array(
				'supplier_id'    => $supplier_id,
				'product_id'     => wc_get_product_id_by_sku( $sku ),
				'variation_id'   => 0,
				'supplier_sku'   => 'F-' . $sku,
				'currency'       => 'EUR',
				'unit_cost'      => $cost,
				'order_multiple' => (string) $multiple,
				'lead_time_days' => '4',
			)
		)
	);
}

oxs_check( 'listini creati', 3 === count( $listings->for_supplier( $abc ) ) + count( $listings->for_supplier( $def ) ) );

echo "\n== dalla schermata dei fabbisogni agli ordini ==\n";

$articles = array();

foreach ( array( 'MOUSE-X', 'SSD-1TB', 'HUB-USB4' ) as $sku ) {
	$articles[] = array( wc_get_product_id_by_sku( $sku ), 0 );
}

$rows = $calculator->calculate_all( $catalogue->paginate( array( 'articles' => $articles, 'per_page' => 50 ) ) );

oxs_check( 'i tre articoli sono nella schermata', 3 === count( $rows ), 'righe: ' . count( $rows ) );

$made = $proposals->build( $rows, gmdate( 'Y-m-d' ) );

oxs_check( 'tre articoli, due fornitori, DUE ordini', 2 === count( $made ), 'ordini: ' . count( $made ) );

$by_supplier = array();

foreach ( $made as $order ) {
	$by_supplier[ $order->supplier_id ] = $order;
}

oxs_check( 'due righe sull\'ordine di ABC', isset( $by_supplier[ $abc ] ) && 2 === count( $by_supplier[ $abc ]->lines ) );
oxs_check( 'una sull\'ordine di DEF', isset( $by_supplier[ $def ] ) && 1 === count( $by_supplier[ $def ]->lines ) );
oxs_check( 'sono tutti bozze', PurchaseOrderStatus::DRAFT === $made[0]->status && PurchaseOrderStatus::DRAFT === $made[1]->status );
oxs_check( 'con numeri diversi', $made[0]->number !== $made[1]->number, $made[0]->number . ' / ' . $made[1]->number );
oxs_check( 'e le condizioni di pagamento del fornitore', '30 giorni fine mese' === $by_supplier[ $abc ]->payment_terms );

// MOUSE-X is oversold and sells in tens: whatever the shortage is, the order
// has to be a multiple of ten.
$mouse_line = null;

foreach ( $by_supplier[ $abc ]->lines as $line ) {
	if ( 'MOUSE-X' === $line->sku ) {
		$mouse_line = $line;
	}
}

oxs_check( 'la riga di MOUSE-X c\'e\'', null !== $mouse_line );

if ( null !== $mouse_line ) {
	oxs_check( 'la quantita\' e\' un multiplo di dieci', 0 === $mouse_line->qty_ordered % 10, (string) $mouse_line->qty_ordered );
	oxs_check( 'col codice del fornitore', 'F-MOUSE-X' === $mouse_line->supplier_sku );
	oxs_check( 'e il costo del listino', 1180 === $mouse_line->unit_cost->minor );
}

echo "\n== la merce in arrivo non fa riordinare due volte ==\n";

$before = null;
$after  = null;

foreach ( $rows as $row ) {
	if ( 'MOUSE-X' === $row->context->sku ) {
		$before = $row;
	}
}

// The order is still a draft, and a draft is a thought: it must not count yet.
$rows_now = $calculator->calculate_all( $catalogue->paginate( array( 'articles' => $articles, 'per_page' => 50 ) ) );

foreach ( $rows_now as $row ) {
	if ( 'MOUSE-X' === $row->context->sku ) {
		$after = $row;
	}
}

oxs_check(
	'una bozza non conta come merce in arrivo',
	null !== $after && 0 === $after->context->incoming,
	null === $after ? 'riga mancante' : (string) $after->context->incoming
);

oxs_check(
	'quindi il fabbisogno non cambia',
	null !== $before && null !== $after && $before->needed === $after->needed
);

// Send it, and now it counts.
$orders->mark_sent( $by_supplier[ $abc ] );

$rows_sent = $calculator->calculate_all( $catalogue->paginate( array( 'articles' => $articles, 'per_page' => 50 ) ) );
$sent_row  = null;

foreach ( $rows_sent as $row ) {
	if ( 'MOUSE-X' === $row->context->sku ) {
		$sent_row = $row;
	}
}

oxs_check(
	'un ordine inviato invece si',
	null !== $sent_row && $sent_row->context->incoming > 0,
	null === $sent_row ? 'riga mancante' : (string) $sent_row->context->incoming
);

oxs_check(
	'e il fabbisogno scende di quello che sta arrivando',
	null !== $sent_row && null !== $before && $sent_row->needed < $before->needed,
	null === $sent_row ? '' : "prima={$before->needed} dopo={$sent_row->needed}"
);

oxs_check(
	'fino a non dover ordinare piu\' niente',
	null !== $sent_row && 0 === $sent_row->suggested,
	null === $sent_row ? '' : (string) $sent_row->suggested
);

echo "\n== la macchina a stati ==\n";

$sent_order = $orders->find( $by_supplier[ $abc ]->id );

oxs_check( 'l\'ordine risulta inviato', PurchaseOrderStatus::SENT === $sent_order->status );
oxs_check( 'e non e\' piu\' modificabile', ! $sent_order->status->is_editable() );

$draft_order = $orders->find( $by_supplier[ $def ]->id );
$orders->set_status( $draft_order, PurchaseOrderStatus::CANCELLED );
$cancelled = $orders->find( $draft_order->id );

oxs_check( 'un ordine annullato resta annullato', PurchaseOrderStatus::CANCELLED === $cancelled->status );
oxs_check( 'e non ha piu\' nessuna mossa possibile', array() === $cancelled->status->allowed_next() );

$refused = false;

try {
	$orders->set_status( $cancelled, PurchaseOrderStatus::SENT );
} catch ( \Oxysoft\OxySuppliers\Domain\InvalidTransition $stopped ) {
	$refused = true;
}

oxs_check( 'rimandarlo a "inviato" viene rifiutato dal dominio', $refused );

$still = $orders->find( $cancelled->id );
oxs_check( 'e il database non e\' stato toccato', PurchaseOrderStatus::CANCELLED === $still->status );

// A cancelled order is not coming, so it must stop counting as incoming.
$rows_after = $calculator->calculate_all( $catalogue->paginate( array( 'articles' => $articles, 'per_page' => 50 ) ) );
$hub        = null;

foreach ( $rows_after as $row ) {
	if ( 'HUB-USB4' === $row->context->sku ) {
		$hub = $row;
	}
}

oxs_check( 'un ordine annullato smette di contare come in arrivo', null !== $hub && 0 === $hub->context->incoming );

echo "\n== il numero e' unico anche di corsa ==\n";

$numbers  = new PurchaseOrderNumbers();
$proposed = $numbers->next();

$first  = $orders->create( \Oxysoft\OxySuppliers\Domain\PurchaseOrder::draft_for( $abc, 'EUR', gmdate( 'Y-m-d' ) )->with_number( $proposed ) );
$second = $orders->create( \Oxysoft\OxySuppliers\Domain\PurchaseOrder::draft_for( $abc, 'EUR', gmdate( 'Y-m-d' ) )->with_number( $proposed ) );

oxs_check( 'due salvataggi con lo stesso numero proposto', null !== $first && null !== $second );
oxs_check( 'producono due numeri diversi', null !== $first && null !== $second && $first->number !== $second->number, null === $second ? 'il secondo non e\' stato salvato' : $first->number . ' / ' . $second->number );

echo "\n== ===============================\n";
printf( "== superati: %d   falliti: %d\n", $GLOBALS['oxs_passed'], $GLOBALS['oxs_failed'] );
