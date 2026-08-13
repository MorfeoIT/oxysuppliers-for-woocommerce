<?php
/**
 * Sending a purchase order to a supplier.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Service;

use Oxysoft\OxySuppliers\Domain\PurchaseOrder;
use Oxysoft\OxySuppliers\Domain\Supplier;
use Oxysoft\OxySuppliers\Pdf\PdfRenderer;
use Oxysoft\OxySuppliers\Pdf\PurchaseOrderDocument;

/**
 * Puts the order in an envelope.
 *
 * The one irreversible thing this plugin does: an email is out of the building
 * the moment it is sent, and the supplier has it. So the screen shows exactly
 * who it will go to before anything is pressed, and this records what happened
 * afterwards — including whether it had already gone once, which the order
 * itself cannot say because it only remembers the last time.
 */
final class PurchaseOrderMailer {

	/**
	 * Take the collaborators.
	 *
	 * @param PurchaseOrderDocument $document Builds the document.
	 * @param PdfRenderer           $renderer Turns it into a PDF.
	 * @param AuditLogger           $audit    The trail.
	 */
	public function __construct(
		private readonly PurchaseOrderDocument $document,
		private readonly PdfRenderer $renderer,
		private readonly AuditLogger $audit
	) {
	}

	/**
	 * Send an order.
	 *
	 * @param PurchaseOrder                                                    $order    The order.
	 * @param Supplier|null                                                    $supplier Who it is for.
	 * @param array{to:string,cc:string,bcc:string,subject:string,body:string} $message What to send.
	 * @return array{sent:bool,resend:bool,error:string}
	 */
	public function send( PurchaseOrder $order, ?Supplier $supplier, array $message ): array {
		$to = sanitize_email( $message['to'] );

		if ( '' === $to || ! is_email( $to ) ) {
			return array(
				'sent'   => false,
				'resend' => false,
				'error'  => __( 'That is not an email address.', 'oxysuppliers-for-woocommerce' ),
			);
		}

		$already = $this->audit->count( AuditLogger::OBJECT_ORDER, (string) $order->id, AuditLogger::ACTION_SENT );
		$resend  = $already > 0;

		$attachment = $this->write_pdf( $order, $supplier );

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		foreach ( array(
			'Cc'  => $message['cc'],
			'Bcc' => $message['bcc'],
		) as $field => $addresses ) {
			$cleaned = $this->clean_addresses( $addresses );

			if ( '' !== $cleaned ) {
				$headers[] = $field . ': ' . $cleaned;
			}
		}

		/**
		 * Filters the email a purchase order is sent in.
		 *
		 * @since 0.1.0
		 *
		 * @param array{to:string,subject:string,body:string,headers:list<string>} $email The message.
		 * @param PurchaseOrder                                                    $order The order.
		 */
		$email = apply_filters(
			'oxysuppliers_purchase_order_email',
			array(
				'to'      => $to,
				'subject' => $message['subject'],
				'body'    => $message['body'],
				'headers' => $headers,
			),
			$order
		);

		$sent = wp_mail(
			(string) $email['to'],
			(string) $email['subject'],
			(string) $email['body'],
			(array) $email['headers'],
			'' === $attachment ? array() : array( $attachment )
		);

		// The attachment was only ever a way to get the bytes into the message.
		// Leaving purchase orders lying about in the uploads folder would put
		// them one guessed URL away from anybody.
		if ( '' !== $attachment && file_exists( $attachment ) ) {
			wp_delete_file( $attachment );
		}

		$this->audit->log(
			AuditLogger::OBJECT_ORDER,
			(string) $order->id,
			AuditLogger::ACTION_SENT,
			null,
			array(
				'to'     => $to,
				'resend' => $resend,
				'ok'     => (bool) $sent,
			),
			$resend
				/* translators: %s: email address. */
				? sprintf( __( 'Sent again to %s', 'oxysuppliers-for-woocommerce' ), $to )
				/* translators: %s: email address. */
				: sprintf( __( 'Sent to %s', 'oxysuppliers-for-woocommerce' ), $to )
		);

		return array(
			'sent'   => (bool) $sent,
			'resend' => $resend,
			'error'  => $sent ? '' : __( 'WordPress could not send the message. Check the site\'s email settings.', 'oxysuppliers-for-woocommerce' ),
		);
	}

	/**
	 * Write the PDF somewhere it can be attached from.
	 *
	 * In the system temp directory, not in the uploads folder: it exists for
	 * the length of one send and is deleted straight afterwards.
	 *
	 * @param PurchaseOrder $order    The order.
	 * @param Supplier|null $supplier Who it is for.
	 * @return string Path, or empty when there is no PDF.
	 */
	private function write_pdf( PurchaseOrder $order, ?Supplier $supplier ): string {
		$pdf = $this->renderer->render( $this->document->html( $order, $supplier ) );

		if ( '' === $pdf ) {
			return '';
		}

		$name = sanitize_file_name( $order->number . '.pdf' );
		$path = trailingslashit( get_temp_dir() ) . uniqid( 'oxysuppliers-', true ) . '-' . $name;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temporary file for one attachment, deleted straight after.
		$written = file_put_contents( $path, $pdf );

		return false === $written ? '' : $path;
	}

	/**
	 * Keep only what looks like an address, comma separated.
	 *
	 * @param string $addresses Raw list.
	 * @return string
	 */
	private function clean_addresses( string $addresses ): string {
		$clean = array();

		foreach ( explode( ',', $addresses ) as $candidate ) {
			$address = sanitize_email( trim( $candidate ) );

			if ( '' !== $address && is_email( $address ) ) {
				$clean[] = $address;
			}
		}

		return implode( ', ', $clean );
	}

	/**
	 * Fill in the placeholders of a subject or a message.
	 *
	 * @param string        $text  The template.
	 * @param PurchaseOrder $order The order.
	 * @return string
	 */
	public static function fill( string $text, PurchaseOrder $order ): string {
		return strtr(
			$text,
			array(
				'{number}'   => $order->number,
				'{company}'  => (string) get_bloginfo( 'name' ),
				'{date}'     => $order->order_date,
				'{expected}' => (string) $order->expected_date,
				'{total}'    => $order->total()->to_decimal() . ' ' . $order->currency,
			)
		);
	}
}
