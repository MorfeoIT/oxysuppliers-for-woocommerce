<?php
/**
 * The import tab.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Admin;

use Oxysoft\OxySuppliers\Import\ParsedCsv;
use Oxysoft\OxySuppliers\Import\PriceListImporter;
use Oxysoft\OxySuppliers\Import\SupplierCsvParser;
use Oxysoft\OxySuppliers\Support\Capabilities;

/**
 * Bringing in a supplier's price list from a file.
 *
 * **The preview is not optional** (§26). Uploading parses and shows what would
 * happen, row by row, including the rows that will be skipped and why; only
 * then is there a button that does it. An import that runs on upload is one
 * somebody finds out about afterwards, on live data, having meant to look first.
 *
 * The file is never stored. It is read from the upload, parsed, and what is kept
 * is the parsed rows, in a transient belonging to the person who uploaded it.
 */
final class ImportScreen implements Screen {

	public const SLUG = 'import';

	public const UPLOAD_ACTION  = 'oxysuppliers_import_upload';
	public const CONFIRM_ACTION = 'oxysuppliers_import_confirm';

	/**
	 * The most rows one file may bring.
	 *
	 * Not a technical limit: a preview of ten thousand rows is not a preview,
	 * and an import nobody read is the thing this screen exists to prevent.
	 */
	private const MAX_ROWS = 2000;

	/**
	 * Take the collaborators.
	 *
	 * @param SupplierCsvParser $parser   Reads the file.
	 * @param PriceListImporter $importer Says what would happen, and does it.
	 */
	public function __construct(
		private readonly SupplierCsvParser $parser,
		private readonly PriceListImporter $importer
	) {
	}

	/**
	 * Slug used in the address.
	 *
	 * @return string
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * Name shown on the tab.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Import', 'oxysuppliers-for-woocommerce' );
	}

	/**
	 * Capability required to open it.
	 *
	 * @return string
	 */
	public function capability(): string {
		return Capabilities::MANAGE_SUPPLIERS;
	}

	/**
	 * Hook up the form handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::UPLOAD_ACTION, array( $this, 'handle_upload' ) );
		add_action( 'admin_post_' . self::CONFIRM_ACTION, array( $this, 'handle_confirm' ) );
	}

	/**
	 * Draw the screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to import suppliers.', 'oxysuppliers-for-woocommerce' ), 403 );
		}

		$this->render_notice();

		$pending = get_transient( $this->key() );

		if ( is_array( $pending ) && isset( $pending['rows'] ) ) {
			$this->render_preview( new ParsedCsv( $pending['header'] ?? array(), $pending['rows'], array() ) );

			return;
		}

		$this->render_upload_form();
	}

	/**
	 * The form that takes the file.
	 *
	 * @return void
	 */
	private function render_upload_form(): void {
		?>
		<h1><?php esc_html_e( 'Import a price list', 'oxysuppliers-for-woocommerce' ); ?></h1>

		<p class="description">
			<?php esc_html_e( 'A CSV file with one row per article a supplier sells you. Nothing is changed until you have seen what it would do.', 'oxysuppliers-for-woocommerce' ); ?>
		</p>

		<table class="widefat striped" style="max-width: 780px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Column', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'What it is', 'oxysuppliers-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>supplier</code></td><td><?php esc_html_e( 'The supplier\'s name. Required.', 'oxysuppliers-for-woocommerce' ); ?></td></tr>
				<tr><td><code>sku</code></td><td><?php esc_html_e( 'Your own SKU, as WooCommerce knows it. Required.', 'oxysuppliers-for-woocommerce' ); ?></td></tr>
				<tr><td><code>vat</code></td><td><?php esc_html_e( 'Their VAT number. Used to recognise a supplier you already have.', 'oxysuppliers-for-woocommerce' ); ?></td></tr>
				<tr><td><code>email</code></td><td><?php esc_html_e( 'Where their orders should go.', 'oxysuppliers-for-woocommerce' ); ?></td></tr>
				<tr><td><code>supplier code</code></td><td><?php esc_html_e( 'Their code for the article, which is what goes on the order.', 'oxysuppliers-for-woocommerce' ); ?></td></tr>
				<tr><td><code>cost</code></td><td><?php esc_html_e( 'What they charge. A comma or a full stop, either way.', 'oxysuppliers-for-woocommerce' ); ?></td></tr>
				<tr><td><code>moq</code>, <code>multiple</code>, <code>pack</code>, <code>lead time</code></td><td><?php esc_html_e( 'Their buying terms, as whole numbers.', 'oxysuppliers-for-woocommerce' ); ?></td></tr>
			</tbody>
		</table>

		<p class="description">
			<?php esc_html_e( 'Italian headings work too, and so do semicolons instead of commas: a file saved by a spreadsheet is still a file.', 'oxysuppliers-for-woocommerce' ); ?>
		</p>

		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::UPLOAD_ACTION ); ?>">
			<?php wp_nonce_field( self::UPLOAD_ACTION ); ?>

			<p>
				<input type="file" name="oxysuppliers_csv" accept=".csv,text/csv,text/plain" required>
			</p>

			<p class="submit">
				<?php submit_button( __( 'Read the file', 'oxysuppliers-for-woocommerce' ), 'primary', 'submit', false ); ?>
			</p>
		</form>
		<?php
	}

	/**
	 * What the import would do.
	 *
	 * @param ParsedCsv $file The parsed file.
	 * @return void
	 */
	private function render_preview( ParsedCsv $file ): void {
		$plan = $this->importer->plan( $file );

		$new_suppliers = 0;
		$new_lines     = 0;
		$updates       = 0;
		$skips         = 0;

		foreach ( $plan as $entry ) {
			$new_suppliers += in_array( PriceListImporter::CREATE_SUPPLIER, $entry['actions'], true ) ? 1 : 0;
			$new_lines     += in_array( PriceListImporter::CREATE_LINE, $entry['actions'], true ) ? 1 : 0;
			$updates       += in_array( PriceListImporter::UPDATE_LINE, $entry['actions'], true ) ? 1 : 0;
			$skips         += in_array( PriceListImporter::SKIP, $entry['actions'], true ) ? 1 : 0;
		}

		?>
		<h1><?php esc_html_e( 'What this would do', 'oxysuppliers-for-woocommerce' ); ?></h1>

		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: new suppliers, 2: new price list lines, 3: updated lines, 4: skipped rows. */
					__( '%1$d suppliers would be created, %2$d price list lines added, %3$d updated, and %4$d rows skipped.', 'oxysuppliers-for-woocommerce' ),
					$new_suppliers,
					$new_lines,
					$updates,
					$skips
				)
			);
			?>
		</p>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width: 60px;"><?php esc_html_e( 'Line', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Supplier', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'SKU', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Cost', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'What happens', 'oxysuppliers-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $plan as $entry ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $entry['line'] ); ?></td>
					<td><?php echo esc_html( $entry['supplier'] ); ?></td>
					<td><?php echo esc_html( $entry['sku'] ); ?></td>
					<td><?php echo esc_html( $entry['cost'] ); ?></td>
					<td>
						<?php if ( array() !== $entry['errors'] ) : ?>
							<span class="oxysuppliers-status is-out"><?php esc_html_e( 'skipped', 'oxysuppliers-for-woocommerce' ); ?></span>
							<span class="description"><?php echo esc_html( implode( ', ', $entry['errors'] ) ); ?></span>
						<?php else : ?>
							<?php echo esc_html( $this->describe( array_values( (array) $entry['actions'] ) ) ); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 1em;">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::CONFIRM_ACTION ); ?>">
			<?php wp_nonce_field( self::CONFIRM_ACTION ); ?>

			<?php submit_button( __( 'Do it', 'oxysuppliers-for-woocommerce' ), 'primary', 'submit', false ); ?>
			<a class="button" href="<?php echo esc_url( Menu::url( self::SLUG, array( 'discard' => '1' ) ) ); ?>">
				<?php esc_html_e( 'Throw it away', 'oxysuppliers-for-woocommerce' ); ?>
			</a>
		</form>
		<?php
	}

	/**
	 * What a set of actions amounts to, in words.
	 *
	 * @param list<string> $actions The actions.
	 * @return string
	 */
	private function describe( array $actions ): string {
		$words = array();

		foreach ( $actions as $action ) {
			$words[] = match ( $action ) {
				PriceListImporter::CREATE_SUPPLIER => __( 'new supplier', 'oxysuppliers-for-woocommerce' ),
				PriceListImporter::USE_SUPPLIER => __( 'supplier already known', 'oxysuppliers-for-woocommerce' ),
				PriceListImporter::CREATE_LINE => __( 'price added', 'oxysuppliers-for-woocommerce' ),
				PriceListImporter::UPDATE_LINE => __( 'price updated', 'oxysuppliers-for-woocommerce' ),
				default => '',
			};
		}

		return implode( ', ', array_filter( $words ) );
	}

	/**
	 * Take the file and show what it would do.
	 *
	 * @return void
	 */
	public function handle_upload(): void {
		check_admin_referer( self::UPLOAD_ACTION );

		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to import suppliers.', 'oxysuppliers-for-woocommerce' ), 403 );
		}

		// An upload is an array of parts, and each part is cleaned on the lines
		// below before anything looks at it. There is nothing a single
		// sanitising call could do to the array as a whole.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked above; each part is sanitised individually below.
		$upload = isset( $_FILES['oxysuppliers_csv'] ) ? wp_unslash( $_FILES['oxysuppliers_csv'] ) : array();

		$path = isset( $upload['tmp_name'] ) ? sanitize_text_field( (string) $upload['tmp_name'] ) : '';
		$name = isset( $upload['name'] ) ? sanitize_file_name( (string) $upload['name'] ) : '';

		if ( '' === $path || ! is_uploaded_file( $path ) ) {
			$this->redirect( 'no_file' );
		}

		// A name that does not end in .csv is refused rather than sniffed: this
		// screen reads text, and a file pretending to be text is a file
		// somebody chose badly.
		$type = wp_check_filetype(
			$name,
			array(
				'csv' => 'text/csv',
				'txt' => 'text/plain',
			)
		);

		if ( ! isset( $type['ext'] ) || ! in_array( $type['ext'], array( 'csv', 'txt' ), true ) ) {
			$this->redirect( 'not_csv' );
		}

		if ( (int) ( $upload['size'] ?? 0 ) > 5 * MB_IN_BYTES ) {
			$this->redirect( 'too_big' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the upload PHP has already put on disk; WP_Filesystem has nothing to add.
		$contents = file_get_contents( $path );

		if ( false === $contents ) {
			$this->redirect( 'no_file' );
		}

		$file = $this->parser->parse( $contents );

		if ( ! $file->is_usable() ) {
			set_transient( $this->key() . '_problems', $file->problems, MINUTE_IN_SECONDS * 5 );
			$this->redirect( 'unreadable' );
		}

		if ( count( $file->rows ) > self::MAX_ROWS ) {
			$this->redirect( 'too_many' );
		}

		// The file itself is not kept: what is kept is what was read out of it,
		// and only until it has been looked at.
		set_transient(
			$this->key(),
			array(
				'header' => $file->header,
				'rows'   => $file->rows,
			),
			HOUR_IN_SECONDS
		);

		$this->redirect( '' );
	}

	/**
	 * Do what the preview said.
	 *
	 * @return void
	 */
	public function handle_confirm(): void {
		check_admin_referer( self::CONFIRM_ACTION );

		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to import suppliers.', 'oxysuppliers-for-woocommerce' ), 403 );
		}

		$pending = get_transient( $this->key() );

		// Nothing to do without a preview: the button cannot be reached
		// without one, and neither can the address.
		if ( ! is_array( $pending ) || ! isset( $pending['rows'] ) ) {
			$this->redirect( 'nothing_pending' );
		}

		$done = $this->importer->apply( new ParsedCsv( $pending['header'] ?? array(), $pending['rows'], array() ) );

		delete_transient( $this->key() );

		set_transient( $this->key() . '_done', $done, MINUTE_IN_SECONDS * 5 );

		$this->redirect( 'done' );
	}

	/**
	 * Where the pending import is kept.
	 *
	 * @return string
	 */
	private function key(): string {
		return 'oxysuppliers_import_' . get_current_user_id();
	}

	/**
	 * Show whatever the last step left behind.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Displays a fixed message, or throws away this user's own pending import.
		if ( isset( $_GET['discard'] ) ) {
			delete_transient( $this->key() );
		}

		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'done' === $notice ) {
			$done = get_transient( $this->key() . '_done' );

			delete_transient( $this->key() . '_done' );

			if ( is_array( $done ) ) {
				printf(
					'<div class="notice notice-success"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: 1: suppliers created, 2: price list lines written, 3: rows skipped. */
							__( 'Done: %1$d suppliers created, %2$d price list lines written, %3$d rows skipped.', 'oxysuppliers-for-woocommerce' ),
							(int) $done['suppliers'],
							(int) $done['lines'],
							(int) $done['skipped']
						)
					)
				);
			}

			return;
		}

		$messages = array(
			'no_file'         => __( 'No file arrived.', 'oxysuppliers-for-woocommerce' ),
			'not_csv'         => __( 'That is not a CSV file.', 'oxysuppliers-for-woocommerce' ),
			'too_big'         => __( 'That file is larger than five megabytes.', 'oxysuppliers-for-woocommerce' ),
			'too_many'        => __( 'That file has more rows than anybody is going to read in a preview. Split it up.', 'oxysuppliers-for-woocommerce' ),
			'nothing_pending' => __( 'There is nothing waiting to be imported.', 'oxysuppliers-for-woocommerce' ),
		);

		if ( 'unreadable' === $notice ) {
			$problems = get_transient( $this->key() . '_problems' );

			delete_transient( $this->key() . '_problems' );

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( is_array( $problems ) ? implode( ' ', $problems ) : __( 'That file could not be read.', 'oxysuppliers-for-woocommerce' ) )
			);

			return;
		}

		if ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $messages[ $notice ] ) );
		}
	}

	/**
	 * Back to the tab.
	 *
	 * @param string $notice Notice key, or empty.
	 * @return never
	 */
	private function redirect( string $notice ) {
		wp_safe_redirect( Menu::url( self::SLUG, '' === $notice ? array() : array( 'notice' => $notice ) ) );
		exit;
	}
}
