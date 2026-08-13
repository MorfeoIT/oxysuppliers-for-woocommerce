<?php
// Sprint 7 on the seeded shop: goods on the way, the record of what things
// cost, OxyProfit both absent and present, the CSV import, and REST.
//
//   wp eval-file verify-sprint7.php
//
// The OxyProfit half is the reason this file exists. The CI has no WooCommerce
// and no OxyProfit, so the only place the two branches can both be walked is
// here, with scripts/fake-oxyprofit.php standing in for the other plugin.

if ( ! function_exists( 'wc_get_product' ) ) {
	echo "WooCommerce non caricato\n";
	return;
}

use Oxysoft\OxySuppliers\Domain\Money;
use Oxysoft\OxySuppliers\Domain\PurchaseOrder;
use Oxysoft\OxySuppliers\Domain\PurchaseOrderLine;
use Oxysoft\OxySuppliers\Domain\PurchaseOrderStatus;
use Oxysoft\OxySuppliers\Import\SupplierCsvParser;
use Oxysoft\OxySuppliers\Integration\OxyProfit;
use Oxysoft\OxySuppliers\Persistence\CostHistoryRepository;
use Oxysoft\OxySuppliers\Persistence\PurchaseOrderRepository;
use Oxysoft\OxySuppliers\Persistence\ReceiptRepository;
use Oxysoft\OxySuppliers\Service\AuditLogger;
use Oxysoft\OxySuppliers\Service\GoodsReceiver;
use Oxysoft\OxySuppliers\Service\PurchaseOrderNumbers;
use Oxysoft\OxySuppliers\Service\ReceiptOutcome;

$GLOBALS['oxs_passed'] = 0;
$GLOBALS['oxs_failed'] = 0;

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

// Il deploy riattiva il plugin, ma la migrazione la si chiede comunque: e' la
// stessa che gira su un sito aggiornato senza disattivare niente.
( new Oxysoft\OxySuppliers\Persistence\Migrator() )->migrate();

$wpdb->query( 'DELETE FROM oxs_oxysuppliers_cost_history' );
$wpdb->query( 'DELETE FROM oxs_oxysuppliers_receipt_items' );
$wpdb->query( 'DELETE FROM oxs_oxysuppliers_receipts' );
$wpdb->query( 'DELETE FROM oxs_oxysuppliers_purchase_order_items' );
$wpdb->query( 'DELETE FROM oxs_oxysuppliers_purchase_orders' );

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admin ? $admin[0]->ID : 1 );

$orders   = new PurchaseOrderRepository( new PurchaseOrderNumbers() );
$receipts = new ReceiptRepository();
$costs    = new CostHistoryRepository();
$receiver = new GoodsReceiver( $receipts, $orders, new AuditLogger(), $costs );

$mouse = wc_get_product_id_by_sku( 'MOUSE-X' );

echo "== lo schema 2 e' arrivato fin qui ==\n";

oxs_check(
	'la versione dello schema installata e\' quella attesa',
	Oxysoft\OxySuppliers\Persistence\Migrator::SCHEMA_VERSION === (int) get_option( 'oxysuppliers_db_version' ),
	'installata=' . get_option( 'oxysuppliers_db_version' )
);

$column = $wpdb->get_row( "SHOW COLUMNS FROM oxs_oxysuppliers_cost_history LIKE 'new_cost_minor'", ARRAY_A );

oxs_check( 'e il costo puo\' essere nullo, cioe\' sconosciuto', is_array( $column ) && 'YES' === $column['Null'], is_array( $column ) ? $column['Null'] : 'colonna assente' );

echo "\n== senza OxyProfit non succede niente, ed e' la cosa importante ==\n";

oxs_check(
	'l\'interfaccia di OxyProfit non c\'e\'',
	! interface_exists( 'Oxysoft\OxyProfit\Engine\CostSource' )
);

// autoload = true di proposito: e' la domanda vera. Se l'autoloader riuscisse a
// caricare questa classe su un sito senza OxyProfit, sarebbe un errore fatale.
oxs_check(
	'e la nostra classe che la implementa non e\' raggiungibile dall\'autoloader',
	! class_exists( 'Oxysoft\OxySuppliers\Integration\OxyProfitCostSource', true )
);

OxyProfit::maybe_register();

oxs_check( 'chiamare maybe_register() non la carica lo stesso', ! class_exists( 'Oxysoft\OxySuppliers\Integration\OxyProfitCostSource', false ) );
oxs_check( 'e nessuno si e\' iscritto al filtro', array() === apply_filters( 'oxyprofit_cost_sources', array() ) );

echo "\n== un ordine inviato: la merce e' in arrivo ==\n";

// Costruito a mano invece che con draft_for(): serve la data prevista, che e'
// il quarto campo, ed e' quella che deve arrivare fino alla scheda prodotto.
$eta = gmdate( 'Y-m-d', time() + 5 * DAY_IN_SECONDS );

$order = $orders->create(
	( new PurchaseOrder( 0, '', 1, PurchaseOrderStatus::DRAFT, 'EUR', gmdate( 'Y-m-d' ), $eta ) )->with_lines(
		array(
			new PurchaseOrderLine( 0, $mouse, 0, 'MOUSE-X', 'F-MX', 'Mouse X wireless', 30, 0, Money::from_minor( 1180, 'EUR' ) ),
		)
	)
);

$incoming = $orders->incoming_for_item( $mouse );

oxs_check( 'una bozza non e\' merce in arrivo', 0 === $incoming['quantity'], (string) $incoming['quantity'] );

$orders->mark_sent( $order );
$order = $orders->find( $order->id );

$incoming = $orders->incoming_for_item( $mouse );

oxs_check( 'appena inviato, sono trenta pezzi in arrivo', 30 === $incoming['quantity'], (string) $incoming['quantity'] );
oxs_check( 'con la data prevista dell\'ordine', $eta === $incoming['eta'], (string) $incoming['eta'] );

echo "\n== ne arrivano dieci, a un costo diverso da quello ordinato ==\n";

$first = $receiver->receive(
	$order,
	array( $order->lines[0]->id => 10 ),
	array( $order->lines[0]->id => '11,50' ),
	wp_generate_uuid4(),
	'DDT-S7-001'
);

oxs_check( 'ricezione registrata', ReceiptOutcome::RECORDED === $first->status, $first->detail );

$incoming = $orders->incoming_for_item( $mouse );

oxs_check( 'in arrivo restano venti, non trenta', 20 === $incoming['quantity'], (string) $incoming['quantity'] );

$cost = $costs->cost_on( $mouse, 0 );

oxs_check( 'lo storico dice 11,50, il costo pagato', null !== $cost && 1150 === $cost->minor, null === $cost ? 'niente' : (string) $cost->minor );
oxs_check( 'e non 11,80, il costo ordinato', null !== $cost && 1180 !== $cost->minor );

echo "\n== la seconda consegna arriva al costo dell'ordine ==\n";

$order = $orders->find( $order->id );

$second = $receiver->receive(
	$order,
	array( $order->lines[0]->id => 20 ),
	array(),
	wp_generate_uuid4(),
	'DDT-S7-002'
);

oxs_check( 'ricezione registrata', ReceiptOutcome::RECORDED === $second->status, $second->detail );

$incoming = $orders->incoming_for_item( $mouse );

oxs_check( 'non c\'e\' piu\' niente in arrivo', 0 === $incoming['quantity'], (string) $incoming['quantity'] );

$cost = $costs->cost_on( $mouse, 0 );

oxs_check( 'lo storico ora dice 11,80', null !== $cost && 1180 === $cost->minor, null === $cost ? 'niente' : (string) $cost->minor );
oxs_check( 'e ha due righe, non una riscritta', 2 === $costs->count_for( $mouse ), (string) $costs->count_for( $mouse ) );

echo "\n== annullo la seconda: il costo torna quello di prima ==\n";

$order = $orders->find( $order->id );

$reversal = $receiver->reverse( $second->receipt, wp_generate_uuid4() );

oxs_check( 'annullamento riuscito', ReceiptOutcome::RECORDED === $reversal->status, $reversal->detail );

$cost = $costs->cost_on( $mouse, 0 );

oxs_check(
	'il costo corrente e\' di nuovo 11,50',
	null !== $cost && 1150 === $cost->minor,
	null === $cost ? 'niente' : (string) $cost->minor
);

$history = $costs->history( $mouse, 0, 5 );

oxs_check( 'e lo storico ha tre righe: niente viene cancellato', 3 === count( $history ), (string) count( $history ) );
oxs_check( 'l\'ultima e\' marcata come annullamento', isset( $history[0]['source'] ) && str_contains( (string) $history[0]['source'], 'revers' ), (string) ( $history[0]['source'] ?? '' ) );

$incoming = $orders->incoming_for_item( $mouse );

oxs_check( 'e i venti pezzi tornano a essere in arrivo', 20 === $incoming['quantity'], (string) $incoming['quantity'] );

echo "\n== adesso OxyProfit c'e' ==\n";

require_once __DIR__ . '/fake-oxyprofit.php';

oxs_check( 'l\'interfaccia adesso esiste', interface_exists( 'Oxysoft\OxyProfit\Engine\CostSource' ) );

OxyProfit::maybe_register();

$sources = apply_filters( 'oxyprofit_cost_sources', array( 'segnaposto' ) );

oxs_check( 'la nostra fonte si e\' iscritta', 2 === count( $sources ), (string) count( $sources ) );
oxs_check( 'ed e\' la prima: un costo pagato batte uno digitato', $sources[0] instanceof Oxysoft\OxySuppliers\Integration\OxyProfitCostSource );

$source = $sources[0];

oxs_check( 'si presenta con il suo nome', 'oxysuppliers' === $source->name(), $source->name() );

$answer = $source->unit_cost( new Oxysoft\OxyProfit\Engine\CostQuery( $mouse, 0, gmdate( 'Y-m-d' ), 'EUR' ) );

oxs_check( 'e risponde 11,50 su MOUSE-X', null !== $answer && 1150 === $answer->minor, null === $answer ? 'niente' : (string) $answer->minor );

$unknown = $source->unit_cost( new Oxysoft\OxyProfit\Engine\CostQuery( 999999, 0, gmdate( 'Y-m-d' ), 'EUR' ) );

oxs_check( 'su un articolo mai comprato risponde "non lo so", non zero', null === $unknown, null === $unknown ? '' : 'ha risposto ' . $unknown->minor );

$wrong_currency = $source->unit_cost( new Oxysoft\OxyProfit\Engine\CostQuery( $mouse, 0, gmdate( 'Y-m-d' ), 'USD' ) );

oxs_check( 'in un\'altra valuta tace, invece di dare il numero sbagliato', null === $wrong_currency );

$yesterday = $source->unit_cost( new Oxysoft\OxyProfit\Engine\CostQuery( $mouse, 0, gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ), 'EUR' ) );

oxs_check( 'un mese fa non sapevamo ancora niente', null === $yesterday, null === $yesterday ? '' : 'ha risposto ' . $yesterday->minor );

echo "\n== il listino che arriva da Excel ==\n";

// BOM, punto e virgola, virgola decimale, intestazioni in italiano: il file
// vero, non quello di scuola.
$csv = "\xEF\xBB\xBF" . "ragione sociale;codice interno;codice fornitore;prezzo;minimo;multiplo;giorni\n"
	. "Fornitore Uno;MOUSE-X;F-MX;11,80;10;5;7\n"
	. "Fornitore Uno;TAST-Y;F-TY;1.234,56;1;1;3\n"
	. ";SENZA-FORNITORE;X;5,00;1;1;1\n"
	. "Fornitore Uno;;;;;;\n"
	. "Fornitore Uno;COSTO-ROTTO;F-CR;undici euro;1;1;1\n";

$parsed = ( new SupplierCsvParser() )->parse( $csv );

oxs_check( 'il file si legge nonostante il BOM e i punti e virgola', $parsed->is_usable(), implode( '; ', $parsed->problems ) );
oxs_check( 'cinque righe di dati', 5 === count( $parsed->rows ), (string) count( $parsed->rows ) );
oxs_check( '11,80 diventa 11.80', '11.80' === SupplierCsvParser::number( '11,80' ), (string) SupplierCsvParser::number( '11,80' ) );
oxs_check( '1.234,56 diventa 1234.56', '1234.56' === SupplierCsvParser::number( '1.234,56' ), (string) SupplierCsvParser::number( '1.234,56' ) );
oxs_check( '11.80 resta 11.80', '11.80' === SupplierCsvParser::number( '11.80' ), (string) SupplierCsvParser::number( '11.80' ) );

$bad = 0;

foreach ( $parsed->rows as $row ) {
	if ( array() !== $row['errors'] ) {
		++$bad;
	}
}

oxs_check( 'tre righe sono segnalate come sbagliate, non buttate via', 3 === $bad, (string) $bad );

echo "\n== e le rotte REST ==\n";

do_action( 'rest_api_init' );

$routes = rest_get_server()->get_routes();

foreach ( array( '/oxysuppliers/v1/requirements', '/oxysuppliers/v1/orders', '/oxysuppliers/v1/suppliers' ) as $route ) {
	oxs_check( "la rotta {$route} esiste", isset( $routes[ $route ] ) );

	if ( ! isset( $routes[ $route ] ) ) {
		continue;
	}

	$methods = array();

	foreach ( $routes[ $route ] as $handler ) {
		$methods = array_merge( $methods, array_keys( array_filter( $handler['methods'] ) ) );
	}

	oxs_check( "  e legge soltanto", array( 'GET' ) === array_values( array_unique( $methods ) ), implode( ',', $methods ) );
	oxs_check( "  con un controllo dei permessi", is_callable( $routes[ $route ][0]['permission_callback'] ) );
}

$subscriber = get_users( array( 'role' => 'subscriber', 'number' => 1 ) );

if ( $subscriber ) {
	wp_set_current_user( $subscriber[0]->ID );

	$denied = rest_do_request( new WP_REST_Request( 'GET', '/oxysuppliers/v1/requirements' ) );

	oxs_check( 'un iscritto qualunque si prende un 403', 403 === $denied->get_status(), (string) $denied->get_status() );

	wp_set_current_user( $admin ? $admin[0]->ID : 1 );
}

$allowed = rest_do_request( new WP_REST_Request( 'GET', '/oxysuppliers/v1/requirements' ) );

oxs_check( 'l\'amministratore invece entra', 200 === $allowed->get_status(), (string) $allowed->get_status() );

$data = $allowed->get_data();

oxs_check( 'e riceve delle righe', is_array( $data ) && array() !== $data );

echo "\n== ===============================\n";
printf( "== superati: %d   falliti: %d\n", $GLOBALS['oxs_passed'], $GLOBALS['oxs_failed'] );
