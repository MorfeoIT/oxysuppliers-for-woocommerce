<?php
// Builds a real purchase order, prints it, and sends it — on the test bench,
// where the post is captured rather than delivered.
//
//   wp eval-file verify-pdf-email.php

if ( ! function_exists( 'wc_get_product' ) ) {
	echo "WooCommerce non caricato\n";
	return;
}

use Oxysoft\OxySuppliers\Domain\PurchaseOrder;
use Oxysoft\OxySuppliers\Domain\PurchaseOrderLine;
use Oxysoft\OxySuppliers\Domain\Money;
use Oxysoft\OxySuppliers\Domain\Supplier;
use Oxysoft\OxySuppliers\Pdf\PdfRenderer;
use Oxysoft\OxySuppliers\Pdf\PurchaseOrderDocument;
use Oxysoft\OxySuppliers\Persistence\PurchaseOrderRepository;
use Oxysoft\OxySuppliers\Persistence\SupplierRepository;
use Oxysoft\OxySuppliers\Service\AuditLogger;
use Oxysoft\OxySuppliers\Service\PurchaseOrderMailer;
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

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admin ? $admin[0]->ID : 1 );

$suppliers = new SupplierRepository();
$orders    = new PurchaseOrderRepository( new PurchaseOrderNumbers() );
$document  = new PurchaseOrderDocument();
$renderer  = new PdfRenderer();
$audit     = new AuditLogger();
$mailer    = new PurchaseOrderMailer( $document, $renderer, $audit );

echo "== un ordine vero, con un fornitore vero ==\n";

$supplier_id = $suppliers->insert(
	Supplier::from_fields(
		array(
			'company_name'   => 'Fornitura Però & Figli Srl',
			'vat_number'     => 'IT01234567890',
			'address'        => 'Via dell\'Acquedotto 12',
			'postcode'       => '20121',
			'city'           => 'Milano',
			'country'        => 'IT',
			'order_email'    => 'ordini@example.test',
			'contact_name'   => 'Giuseppe Però',
			'currency'       => 'EUR',
			'payment_terms'  => '30 giorni fine mese',
			'lead_time_days' => '4',
		)
	)
);

$supplier = $suppliers->find( $supplier_id );

oxs_check( 'fornitore creato, accenti compresi', null !== $supplier && str_contains( $supplier->company_name, 'Però' ) );

$order = $orders->create(
	PurchaseOrder::draft_for( $supplier_id, 'EUR', gmdate( 'Y-m-d' ) )
		->with_lines(
			array(
				new PurchaseOrderLine( 0, 10, 0, 'MOUSE-X', 'F-MX-123', 'Mouse X wireless', 20, 0, Money::from_minor( 1180, 'EUR' ), 0, 2200 ),
				new PurchaseOrderLine( 0, 15, 0, 'SSD-1TB', 'F-SSD', 'SSD NVMe 1 TB — 3ª generazione', 5, 0, Money::from_minor( 6200, 'EUR' ), 500, 2200 ),
			)
		)
);

oxs_check( 'ordine creato', null !== $order, null === $order ? 'non creato' : $order->number );

if ( null === $order ) {
	return;
}

echo "\n== il documento ==\n";

$html = $document->html( $order, $supplier );

oxs_check( 'porta il numero dell\'ordine', str_contains( $html, $order->number ) );
oxs_check( 'e il nome del fornitore', str_contains( $html, 'Fornitura Però' ) );
oxs_check( 'il codice del fornitore, non il nostro', str_contains( $html, 'F-MX-123' ) );
oxs_check( 'le condizioni di pagamento', str_contains( $html, '30 giorni fine mese' ) );
oxs_check( 'il totale con l\'IVA', str_contains( $html, $order->total()->to_decimal() ), $order->total()->to_decimal() );
oxs_check( 'e i nostri dati aziendali', str_contains( $html, (string) get_bloginfo( 'name' ) ) );

echo "\n== il PDF ==\n";

oxs_check( 'la libreria c\'e\'', $renderer->is_available() );

$started = microtime( true );
$pdf     = $renderer->render( $html );
$took    = round( ( microtime( true ) - $started ) * 1000 );

oxs_check( 'e\' stato generato', '' !== $pdf );
oxs_check( 'ed e\' davvero un PDF', str_starts_with( $pdf, '%PDF-' ), substr( $pdf, 0, 8 ) );
oxs_check( 'di dimensione sensata', strlen( $pdf ) > 2000, strlen( $pdf ) . ' byte' );
echo "  (generato in {$took} ms, " . number_format( strlen( $pdf ) / 1024, 1 ) . " KB)\n";

// There is no PDF parser on this machine, so "the text is in there" cannot be
// asserted honestly by reading it back. What can be asserted is that the
// content is really being rendered rather than a blank page coming out: the
// same document with one line fewer produces a measurably smaller file.
$shorter = $renderer->render(
	$document->html( $order->with_lines( array( $order->lines[0] ) ), $supplier )
);

oxs_check(
	'un ordine con una riga in meno produce un PDF piu\' piccolo',
	'' !== $shorter && strlen( $shorter ) < strlen( $pdf ),
	strlen( $shorter ) . ' vs ' . strlen( $pdf ) . ' byte'
);

// The accented characters are the reason the fonts are in the box: a shop
// called "Però" must not come out as "Per". The font that carries them has to
// be embedded, which shows up in the file itself.
oxs_check(
	'il PDF incorpora un font, quindi gli accenti hanno di che disegnarsi',
	str_contains( $pdf, 'FontFile' ) || str_contains( $pdf, 'DejaVu' ),
	'nessun font incorporato'
);

echo "\n== l'invio al fornitore ==\n";

$log_path = WP_CONTENT_DIR . '/mail.log';
$before   = file_exists( $log_path ) ? filesize( $log_path ) : 0;

$result = $mailer->send(
	$order,
	$supplier,
	array(
		'to'      => 'ordini@example.test',
		'cc'      => 'copia@example.test, non-un-indirizzo',
		'bcc'     => '',
		'subject' => PurchaseOrderMailer::fill( 'Ordine {number} da {company}', $order ),
		'body'    => PurchaseOrderMailer::fill( "Buongiorno,\n\nin allegato l'ordine {number}.\n", $order ),
	)
);

oxs_check( 'l\'invio riesce', $result['sent'], $result['error'] );
oxs_check( 'ed e\' il primo, non un reinvio', ! $result['resend'] );

$captured = file_exists( $log_path ) ? file_get_contents( $log_path, false, null, $before ) : '';

oxs_check( 'la posta e\' stata catturata', '' !== $captured );
oxs_check( 'con il destinatario giusto', str_contains( $captured, 'ordini@example.test' ) );
oxs_check( 'il numero nell\'oggetto', str_contains( $captured, $order->number ) );
oxs_check( 'e il PDF allegato', str_contains( $captured, '.pdf' ), 'nessun allegato nel log' );
oxs_check( 'l\'indirizzo in copia buono passa', str_contains( $captured, 'copia@example.test' ) );
oxs_check( 'quello sbagliato no', ! str_contains( $captured, 'non-un-indirizzo' ) );

echo "\n== il file temporaneo non resta in giro ==\n";

$leftovers = glob( trailingslashit( get_temp_dir() ) . 'oxysuppliers-po-*' );

oxs_check( 'nessun PDF dimenticato nella cartella temporanea', array() === $leftovers || null === $leftovers, is_array( $leftovers ) ? implode( ', ', $leftovers ) : '' );

echo "\n== il reinvio si distingue dal primo invio ==\n";

$again = $mailer->send(
	$order,
	$supplier,
	array(
		'to'      => 'ordini@example.test',
		'cc'      => '',
		'bcc'     => '',
		'subject' => 'Sollecito ' . $order->number,
		'body'    => 'Buongiorno, ricordiamo l\'ordine.',
	)
);

oxs_check( 'il secondo invio riesce', $again['sent'] );
oxs_check( 'ed e\' segnalato come reinvio', $again['resend'] );

$sends = $audit->count( AuditLogger::OBJECT_ORDER, (string) $order->id, AuditLogger::ACTION_SENT );
oxs_check( 'il registro ne conta due', 2 === $sends, (string) $sends );

$history = $audit->history( AuditLogger::OBJECT_ORDER, (string) $order->id, 10 );
$first   = '';
$second  = '';

foreach ( array_reverse( $history ) as $line ) {
	if ( AuditLogger::ACTION_SENT === $line['action'] ) {
		if ( '' === $first ) {
			$first = (string) $line['message'];
		} else {
			$second = (string) $line['message'];
		}
	}
}

oxs_check( 'e li racconta in modo diverso', '' !== $first && '' !== $second && $first !== $second, "1: {$first} | 2: {$second}" );

echo "\n== un indirizzo che non e' un indirizzo ==\n";

$refused = $mailer->send(
	$order,
	$supplier,
	array(
		'to'      => 'non-e-un-indirizzo',
		'cc'      => '',
		'bcc'     => '',
		'subject' => 'x',
		'body'    => 'x',
	)
);

oxs_check( 'viene rifiutato prima di spedire', ! $refused['sent'] );
oxs_check( 'e non finisce nel registro degli invii', 2 === $audit->count( AuditLogger::OBJECT_ORDER, (string) $order->id, AuditLogger::ACTION_SENT ) );

echo "\n== ===============================\n";
printf( "== superati: %d   falliti: %d\n", $GLOBALS['oxs_passed'], $GLOBALS['oxs_failed'] );
