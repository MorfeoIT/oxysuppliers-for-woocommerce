<?php
/**
 * Turning the document into a PDF.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;

use function Oxysoft\OxySuppliers\plugin_dir;

/**
 * The one place that knows about Dompdf.
 *
 * Loaded when a PDF is actually wanted and not before: the library is a hundred
 * classes and a few megabytes of fonts, and a shop that never prints an order
 * should never pay for it.
 *
 * Two settings here are security decisions rather than preferences. Remote
 * loading is **off**, so nothing in the document can make the site fetch a URL —
 * a template is HTML, and HTML that can fetch is a way out of the building. And
 * the chroot is the uploads directory, so a local path in a template cannot
 * reach wp-config.php.
 */
final class PdfRenderer {

	/**
	 * Whether the library is there.
	 *
	 * It is installed with the plugin, so this is about a broken install rather
	 * than an optional feature — but a missing file should say so, not fatal.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return class_exists( Dompdf::class ) || is_readable( plugin_dir() . 'vendor/autoload.php' );
	}

	/**
	 * Render HTML into PDF bytes.
	 *
	 * @param string $html  The document.
	 * @param string $paper Paper size, such as "A4".
	 * @return string The PDF, or an empty string when it could not be made.
	 */
	public function render( string $html, string $paper = 'A4' ): string {
		if ( ! $this->load() ) {
			return '';
		}

		$uploads = wp_upload_dir();
		$temp    = isset( $uploads['basedir'] ) ? $uploads['basedir'] . '/oxysuppliers-tmp' : get_temp_dir();

		if ( ! is_dir( $temp ) ) {
			wp_mkdir_p( $temp );
		}

		$options = new Options();

		// Nothing in a document may reach the network.
		$options->setIsRemoteEnabled( false );
		$options->setIsHtml5ParserEnabled( true );
		$options->setChroot( array( (string) ( $uploads['basedir'] ?? get_temp_dir() ) ) );
		$options->setTempDir( $temp );
		$options->setFontCache( $temp );
		$options->setDefaultFont( 'DejaVu Sans' );

		/**
		 * Filters the Dompdf options used for purchase order documents.
		 *
		 * @since 0.1.0
		 *
		 * @param Options $options The options.
		 */
		$options = apply_filters( 'oxysuppliers_pdf_options', $options );

		$dompdf = new Dompdf( $options );

		$dompdf->setPaper( $paper, 'portrait' );
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->render();

		$output = $dompdf->output();

		return is_string( $output ) ? $output : '';
	}

	/**
	 * Load the library, once.
	 *
	 * @return bool
	 */
	private function load(): bool {
		if ( class_exists( Dompdf::class ) ) {
			return true;
		}

		$autoload = plugin_dir() . 'vendor/autoload.php';

		if ( ! is_readable( $autoload ) ) {
			return false;
		}

		require_once $autoload;

		return class_exists( Dompdf::class );
	}
}
