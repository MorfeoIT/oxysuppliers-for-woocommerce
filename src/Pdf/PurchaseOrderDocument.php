<?php
/**
 * The purchase order as a document.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Pdf;

use Oxysoft\OxySuppliers\Domain\PurchaseOrder;
use Oxysoft\OxySuppliers\Domain\Supplier;

use function Oxysoft\OxySuppliers\plugin_dir;

/**
 * Builds the HTML a purchase order is printed from.
 *
 * The template is a plain PHP file and can be replaced by a theme, the way
 * WooCommerce does it: put a file at `oxysuppliers/purchase-order.php` in the
 * theme and it wins. What a shop sends its suppliers is the shop's business.
 */
final class PurchaseOrderDocument {

	/**
	 * Name of the template file.
	 */
	private const TEMPLATE = 'purchase-order.php';

	/**
	 * Build the HTML.
	 *
	 * @param PurchaseOrder $order    The order.
	 * @param Supplier|null $supplier Who it is for.
	 * @return string
	 */
	public function html( PurchaseOrder $order, ?Supplier $supplier ): string {
		$data = array(
			'order'    => $order,
			'supplier' => $supplier,
			'company'  => $this->company(),
			'logo'     => $this->logo(),
			'labels'   => $this->labels(),
		);

		/**
		 * Filters what the purchase order template is given.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string,mixed> $data  Template data.
		 * @param PurchaseOrder       $order The order.
		 */
		$data = apply_filters( 'oxysuppliers_document_data', $data, $order );

		ob_start();

		// The template reads $data. Extracting into loose variables would let a
		// filter invent any variable name it liked inside our own file.
		include $this->template_path();

		return (string) ob_get_clean();
	}

	/**
	 * Where the template lives.
	 *
	 * A theme's copy wins, and then the plugin's.
	 *
	 * @return string
	 */
	private function template_path(): string {
		$theme = locate_template( array( 'oxysuppliers/' . self::TEMPLATE ) );

		$path = '' !== $theme ? $theme : plugin_dir() . 'templates/' . self::TEMPLATE;

		/**
		 * Filters the template a purchase order is printed from.
		 *
		 * @since 0.1.0
		 *
		 * @param string $path Absolute path to the template.
		 */
		$path = (string) apply_filters( 'oxysuppliers_document_template', $path );

		return is_readable( $path ) ? $path : plugin_dir() . 'templates/' . self::TEMPLATE;
	}

	/**
	 * Who is sending the order.
	 *
	 * Taken from what WooCommerce already knows, so nothing new has to be
	 * filled in before the first order can be printed.
	 *
	 * @return array<string,string>
	 */
	private function company(): array {
		$countries = function_exists( 'WC' ) && null !== WC()->countries ? WC()->countries : null;
		$country   = (string) get_option( 'woocommerce_default_country', '' );
		$country   = explode( ':', $country )[0];

		return array(
			'name'     => (string) get_bloginfo( 'name' ),
			'address'  => (string) get_option( 'woocommerce_store_address', '' ),
			'address2' => (string) get_option( 'woocommerce_store_address_2', '' ),
			'city'     => (string) get_option( 'woocommerce_store_city', '' ),
			'postcode' => (string) get_option( 'woocommerce_store_postcode', '' ),
			'country'  => null !== $countries ? (string) ( $countries->get_countries()[ $country ] ?? $country ) : $country,
			'email'    => (string) get_option( 'admin_email', '' ),
		);
	}

	/**
	 * The logo, as a data URI.
	 *
	 * Inlined rather than linked because the renderer is not allowed to fetch
	 * anything: a document that can reach a URL is a way out of the building.
	 *
	 * @return string
	 */
	private function logo(): string {
		$attachment_id = (int) get_option( 'woocommerce_email_header_image', 0 );

		if ( 0 === $attachment_id ) {
			$attachment_id = (int) get_option( 'site_icon', 0 );
		}

		/**
		 * Filters the attachment used as the logo on purchase orders.
		 *
		 * @since 0.1.0
		 *
		 * @param int $attachment_id Attachment id, or 0 for none.
		 */
		$attachment_id = (int) apply_filters( 'oxysuppliers_document_logo_id', $attachment_id );

		if ( $attachment_id <= 0 ) {
			return '';
		}

		$path = get_attached_file( $attachment_id );

		if ( ! is_string( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		$type = wp_check_filetype( $path );
		$mime = (string) ( $type['type'] ?? '' );

		if ( ! in_array( $mime, array( 'image/png', 'image/jpeg', 'image/gif' ), true ) ) {
			return '';
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file being inlined, not a remote request.

		if ( false === $bytes || strlen( $bytes ) > 512000 ) {
			return '';
		}

		return 'data:' . $mime . ';base64,' . base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Inlining an image, which is what a data URI is.
	}

	/**
	 * The words on the document.
	 *
	 * In one place so a translation has one thing to translate, and so a filter
	 * can change a word without replacing the whole template.
	 *
	 * @return array<string,string>
	 */
	private function labels(): array {
		return array(
			'title'      => __( 'Purchase order', 'oxysuppliers-for-woocommerce' ),
			'number'     => __( 'Number', 'oxysuppliers-for-woocommerce' ),
			'date'       => __( 'Date', 'oxysuppliers-for-woocommerce' ),
			'expected'   => __( 'Expected', 'oxysuppliers-for-woocommerce' ),
			'supplier'   => __( 'Supplier', 'oxysuppliers-for-woocommerce' ),
			'deliver_to' => __( 'Deliver to', 'oxysuppliers-for-woocommerce' ),
			'code'       => __( 'Code', 'oxysuppliers-for-woocommerce' ),
			'article'    => __( 'Article', 'oxysuppliers-for-woocommerce' ),
			'quantity'   => __( 'Quantity', 'oxysuppliers-for-woocommerce' ),
			'unit_cost'  => __( 'Unit price', 'oxysuppliers-for-woocommerce' ),
			'line_total' => __( 'Total', 'oxysuppliers-for-woocommerce' ),
			'subtotal'   => __( 'Subtotal', 'oxysuppliers-for-woocommerce' ),
			'tax'        => __( 'Tax', 'oxysuppliers-for-woocommerce' ),
			'total'      => __( 'Order total', 'oxysuppliers-for-woocommerce' ),
			'terms'      => __( 'Payment terms', 'oxysuppliers-for-woocommerce' ),
			'reference'  => __( 'Your reference', 'oxysuppliers-for-woocommerce' ),
			'notes'      => __( 'Notes', 'oxysuppliers-for-woocommerce' ),
		);
	}
}
