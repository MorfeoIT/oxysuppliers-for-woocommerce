<?php
// Receives goods against real WooCommerce products, on the seeded shop.
//
//   wp eval-file verify-receipts.php
//
// This is the half the CI cannot answer: there is no WooCommerce there, so
// nothing moves any stock. Here it does.

if ( ! function_exists( 'wc_get_product' ) ) {
	echo "WooCommerce non caricato\n";
	return;
}

use Oxysoft\OxySuppliers\Domain\Money;
use Oxysoft\OxySuppliers\Domain\PurchaseOrder;
use Oxysoft\OxySuppliers\Domain\PurchaseOrderLine;
use Oxysoft\OxySuppliers\Domain\PurchaseOrderStatus;
use Oxysoft\OxySuppliers\Persistence\PurchaseOrderRepository;
use Oxysoft\OxySuppliers\Persistence\ReceiptRepository;
use Oxysoft\OxySuppliers\Service\AuditLogger;
use Oxysoft\OxySuppliers\Service\GoodsReceiver;
use Oxysoft\OxySuppliers\Service\PurchaseOrderNumbers;
use Oxysoft\OxySuppliers\Service\ReceiptOutcome;

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

/**
 * The stock WooCommerce holds for an article, read fresh.
 */
function oxs_stock( $sku ) {
	$id = wc_get_product_id_by_sku( $sku );

	if ( ! $id ) {
		return null;
	}

	wc_delete_product_transients( $id );

	$product = wc_get_product( $id );

	return $product instanceof WC_Product ? (int) $product->get_stock_quantity() : null;
}

global $wpdb;

$wpdb->query( 'DELETE FROM oxs_oxysuppliers_receipt_items' );
$wpdb->query( 'DELETE FROM oxs_oxysuppliers_receipts' );
$wpdb->query( 'DELETE FROM oxs_oxysuppliers_purchase_order_items' );
$wpdb->query( 'DELETE FROM oxs_oxysuppliers_purchase_orders' );

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admin ? $admin[0]->ID : 1 );

$orders   = new PurchaseOrderRepository( new PurchaseOrderNumbers() );
$receipts = new ReceiptRepository();
$audit    = new AuditLogger();
$receiver = new GoodsReceiver( $receipts, $orders, $audit );

$mouse   = wc_get_product_id_by_sku( 'MOUSE-X' );
$service = wc_get_product_id_by_sku( 'SERV-SETUP' );

echo "== un ordine per due articoli, uno dei quali senza gestione stock ==\n";

$before_mouse = oxs_stock( 'MOUSE-X' );

echo "  giacenza di MOUSE-X prima: {$before_mouse}\n";

$order = $orders->create(
	PurchaseOrder::draft_for( 1, 'EUR', gmdate( 'Y-m-d' ) )->with_lines(
		array(
			new PurchaseOrderLine( 0, $mouse, 0, 'MOUSE-X', 'F-MX', 'Mouse X wireless', 30, 0, Money::from_minor( 1180, 'EUR' ) ),
			new PurchaseOrderLine( 0, $service, 0, 'SERV-SETUP', 'F-SRV', 'Configurazione', 2, 0, Money::from_minor( 6000, 'EUR' ) ),
		)
	)
);

$orders->mark_sent( $order );
$order = $orders->find( $order->id );

oxs_check( 'ordine creato e inviato', PurchaseOrderStatus::SENT === $order->status );

echo "\n== arriva meta' della merce ==\n";

$key = wp_generate_uuid4();

$first = $receiver->receive(
	$order,
	array(
		$order->lines[0]->id => 10,
		$order->lines[1]->id => 2,
	),
	array( $order->lines[0]->id => '11,50' ),
	$key,
	'DDT-2026-001'
);

oxs_check( 'la ricezione va a buon fine', ReceiptOutcome::RECORDED === $first->status, $first->detail );

$after_mouse = oxs_stock( 'MOUSE-X' );

oxs_check(
	'la giacenza e\' salita esattamente di dieci',
	$after_mouse === $before_mouse + 10,
	"prima={$before_mouse} dopo={$after_mouse}"
);

$service_product = wc_get_product( $service );

oxs_check( 'il servizio non ha gestione stock', ! $service_product->managing_stock() );
oxs_check( 'e infatti non ha giacenza', null === $service_product->get_stock_quantity() );

$lines_with_reason = 0;

foreach ( $first->receipt->lines as $line ) {
	if ( $line->product_id === $service ) {
		++$lines_with_reason;
	}
}

oxs_check( 'la riga del servizio e\' comunque registrata', 1 === $lines_with_reason );

$reason = $wpdb->get_var(
	$wpdb->prepare(
		'SELECT stock_skipped_reason FROM oxs_oxysuppliers_receipt_items WHERE receipt_id = %d AND product_id = %d',
		$first->receipt->id,
		$service
	)
);

oxs_check( 'col motivo per cui lo stock non e\' stato toccato', 'not_tracked' === $reason, (string) $reason );

$before_after = $wpdb->get_row(
	$wpdb->prepare(
		'SELECT stock_before, stock_after FROM oxs_oxysuppliers_receipt_items WHERE receipt_id = %d AND product_id = %d',
		$first->receipt->id,
		$mouse
	),
	ARRAY_A
);

oxs_check(
	'e per il mouse c\'e\' scritto prima e dopo',
	null !== $before_after && (int) $before_after['stock_before'] === $before_mouse && (int) $before_after['stock_after'] === $after_mouse,
	null === $before_after ? 'riga mancante' : $before_after['stock_before'] . ' -> ' . $before_after['stock_after']
);

oxs_check( 'il costo davvero pagato e\' stato salvato', 1150 === $first->receipt->lines[0]->actual_cost->minor );

echo "\n== IL PUNTO: la stessa richiesta due volte ==\n";

$stock_before_second = oxs_stock( 'MOUSE-X' );

$second = $receiver->receive(
	$order,
	array(
		$order->lines[0]->id => 10,
		$order->lines[1]->id => 2,
	),
	array(),
	$key,
	'DDT-2026-001'
);

oxs_check( 'la seconda volta non registra niente di nuovo', ReceiptOutcome::ALREADY_RECORDED === $second->status, $second->status );
oxs_check( 'e non e\' un errore: indica la ricezione che c\'e\' gia\'', $second->is_recorded() && $second->receipt->id === $first->receipt->id );
oxs_check(
	'LA GIACENZA NON SI E\' MOSSA',
	oxs_stock( 'MOUSE-X' ) === $stock_before_second,
	'prima=' . $stock_before_second . ' dopo=' . oxs_stock( 'MOUSE-X' )
);
oxs_check( 'e c\'e\' una sola ricezione', 1 === count( $receipts->for_order( $order->id ) ) );

echo "\n== piu' di quello che manca ==\n";

$order_now = $orders->find( $order->id );
$stock_now = oxs_stock( 'MOUSE-X' );

$too_many = $receiver->receive(
	$order_now,
	array( $order_now->lines[0]->id => 25 ),
	array(),
	wp_generate_uuid4()
);

oxs_check( 'viene rifiutata', ReceiptOutcome::TOO_MANY === $too_many->status );
oxs_check( 'e la giacenza non si e\' mossa', oxs_stock( 'MOUSE-X' ) === $stock_now );

echo "\n== l'annullamento rimette la merce a posto ==\n";

$before_reversal = oxs_stock( 'MOUSE-X' );

$fix = $receiver->reverse( $first->receipt, wp_generate_uuid4() );

oxs_check( 'la correzione viene registrata', ReceiptOutcome::RECORDED === $fix->status, $fix->detail );
oxs_check(
	'la giacenza torna indietro di dieci',
	oxs_stock( 'MOUSE-X' ) === $before_reversal - 10,
	'prima=' . $before_reversal . ' dopo=' . oxs_stock( 'MOUSE-X' )
);
oxs_check( 'la ricezione sbagliata e\' ancora li\'', null !== $receipts->find( $first->receipt->id ) );
oxs_check( 'e ce ne sono due, non zero', 2 === count( $receipts->for_order( $order->id ) ) );

$final = $orders->find( $order->id );

oxs_check( 'l\'ordine e\' tornato a non aver ricevuto niente', 0 === $final->lines[0]->qty_received );

echo "\n== e il registro racconta tutto ==\n";

$moves = $audit->count( AuditLogger::OBJECT_STOCK, (string) $mouse, AuditLogger::ACTION_STOCK_MOVE );

oxs_check( 'due movimenti di stock: quello in entrata e quello indietro', $moves >= 2, (string) $moves );

$history = $audit->history( AuditLogger::OBJECT_STOCK, (string) $mouse, 5 );

if ( $history ) {
	echo '  ultimo movimento: ' . $history[0]['message'] . "\n";
}

echo "\n== ===============================\n";
printf( "== superati: %d   falliti: %d\n", $GLOBALS['oxs_passed'], $GLOBALS['oxs_failed'] );
