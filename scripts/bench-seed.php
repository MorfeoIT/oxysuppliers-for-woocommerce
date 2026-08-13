<?php
// Puts a plausible buying setup on the seeded shop: three suppliers, a price
// list, an order out for delivery, one part-delivered, and one that is late.
//
//   wp eval-file bench-seed.php
//
// Written for the screenshots, and useful whenever the bench has been emptied.
// It is deliberately not random: the same shop every time means a screenshot
// taken today can be compared with one taken in six months.

use Oxysoft\OxySuppliers\Domain\Money;
use Oxysoft\OxySuppliers\Domain\OrderTerms;
use Oxysoft\OxySuppliers\Domain\PurchaseOrder;
use Oxysoft\OxySuppliers\Domain\PurchaseOrderLine;
use Oxysoft\OxySuppliers\Domain\PurchaseOrderStatus;
use Oxysoft\OxySuppliers\Domain\Supplier;
use Oxysoft\OxySuppliers\Domain\SupplierProduct;
use Oxysoft\OxySuppliers\Persistence\PurchaseOrderRepository;
use Oxysoft\OxySuppliers\Persistence\ReceiptRepository;
use Oxysoft\OxySuppliers\Persistence\SupplierProductRepository;
use Oxysoft\OxySuppliers\Persistence\SupplierRepository;
use Oxysoft\OxySuppliers\Service\AuditLogger;
use Oxysoft\OxySuppliers\Service\GoodsReceiver;
use Oxysoft\OxySuppliers\Persistence\CostHistoryRepository;
use Oxysoft\OxySuppliers\Service\PurchaseOrderNumbers;

if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
	echo "WooCommerce non caricato\n";
	return;
}

global $wpdb;

foreach ( array( 'receipt_items', 'receipts', 'purchase_order_items', 'purchase_orders', 'supplier_products', 'suppliers', 'cost_history' ) as $table ) {
	$wpdb->query( "DELETE FROM oxs_oxysuppliers_{$table}" );
}

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admin ? $admin[0]->ID : 1 );

$suppliers = new SupplierRepository();
$listings  = new SupplierProductRepository();
$orders    = new PurchaseOrderRepository( new PurchaseOrderNumbers() );
$receiver  = new GoodsReceiver( new ReceiptRepository(), $orders, new AuditLogger(), new CostHistoryRepository() );

echo "articoli nel negozio:\n";

foreach ( wc_get_products( array( 'limit' => 50 ) ) as $product ) {
	echo '  ' . str_pad( (string) $product->get_sku(), 14 ) . $product->get_name() . "\n";
}

$made = array();

$people = array(
	array(
		'company_name'    => 'Informatica Bertoldi S.r.l.',
		'vat_number'      => 'IT02938471203',
		'address'         => 'Via dell Artigianato 14',
		'postcode'        => '36100',
		'city'            => 'Vicenza',
		'state'           => 'VI',
		'country'         => 'IT',
		'order_email'     => 'ordini@bertoldi.example',
		'phone'           => '0444 555 120',
		'contact_name'    => 'Elena Bertoldi',
		'payment_terms'   => '30 giorni fine mese',
		'lead_time_days'  => 5,
		'min_order_value' => '250',
	),
	array(
		'company_name'    => 'Nord Componenti S.p.A.',
		'vat_number'      => 'IT01827364501',
		'address'         => 'Strada Statale 11, km 4',
		'postcode'        => '20090',
		'city'            => 'Segrate',
		'state'           => 'MI',
		'country'         => 'IT',
		'order_email'     => 'acquisti@nordcomponenti.example',
		'phone'           => '02 7000 4412',
		'contact_name'    => 'Marco Sala',
		'payment_terms'   => 'Bonifico 60 giorni',
		'lead_time_days'  => 12,
		'min_order_value' => '500',
	),
	array(
		'company_name'    => 'Delta Forniture',
		'vat_number'      => 'IT03910284755',
		'address'         => 'Via Emilia 210',
		'postcode'        => '40139',
		'city'            => 'Bologna',
		'state'           => 'BO',
		'country'         => 'IT',
		'order_email'     => 'info@deltaforniture.example',
		'phone'           => '051 660 1180',
		'contact_name'    => 'Giulia Neri',
		'payment_terms'   => 'Pagamento alla consegna',
		'lead_time_days'  => 3,
		'min_order_value' => '0',
	),
);

foreach ( $people as $fields ) {
	$fields['currency'] = 'EUR';

	$made[] = $suppliers->insert( Supplier::from_fields( $fields ) );
}

echo 'fornitori: ' . implode( ', ', $made ) . "\n";

// Listino: chi vende cosa, a quanto, con che condizioni. Due fornitori per il
// mouse, cosi' si vede che si puo' scegliere.
$lines = array(
	array( 'MOUSE-X', 0, 'BER-MX90', 'Mouse ottico wireless X', '11.80', 10, 5, 1, 5, true ),
	array( 'MOUSE-X', 1, 'NC-1180', 'Mouse wireless (compatibile)', '10.90', 50, 10, 10, 12, false ),
	array( 'KEYB-100', 0, 'BER-TK100', 'Tastiera meccanica 100 tasti', '38.50', 5, 1, 1, 5, true ),
	array( 'HUB-USB4', 1, 'NC-HUB4', 'Hub USB-C 4 porte', '17.40', 20, 10, 5, 12, true ),
	array( 'CAM-HD', 2, 'DF-WC1080', 'Webcam full HD', '24.90', 6, 2, 1, 3, true ),
	array( 'CAVO-HDMI2', 2, 'DF-HDMI2', 'Cavo HDMI 2 m', '3.20', 50, 25, 25, 3, true ),
	array( 'SSD-1TB', 1, 'NC-SSD1T', 'SSD NVMe 1 TB', '62.00', 4, 2, 1, 12, true ),
);

$saved = 0;

foreach ( $lines as $line ) {
	list( $sku, $who, $code, $description, $cost, $minimum, $multiple, $pack, $lead, $preferred ) = $line;

	$product_id = wc_get_product_id_by_sku( $sku );

	if ( ! $product_id ) {
		echo "  salto {$sku}: non c'e' nel negozio\n";
		continue;
	}

	$id = $listings->save(
		new SupplierProduct(
			0,
			$made[ $who ],
			$product_id,
			0,
			$code,
			$description,
			Money::from_decimal( $cost, 'EUR' ),
			new OrderTerms( $minimum, $multiple, $pack ),
			$lead,
			null,
			null,
			$preferred,
			''
		)
	);

	if ( $preferred ) {
		$listings->set_preferred( $id );
	}

	++$saved;
}

echo "righe di listino: {$saved}\n";

/**
 * One order, at whatever stage the caller asks for.
 */
function oxs_seed_order( PurchaseOrderRepository $orders, int $supplier_id, string $ordered_on, string $eta, array $items ): ?PurchaseOrder {
	$lines = array();

	foreach ( $items as $item ) {
		list( $sku, $description, $code, $quantity, $cost ) = $item;

		$product_id = wc_get_product_id_by_sku( $sku );

		if ( ! $product_id ) {
			continue;
		}

		$lines[] = new PurchaseOrderLine( 0, $product_id, 0, $sku, $code, $description, $quantity, 0, Money::from_decimal( $cost, 'EUR' ) );
	}

	if ( array() === $lines ) {
		return null;
	}

	return $orders->create(
		( new PurchaseOrder( 0, '', $supplier_id, PurchaseOrderStatus::DRAFT, 'EUR', $ordered_on, $eta ) )->with_lines( $lines )
	);
}

// Uno appena partito, che arriva fra cinque giorni.
$on_its_way = oxs_seed_order(
	$orders,
	$made[0],
	gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ),
	gmdate( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ),
	array(
		array( 'MOUSE-X', 'Mouse ottico wireless X', 'BER-MX90', 30, '11.80' ),
		array( 'KEYB-100', 'Tastiera meccanica 100 tasti', 'BER-TK100', 10, '38.50' ),
	)
);

$orders->mark_sent( $on_its_way );

// Uno arrivato a meta'.
$half = oxs_seed_order(
	$orders,
	$made[1],
	gmdate( 'Y-m-d', time() - 20 * DAY_IN_SECONDS ),
	gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ),
	array(
		array( 'SSD-1TB', 'SSD NVMe 1 TB', 'NC-SSD1T', 12, '62.00' ),
		array( 'HUB-USB4', 'Hub USB-C 4 porte', 'NC-HUB4', 20, '17.40' ),
	)
);

$orders->mark_sent( $half );

$half = $orders->find( $half->id );

$receiver->receive(
	$half,
	array( $half->lines[0]->id => 8 ),
	array( $half->lines[0]->id => '61.50' ),
	wp_generate_uuid4(),
	'DDT 2026/4471'
);

// E uno che doveva arrivare la settimana scorsa.
$late = oxs_seed_order(
	$orders,
	$made[2],
	gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ),
	gmdate( 'Y-m-d', time() - 8 * DAY_IN_SECONDS ),
	array(
		array( 'CAVO-HDMI2', 'Cavo HDMI 2 m', 'DF-HDMI2', 100, '3.20' ),
		array( 'CAM-HD', 'Webcam full HD', 'DF-WC1080', 6, '24.90' ),
	)
);

$orders->mark_sent( $late );

echo "ordini: {$on_its_way->id} in viaggio, {$half->id} arrivato a meta', {$late->id} in ritardo\n";
echo "fatto\n";
