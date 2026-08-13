<?php
// Leaves one sent order for MOUSE-X on the bench, so a screen has something in
// the way to show. Prints "<expected date> <quantity now on the way>", which is
// what the caller checks against the screen.
//
// It prints the quantity rather than assuming it: other orders for the same
// article may already be open, and a test that expects its own number and not
// the shop's is testing itself.
//
//   wp eval-file bench-open-order.php

use Oxysoft\OxySuppliers\Domain\Money;
use Oxysoft\OxySuppliers\Domain\PurchaseOrder;
use Oxysoft\OxySuppliers\Domain\PurchaseOrderLine;
use Oxysoft\OxySuppliers\Domain\PurchaseOrderStatus;
use Oxysoft\OxySuppliers\Persistence\PurchaseOrderRepository;
use Oxysoft\OxySuppliers\Service\PurchaseOrderNumbers;

if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
	echo "no-woocommerce\n";
	return;
}

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admin ? $admin[0]->ID : 1 );

$mouse = wc_get_product_id_by_sku( 'MOUSE-X' );

// Earlier than anything else the bench creates, so it is the one the screen
// shows as the date to expect.
$eta = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );

$orders = new PurchaseOrderRepository( new PurchaseOrderNumbers() );

$order = $orders->create(
	( new PurchaseOrder( 0, '', 1, PurchaseOrderStatus::DRAFT, 'EUR', gmdate( 'Y-m-d' ), $eta ) )->with_lines(
		array(
			new PurchaseOrderLine( 0, $mouse, 0, 'MOUSE-X', 'F-MX', 'Mouse X wireless', 20, 0, Money::from_minor( 1180, 'EUR' ) ),
		)
	)
);

$orders->mark_sent( $order );

$incoming = $orders->incoming_for_item( $mouse );

echo $eta . ' ' . (int) $incoming['quantity'] . "\n";
