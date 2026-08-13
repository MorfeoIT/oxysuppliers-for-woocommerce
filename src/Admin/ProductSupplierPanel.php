<?php
/**
 * The Suppliers panel on a product.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Admin;

use Oxysoft\OxySuppliers\Domain\Supplier;
use Oxysoft\OxySuppliers\Domain\SupplierProduct;
use Oxysoft\OxySuppliers\Domain\SupplierProductValidator;
use Oxysoft\OxySuppliers\Persistence\PurchaseOrderRepository;
use Oxysoft\OxySuppliers\Persistence\SupplierProductRepository;
use Oxysoft\OxySuppliers\Persistence\SupplierRepository;
use Oxysoft\OxySuppliers\Service\AuditLogger;
use Oxysoft\OxySuppliers\Support\Capabilities;

/**
 * Who sells this, for how much, and in what quantities.
 *
 * Deliberately without a line of JavaScript. The panel draws the lines that
 * exist plus one empty one, so adding a supplier is filling it in and pressing
 * the button the shop already presses. A row is removed by ticking a box, not
 * by a script that removes it from a page that has not been saved yet.
 */
final class ProductSupplierPanel {

	/**
	 * Where a failed save leaves its complaints.
	 */
	private const NOTICE_KEY = 'oxysuppliers_product_notice_';

	/**
	 * Take the collaborators.
	 *
	 * @param SupplierProductRepository $lines     Price list storage.
	 * @param SupplierRepository        $suppliers Supplier storage.
	 * @param SupplierProductValidator  $validator The rules.
	 * @param AuditLogger               $audit     The trail.
	 * @param PurchaseOrderRepository   $orders    For what is on its way.
	 */
	public function __construct(
		private readonly SupplierProductRepository $lines,
		private readonly SupplierRepository $suppliers,
		private readonly SupplierProductValidator $validator,
		private readonly AuditLogger $audit,
		private readonly PurchaseOrderRepository $orders
	) {
	}

	/**
	 * Hook into the product screen.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product' ) );

		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'render_variation' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation' ), 10, 2 );

		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Add the tab.
	 *
	 * @param array<string,array<string,mixed>> $tabs Existing tabs.
	 * @return array<string,array<string,mixed>>
	 */
	public function add_tab( $tabs ): array {
		if ( ! is_array( $tabs ) || ! current_user_can( Capabilities::MANAGE_SUPPLIERS ) ) {
			return is_array( $tabs ) ? $tabs : array();
		}

		$tabs['oxysuppliers'] = array(
			'label'    => __( 'Suppliers', 'oxysuppliers-for-woocommerce' ),
			'target'   => 'oxysuppliers_product_data',
			'class'    => array(),
			'priority' => 65,
		);

		return $tabs;
	}

	/**
	 * Draw the panel for the product itself.
	 *
	 * @return void
	 */
	public function render_panel(): void {
		global $post;

		if ( ! current_user_can( Capabilities::MANAGE_SUPPLIERS ) || ! $post instanceof \WP_Post ) {
			return;
		}

		echo '<div id="oxysuppliers_product_data" class="panel woocommerce_options_panel hidden">';
		echo '<div class="options_group oxysuppliers-panel">';

		wp_nonce_field( 'oxysuppliers_save_product_lines', 'oxysuppliers_product_nonce' );

		$product = wc_get_product( $post->ID );

		if ( $product instanceof \WC_Product && $product->is_type( 'variable' ) ) {
			printf(
				'<p class="form-field"><em>%s</em></p>',
				esc_html__( 'This product has variations, and each one is bought separately. Set the suppliers on the Variations tab.', 'oxysuppliers-for-woocommerce' )
			);
		}

		$this->render_table( (int) $post->ID, 0, 'oxysuppliers_lines' );

		echo '</div></div>';
	}

	/**
	 * Draw the panel for one variation.
	 *
	 * @param int                 $loop           Index of the variation in the list.
	 * @param array<string,mixed> $variation_data Variation meta, unused.
	 * @param \WP_Post            $variation      The variation post.
	 * @return void
	 */
	public function render_variation( $loop, $variation_data, $variation ): void {
		if ( ! current_user_can( Capabilities::MANAGE_SUPPLIERS ) || ! $variation instanceof \WP_Post ) {
			return;
		}

		echo '<div class="oxysuppliers-variation-panel" style="clear:both;padding-top:8px;">';
		printf( '<h4>%s</h4>', esc_html__( 'Suppliers', 'oxysuppliers-for-woocommerce' ) );

		$this->render_table(
			(int) $variation->post_parent,
			(int) $variation->ID,
			'oxysuppliers_variation_lines[' . (int) $loop . ']'
		);

		echo '</div>';
	}

	/**
	 * The table of price list lines for one article.
	 *
	 * @param int    $product_id   Parent product.
	 * @param int    $variation_id Variation, 0 for the product itself.
	 * @param string $field_prefix Name prefix for the inputs.
	 * @return void
	 */
	private function render_table( int $product_id, int $variation_id, string $field_prefix ): void {
		$lines     = $this->lines->for_item( $product_id, $variation_id );
		$suppliers = $this->suppliers->paginate(
			array(
				'per_page' => 200,
				'orderby'  => 'company_name',
			)
		);

		if ( array() === $suppliers ) {
			printf(
				'<p class="form-field">%s <a href="%s">%s</a></p>',
				esc_html__( 'No suppliers yet.', 'oxysuppliers-for-woocommerce' ),
				esc_url( Menu::url( SuppliersScreen::SLUG, array( 'action' => 'new' ) ) ),
				esc_html__( 'Add the first one.', 'oxysuppliers-for-woocommerce' )
			);

			return;
		}

		$this->render_incoming( $product_id, $variation_id );

		$preferred_field = $field_prefix . '[preferred]';
		$chosen          = 0;

		foreach ( $lines as $line ) {
			if ( $line->is_preferred ) {
				$chosen = $line->id;
			}
		}

		?>
		<table class="widefat striped oxysuppliers-lines">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Supplier', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Their code', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Cost', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Minimum', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Multiple', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Per pack', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Lead time', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Preferred', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Remove', 'oxysuppliers-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $lines as $line ) : ?>
				<?php $this->render_row( $field_prefix, (string) $line->id, $suppliers, $line, $preferred_field, $chosen ); ?>
			<?php endforeach; ?>

			<?php $this->render_row( $field_prefix, 'new', $suppliers, null, $preferred_field, $chosen ); ?>
			</tbody>
		</table>

		<p class="description">
			<?php esc_html_e( 'Fill in the empty line to add a supplier. Quantities are rounded up to satisfy the minimum, the multiple and the pack size together.', 'oxysuppliers-for-woocommerce' ); ?>
		</p>

		<?php if ( array() !== $lines ) : ?>
			<p class="description">
				<label>
					<input type="radio" name="<?php echo esc_attr( $preferred_field ); ?>" value="0" <?php checked( 0, $chosen ); ?>>
					<?php esc_html_e( 'No preferred supplier: use the cheapest.', 'oxysuppliers-for-woocommerce' ); ?>
				</label>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * What is on the shelf, what is on its way, and when it should land.
	 *
	 * The one line somebody looking at a product actually wants (§15). Shown
	 * only when something is genuinely coming: a line saying "on the way: 0" is
	 * noise.
	 *
	 * @param int $product_id   Parent product.
	 * @param int $variation_id Variation, 0 for the product itself.
	 * @return void
	 */
	private function render_incoming( int $product_id, int $variation_id ): void {
		$incoming = $this->orders->incoming_for_item( $product_id, $variation_id );

		if ( $incoming['quantity'] <= 0 ) {
			return;
		}

		$product = wc_get_product( 0 !== $variation_id ? $variation_id : $product_id );
		$stock   = $product instanceof \WC_Product && $product->managing_stock()
			? (string) (int) $product->get_stock_quantity()
			: __( 'not tracked', 'oxysuppliers-for-woocommerce' );

		printf(
			'<p class="form-field"><strong>%1$s</strong> %2$s &nbsp;|&nbsp; <strong>%3$s</strong> %4$d%5$s</p>',
			esc_html__( 'Stock:', 'oxysuppliers-for-woocommerce' ),
			esc_html( $stock ),
			esc_html__( 'On the way:', 'oxysuppliers-for-woocommerce' ),
			(int) $incoming['quantity'],
			null === $incoming['eta']
				? ''
				: ' &nbsp;|&nbsp; <strong>' . esc_html__( 'Expected:', 'oxysuppliers-for-woocommerce' ) . '</strong> ' . esc_html( $incoming['eta'] )
		);
	}

	/**
	 * One row of the table.
	 *
	 * @param string               $prefix          Field name prefix.
	 * @param string               $key             Row key: the id, or "new".
	 * @param list<Supplier>       $suppliers       Suppliers to choose from.
	 * @param SupplierProduct|null $line            Existing line, or null for the empty row.
	 * @param string               $preferred_field Name of the preferred radio group.
	 * @param int                  $chosen          Id of the currently preferred line.
	 * @return void
	 */
	private function render_row( string $prefix, string $key, array $suppliers, ?SupplierProduct $line, string $preferred_field, int $chosen ): void {
		$name = $prefix . '[rows][' . $key . ']';

		?>
		<tr>
			<td>
				<select name="<?php echo esc_attr( $name . '[supplier_id]' ); ?>">
					<option value="0"><?php esc_html_e( '— Select —', 'oxysuppliers-for-woocommerce' ); ?></option>
					<?php foreach ( $suppliers as $supplier ) : ?>
						<option value="<?php echo esc_attr( (string) $supplier->id ); ?>" <?php selected( $supplier->id, null === $line ? 0 : $line->supplier_id ); ?>>
							<?php echo esc_html( $supplier->display_name() . ( $supplier->is_active() ? '' : ' (' . __( 'inactive', 'oxysuppliers-for-woocommerce' ) . ')' ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<input type="text" size="12" name="<?php echo esc_attr( $name . '[supplier_sku]' ); ?>"
					value="<?php echo esc_attr( null === $line ? '' : $line->supplier_sku ); ?>">
			</td>
			<td>
				<input type="text" size="8" name="<?php echo esc_attr( $name . '[unit_cost]' ); ?>"
					value="<?php echo esc_attr( null === $line ? '' : $line->unit_cost->to_decimal() ); ?>">
			</td>
			<td>
				<input type="number" min="0" step="1" size="5" name="<?php echo esc_attr( $name . '[min_order_qty]' ); ?>"
					value="<?php echo esc_attr( null === $line ? '' : (string) $line->terms->minimum ); ?>">
			</td>
			<td>
				<input type="number" min="1" step="1" size="5" name="<?php echo esc_attr( $name . '[order_multiple]' ); ?>"
					value="<?php echo esc_attr( null === $line ? '' : (string) $line->terms->multiple ); ?>">
			</td>
			<td>
				<input type="number" min="1" step="1" size="5" name="<?php echo esc_attr( $name . '[pack_qty]' ); ?>"
					value="<?php echo esc_attr( null === $line ? '' : (string) $line->terms->pack ); ?>">
			</td>
			<td>
				<input type="number" min="0" step="1" size="5" name="<?php echo esc_attr( $name . '[lead_time_days]' ); ?>"
					value="<?php echo esc_attr( null === $line ? '' : (string) $line->lead_time_days ); ?>">
			</td>
			<td>
				<?php if ( null !== $line ) : ?>
					<input type="radio" name="<?php echo esc_attr( $preferred_field ); ?>"
						value="<?php echo esc_attr( (string) $line->id ); ?>" <?php checked( $line->id, $chosen ); ?>>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( null !== $line ) : ?>
					<input type="checkbox" name="<?php echo esc_attr( $name . '[remove]' ); ?>" value="1">
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save the lines attached to a product.
	 *
	 * @param int $product_id Product being saved.
	 * @return void
	 */
	public function save_product( $product_id ): void {
		if ( ! $this->may_save() ) {
			return;
		}

		// A nested array of rows, so every leaf is cleaned at once with
		// map_deep() before anything looks at it. The row is then read field by
		// field, which is where the types are settled.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in may_save().
		$submitted = isset( $_POST['oxysuppliers_lines'] ) ? map_deep( wp_unslash( $_POST['oxysuppliers_lines'] ), 'sanitize_text_field' ) : array();

		$this->save_lines( (int) $product_id, 0, is_array( $submitted ) ? $submitted : array() );
	}

	/**
	 * Save the lines attached to one variation.
	 *
	 * @param int $variation_id Variation being saved.
	 * @param int $loop         Its index in the submitted list.
	 * @return void
	 */
	public function save_variation( $variation_id, $loop ): void {
		if ( ! $this->may_save() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in may_save().
		$all = isset( $_POST['oxysuppliers_variation_lines'] ) ? map_deep( wp_unslash( $_POST['oxysuppliers_variation_lines'] ), 'sanitize_text_field' ) : array();

		if ( ! is_array( $all ) || ! isset( $all[ $loop ] ) || ! is_array( $all[ $loop ] ) ) {
			return;
		}

		$variation = wc_get_product( (int) $variation_id );
		$parent    = $variation instanceof \WC_Product_Variation ? $variation->get_parent_id() : 0;

		if ( 0 === $parent ) {
			return;
		}

		$this->save_lines( $parent, (int) $variation_id, $all[ $loop ] );
	}

	/**
	 * Whether this request is allowed to write price list lines.
	 *
	 * @return bool
	 */
	private function may_save(): bool {
		if ( ! current_user_can( Capabilities::MANAGE_SUPPLIERS ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is the nonce check.
		$nonce = isset( $_POST['oxysuppliers_product_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['oxysuppliers_product_nonce'] ) ) : '';

		return false !== wp_verify_nonce( $nonce, 'oxysuppliers_save_product_lines' );
	}

	/**
	 * Write one article's lines.
	 *
	 * @param int                 $product_id   Parent product.
	 * @param int                 $variation_id Variation, 0 for the product itself.
	 * @param array<string,mixed> $submitted   The submitted block for this article.
	 * @return void
	 */
	private function save_lines( int $product_id, int $variation_id, array $submitted ): void {
		$rows = isset( $submitted['rows'] ) && is_array( $submitted['rows'] ) ? $submitted['rows'] : array();

		$errors  = array();
		$written = array();

		foreach ( $rows as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$id          = is_numeric( $key ) ? (int) $key : 0;
			$supplier_id = (int) ( $row['supplier_id'] ?? 0 );

			if ( $id > 0 && ! empty( $row['remove'] ) ) {
				$existing = $this->lines->find( $id );

				if ( null !== $existing && $existing->product_id === $product_id && $existing->variation_id === $variation_id ) {
					$this->lines->delete( $id );
					$this->audit->log(
						AuditLogger::OBJECT_SUPPLIER,
						(string) $existing->supplier_id,
						AuditLogger::ACTION_DELETED,
						array( 'price_list_line' => $id ),
						null
					);
				}

				continue;
			}

			// An empty row is somebody who did not add a supplier, not an error.
			if ( $supplier_id <= 0 ) {
				continue;
			}

			$supplier = $this->suppliers->find( $supplier_id );

			if ( null === $supplier ) {
				continue;
			}

			$fields = $this->clean_row( $row, $supplier, $product_id, $variation_id );
			$found  = $this->validator->validate( $fields );

			if ( array() !== $found ) {
				$errors[] = sprintf(
					/* translators: %s: supplier name. */
					__( 'The line for %s was not saved: check the cost and the quantities.', 'oxysuppliers-for-woocommerce' ),
					$supplier->display_name()
				);

				continue;
			}

			$saved = $this->lines->save( SupplierProduct::from_fields( $fields, $id ) );

			if ( $saved > 0 ) {
				$written[ (string) $key ] = $saved;
			}
		}

		$this->apply_preferred( $submitted, $product_id, $variation_id, $written );

		if ( array() !== $errors ) {
			set_transient( self::NOTICE_KEY . get_current_user_id(), $errors, MINUTE_IN_SECONDS * 5 );
		}
	}

	/**
	 * Set, move or clear the preferred supplier for an article.
	 *
	 * @param array<string,mixed> $submitted The submitted block.
	 * @param int                 $product_id   Parent product.
	 * @param int                 $variation_id Variation, 0 for the product itself.
	 * @param array<string,int>   $written      Row key to stored id, for rows just created.
	 * @return void
	 */
	private function apply_preferred( array $submitted, int $product_id, int $variation_id, array $written ): void {
		if ( ! isset( $submitted['preferred'] ) ) {
			return;
		}

		$chosen = (string) $submitted['preferred'];

		if ( '0' === $chosen || '' === $chosen ) {
			$this->lines->clear_preferred( $product_id, $variation_id );

			return;
		}

		$id = $written[ $chosen ] ?? (int) $chosen;
		$of = $this->lines->find( $id );

		// A choice only counts for the article it was made on: an id from
		// somewhere else must not move another product's preferred supplier.
		if ( null === $of || $of->product_id !== $product_id || $of->variation_id !== $variation_id ) {
			return;
		}

		$this->lines->set_preferred( $id );
	}

	/**
	 * Clean one submitted row into the fields the domain expects.
	 *
	 * The currency is the supplier's, never the form's: a price list is written
	 * in the money the supplier invoices in, and asking twice is asking for the
	 * two answers to disagree.
	 *
	 * @param array<string,mixed> $row          Raw row.
	 * @param Supplier            $supplier     The supplier it belongs to.
	 * @param int                 $product_id   Parent product.
	 * @param int                 $variation_id Variation, 0 for the product itself.
	 * @return array<string,mixed>
	 */
	private function clean_row( array $row, Supplier $supplier, int $product_id, int $variation_id ): array {
		$text = static fn ( $value ): string => sanitize_text_field( (string) ( is_scalar( $value ) ? $value : '' ) );

		$cost = $text( $row['unit_cost'] ?? '' );

		if ( '' !== $cost ) {
			$canonical = (string) wc_format_decimal( $cost, false, true );
			$cost      = '' === $canonical ? $cost : $canonical;
		}

		return array(
			'supplier_id'          => $supplier->id,
			'product_id'           => $product_id,
			'variation_id'         => $variation_id,
			'supplier_sku'         => $text( $row['supplier_sku'] ?? '' ),
			'supplier_description' => $text( $row['supplier_description'] ?? '' ),
			'currency'             => $supplier->currency(),
			'unit_cost'            => $cost,
			'min_order_qty'        => $text( $row['min_order_qty'] ?? '' ),
			'order_multiple'       => '' === $text( $row['order_multiple'] ?? '' ) ? '1' : $text( $row['order_multiple'] ),
			'pack_qty'             => '' === $text( $row['pack_qty'] ?? '' ) ? '1' : $text( $row['pack_qty'] ),
			'lead_time_days'       => '' === $text( $row['lead_time_days'] ?? '' ) ? (string) $supplier->lead_time_days : $text( $row['lead_time_days'] ),
			'notes'                => '',
		);
	}

	/**
	 * Show whatever the last save complained about.
	 *
	 * @return void
	 */
	public function render_notice(): void {
		$key      = self::NOTICE_KEY . get_current_user_id();
		$messages = get_transient( $key );

		if ( ! is_array( $messages ) || array() === $messages ) {
			return;
		}

		delete_transient( $key );

		echo '<div class="notice notice-error"><p>';
		echo esc_html( implode( ' ', array_map( 'strval', $messages ) ) );
		echo '</p></div>';
	}
}
