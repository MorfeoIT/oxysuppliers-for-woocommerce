<?php
/**
 * The purchase orders tab.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Admin;

use Oxysoft\OxySuppliers\Domain\ExpectedDate;
use Oxysoft\OxySuppliers\Domain\InvalidTransition;
use Oxysoft\OxySuppliers\Domain\Money;
use Oxysoft\OxySuppliers\Domain\PurchaseOrder;
use Oxysoft\OxySuppliers\Domain\PurchaseOrderLine;
use Oxysoft\OxySuppliers\Domain\PurchaseOrderStatus;
use Oxysoft\OxySuppliers\Persistence\PurchaseOrderRepository;
use Oxysoft\OxySuppliers\Persistence\ReceiptRepository;
use Oxysoft\OxySuppliers\Persistence\SupplierProductRepository;
use Oxysoft\OxySuppliers\Persistence\SupplierRepository;
use Oxysoft\OxySuppliers\Pdf\PdfRenderer;
use Oxysoft\OxySuppliers\Pdf\PurchaseOrderDocument;
use Oxysoft\OxySuppliers\Service\AuditLogger;
use Oxysoft\OxySuppliers\Service\GoodsReceiver;
use Oxysoft\OxySuppliers\Service\PurchaseOrderMailer;
use Oxysoft\OxySuppliers\Service\ReceiptOutcome;
use Oxysoft\OxySuppliers\Support\Capabilities;
use Oxysoft\OxySuppliers\Support\Settings;

/**
 * Purchase orders: the list, the document, and the buttons that move it on.
 *
 * The buttons are drawn from what the state machine allows, rather than drawn
 * always and checked later. A move the domain forbids never gets an button, and
 * if one is reached anyway — an old page, a hand-typed address — the domain
 * refuses it and the screen says so.
 */
final class PurchaseOrdersScreen implements Screen {

	public const SLUG = 'orders';

	public const CREATE_ACTION  = 'oxysuppliers_create_order';
	public const SAVE_ACTION    = 'oxysuppliers_save_order';
	public const STATUS_ACTION  = 'oxysuppliers_order_status';
	public const PDF_ACTION     = 'oxysuppliers_order_pdf';
	public const SEND_ACTION    = 'oxysuppliers_send_order';
	public const RECEIVE_ACTION = 'oxysuppliers_receive_order';
	public const REVERSE_ACTION = 'oxysuppliers_reverse_receipt';

	/**
	 * How many orders a page holds.
	 */
	private const PER_PAGE = 20;

	/**
	 * Supplier names, keyed by id, fetched once.
	 *
	 * @var array<int,string>|null
	 */
	private ?array $supplier_names = null;

	/**
	 * Take the collaborators.
	 *
	 * @param PurchaseOrderRepository   $orders    Storage for orders.
	 * @param SupplierRepository        $suppliers Storage for suppliers.
	 * @param SupplierProductRepository $listings  Storage for price lists.
	 * @param AuditLogger               $audit     The trail.
	 * @param PurchaseOrderDocument     $document  Builds the document.
	 * @param PdfRenderer               $renderer  Turns it into a PDF.
	 * @param PurchaseOrderMailer       $mailer    Sends it.
	 * @param ReceiptRepository         $receipts  Storage for receipts.
	 * @param GoodsReceiver             $receiver  Records what arrives.
	 */
	public function __construct(
		private readonly PurchaseOrderRepository $orders,
		private readonly SupplierRepository $suppliers,
		private readonly SupplierProductRepository $listings,
		private readonly AuditLogger $audit,
		private readonly PurchaseOrderDocument $document,
		private readonly PdfRenderer $renderer,
		private readonly PurchaseOrderMailer $mailer,
		private readonly ReceiptRepository $receipts,
		private readonly GoodsReceiver $receiver
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
		return __( 'Purchase orders', 'oxysuppliers-for-woocommerce' );
	}

	/**
	 * Capability required to open it.
	 *
	 * @return string
	 */
	public function capability(): string {
		return Capabilities::VIEW_ORDERS;
	}

	/**
	 * Hook up the form handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::CREATE_ACTION, array( $this, 'handle_create' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::STATUS_ACTION, array( $this, 'handle_status' ) );
		add_action( 'admin_post_' . self::PDF_ACTION, array( $this, 'handle_pdf' ) );
		add_action( 'admin_post_' . self::SEND_ACTION, array( $this, 'handle_send' ) );
		add_action( 'admin_post_' . self::RECEIVE_ACTION, array( $this, 'handle_receive' ) );
		add_action( 'admin_post_' . self::REVERSE_ACTION, array( $this, 'handle_reverse' ) );
	}

	/**
	 * Draw whichever view was asked for.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to see purchase orders.', 'oxysuppliers-for-woocommerce' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only navigation; every write goes through admin-post.php with its own nonce.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$id     = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->render_notice();

		if ( 'new' === $action ) {
			$this->render_new_form();

			return;
		}

		if ( 'view' === $action ) {
			$order = $this->orders->find( $id );

			if ( null === $order ) {
				$this->render_missing();

				return;
			}

			$this->render_order( $order );

			return;
		}

		$this->render_list();
	}

	/**
	 * The list of orders.
	 *
	 * @return void
	 */
	private function render_list(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only listing.
		$search      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$supplier_id = isset( $_GET['supplier_id'] ) ? absint( wp_unslash( $_GET['supplier_id'] ) ) : 0;
		$status      = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$overdue     = isset( $_GET['overdue'] );
		$page        = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$query = array(
			'search'      => $search,
			'supplier_id' => $supplier_id,
			'status'      => '' === $status ? array() : array( $status ),
			'overdue'     => $overdue,
			'per_page'    => self::PER_PAGE,
			'page'        => $page,
		);

		$orders = $this->orders->paginate( $query );
		$total  = $this->orders->count( $query );
		$pages  = (int) ceil( $total / self::PER_PAGE );

		$totals = $this->orders->totals_for( array_map( static fn ( PurchaseOrder $one ): int => $one->id, $orders ) );

		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Purchase orders', 'oxysuppliers-for-woocommerce' ); ?></h1>
		<?php if ( current_user_can( Capabilities::CREATE_ORDERS ) ) : ?>
			<a href="<?php echo esc_url( Menu::url( self::SLUG, array( 'action' => 'new' ) ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'New purchase order', 'oxysuppliers-for-woocommerce' ); ?>
			</a>
		<?php endif; ?>
		<hr class="wp-header-end">

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( Menu::SLUG ); ?>">
			<input type="hidden" name="tab" value="<?php echo esc_attr( self::SLUG ); ?>">

			<p class="search-box">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>"
					placeholder="<?php esc_attr_e( 'Number or reference', 'oxysuppliers-for-woocommerce' ); ?>">

				<select name="supplier_id">
					<option value="0"><?php esc_html_e( 'Any supplier', 'oxysuppliers-for-woocommerce' ); ?></option>
					<?php foreach ( $this->supplier_list() as $supplier_key => $supplier_label ) : ?>
						<option value="<?php echo esc_attr( (string) $supplier_key ); ?>" <?php selected( $supplier_key, $supplier_id ); ?>>
							<?php echo esc_html( $supplier_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<select name="status">
					<option value=""><?php esc_html_e( 'Any status', 'oxysuppliers-for-woocommerce' ); ?></option>
					<?php foreach ( PurchaseOrderStatus::cases() as $case ) : ?>
						<option value="<?php echo esc_attr( $case->value ); ?>" <?php selected( $case->value, $status ); ?>>
							<?php echo esc_html( $this->status_label( $case ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label>
					<input type="checkbox" name="overdue" value="1" <?php checked( $overdue ); ?>>
					<?php esc_html_e( 'Late', 'oxysuppliers-for-woocommerce' ); ?>
				</label>

				<?php submit_button( __( 'Filter', 'oxysuppliers-for-woocommerce' ), '', '', false ); ?>
			</p>
		</form>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Number', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Supplier', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Ordered', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Expected', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Total', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'oxysuppliers-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( array() === $orders ) : ?>
				<tr>
					<td colspan="6">
						<?php esc_html_e( 'No purchase order yet. Start one from the reordering screen, or add one by hand.', 'oxysuppliers-for-woocommerce' ); ?>
					</td>
				</tr>
			<?php endif; ?>

			<?php foreach ( $orders as $order ) : ?>
				<tr>
					<td>
						<strong>
							<a href="
							<?php
							echo esc_url(
								Menu::url(
									self::SLUG,
									array(
										'action' => 'view',
										'id'     => $order->id,
									)
								)
							);
							?>
										">
								<?php echo esc_html( $order->number ); ?>
							</a>
						</strong>
					</td>
					<td><?php echo esc_html( $this->supplier_name( $order->supplier_id ) ); ?></td>
					<td><?php echo esc_html( $order->order_date ); ?></td>
					<td>
						<?php echo esc_html( (string) $order->expected_date ); ?>
						<?php if ( $this->is_late( $order ) ) : ?>
							<span class="oxysuppliers-status is-out"><?php esc_html_e( 'late', 'oxysuppliers-for-woocommerce' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php
						echo esc_html(
							Money::from_minor( $totals[ $order->id ] ?? 0, $order->currency )->to_decimal()
							. ' ' . $order->currency
						);
						?>
					</td>
					<td><?php $this->render_status_label( $order->status ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'    => add_query_arg( 'paged', '%#%' ),
								'format'  => '',
								'total'   => $pages,
								'current' => $page,
							)
						) ?? ''
					);
					?>
				</div>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * The form that starts a new order.
	 *
	 * @return void
	 */
	private function render_new_form(): void {
		if ( ! current_user_can( Capabilities::CREATE_ORDERS ) ) {
			wp_die( esc_html__( 'You are not allowed to create purchase orders.', 'oxysuppliers-for-woocommerce' ), 403 );
		}

		$suppliers = $this->supplier_list();

		?>
		<h1><?php esc_html_e( 'New purchase order', 'oxysuppliers-for-woocommerce' ); ?></h1>

		<?php if ( array() === $suppliers ) : ?>
			<p>
				<?php esc_html_e( 'There are no suppliers yet.', 'oxysuppliers-for-woocommerce' ); ?>
				<a href="<?php echo esc_url( Menu::url( SuppliersScreen::SLUG, array( 'action' => 'new' ) ) ); ?>">
					<?php esc_html_e( 'Add the first one.', 'oxysuppliers-for-woocommerce' ); ?>
				</a>
			</p>
			<?php
			return;
		endif;
		?>

		<p class="description">
			<?php esc_html_e( 'Choose who it is for. The articles are added on the next screen, from that supplier\'s price list.', 'oxysuppliers-for-woocommerce' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::CREATE_ACTION ); ?>">
			<?php wp_nonce_field( self::CREATE_ACTION ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="oxysuppliers-po-supplier"><?php esc_html_e( 'Supplier', 'oxysuppliers-for-woocommerce' ); ?></label></th>
						<td>
							<select id="oxysuppliers-po-supplier" name="supplier_id">
								<?php foreach ( $suppliers as $supplier_key => $supplier_label ) : ?>
									<option value="<?php echo esc_attr( (string) $supplier_key ); ?>"><?php echo esc_html( $supplier_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="oxysuppliers-po-date"><?php esc_html_e( 'Order date', 'oxysuppliers-for-woocommerce' ); ?></label></th>
						<td>
							<input type="date" id="oxysuppliers-po-date" name="order_date" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="oxysuppliers-po-expected"><?php esc_html_e( 'Expected', 'oxysuppliers-for-woocommerce' ); ?></label></th>
						<td>
							<input type="date" id="oxysuppliers-po-expected" name="expected_date" value="">
							<p class="description"><?php esc_html_e( 'Left empty, it is worked out from the supplier\'s lead time.', 'oxysuppliers-for-woocommerce' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<p class="submit">
				<?php submit_button( __( 'Start the order', 'oxysuppliers-for-woocommerce' ), 'primary', 'submit', false ); ?>
				<a class="button" href="<?php echo esc_url( Menu::url( self::SLUG ) ); ?>"><?php esc_html_e( 'Cancel', 'oxysuppliers-for-woocommerce' ); ?></a>
			</p>
		</form>
		<?php
	}

	/**
	 * One order.
	 *
	 * @param PurchaseOrder $order The order.
	 * @return void
	 */
	private function render_order( PurchaseOrder $order ): void {
		$editable = $order->status->is_editable() && current_user_can( Capabilities::CREATE_ORDERS );

		?>
		<h1 class="wp-heading-inline">
			<?php echo esc_html( $order->number ); ?>
			&nbsp;<?php $this->render_status_label( $order->status ); ?>
		</h1>
		<a class="page-title-action" href="<?php echo esc_url( Menu::url( self::SLUG ) ); ?>">
			<?php esc_html_e( 'Back to the list', 'oxysuppliers-for-woocommerce' ); ?>
		</a>
		<hr class="wp-header-end">

		<p>
			<strong><?php echo esc_html( $this->supplier_name( $order->supplier_id ) ); ?></strong><br>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: order date, 2: expected date. */
					__( 'Ordered %1$s, expected %2$s', 'oxysuppliers-for-woocommerce' ),
					$order->order_date,
					(string) $order->expected_date
				)
			);
			?>
		</p>

		<?php $this->render_status_actions( $order ); ?>
		<?php $this->render_receiving( $order ); ?>
		<?php $this->render_send_box( $order ); ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $order->id ); ?>">
			<?php wp_nonce_field( self::SAVE_ACTION . '_' . $order->id ); ?>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Article', 'oxysuppliers-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Their code', 'oxysuppliers-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Ordered', 'oxysuppliers-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Received', 'oxysuppliers-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Unit cost', 'oxysuppliers-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Line total', 'oxysuppliers-for-woocommerce' ); ?></th>
						<?php if ( $editable ) : ?>
							<th><?php esc_html_e( 'Remove', 'oxysuppliers-for-woocommerce' ); ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
				<?php if ( array() === $order->lines ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'Nothing on this order yet.', 'oxysuppliers-for-woocommerce' ); ?></td></tr>
				<?php endif; ?>

				<?php foreach ( $order->lines as $line ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( '' !== $line->description ? $line->description : $line->sku ); ?></strong>
							<?php if ( '' !== $line->sku ) : ?>
								<div class="description"><?php echo esc_html( $line->sku ); ?></div>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $line->supplier_sku ); ?></td>
						<td>
							<?php if ( $editable ) : ?>
								<input type="number" min="0" step="1" size="5"
									name="lines[<?php echo esc_attr( (string) $line->id ); ?>][qty_ordered]"
									value="<?php echo esc_attr( (string) $line->qty_ordered ); ?>">
							<?php else : ?>
								<?php echo esc_html( (string) $line->qty_ordered ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) $line->qty_received ); ?></td>
						<td>
							<?php if ( $editable ) : ?>
								<input type="text" size="8"
									name="lines[<?php echo esc_attr( (string) $line->id ); ?>][unit_cost]"
									value="<?php echo esc_attr( $line->unit_cost->to_decimal() ); ?>">
							<?php else : ?>
								<?php echo esc_html( $line->unit_cost->to_decimal() ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $line->net_total()->to_decimal() ); ?></td>
						<?php if ( $editable ) : ?>
							<td>
								<input type="checkbox" name="lines[<?php echo esc_attr( (string) $line->id ); ?>][remove]" value="1">
							</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<th colspan="5" style="text-align:right;"><?php esc_html_e( 'Total', 'oxysuppliers-for-woocommerce' ); ?></th>
						<th><?php echo esc_html( $order->total()->to_decimal() . ' ' . $order->currency ); ?></th>
						<?php if ( $editable ) : ?>
							<th></th>
						<?php endif; ?>
					</tr>
				</tfoot>
			</table>

			<?php if ( $editable ) : ?>
				<?php $this->render_price_list( $order ); ?>

				<h2><?php esc_html_e( 'Details', 'oxysuppliers-for-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="oxysuppliers-po-expected-edit"><?php esc_html_e( 'Expected', 'oxysuppliers-for-woocommerce' ); ?></label></th>
							<td><input type="date" id="oxysuppliers-po-expected-edit" name="expected_date" value="<?php echo esc_attr( (string) $order->expected_date ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="oxysuppliers-po-reference"><?php esc_html_e( 'Their reference', 'oxysuppliers-for-woocommerce' ); ?></label></th>
							<td><input type="text" id="oxysuppliers-po-reference" class="regular-text" name="supplier_reference" value="<?php echo esc_attr( $order->supplier_reference ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="oxysuppliers-po-supplier-notes"><?php esc_html_e( 'Notes for the supplier', 'oxysuppliers-for-woocommerce' ); ?></label></th>
							<td><textarea id="oxysuppliers-po-supplier-notes" class="large-text" rows="3" name="supplier_notes"><?php echo esc_textarea( $order->supplier_notes ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="oxysuppliers-po-internal-notes"><?php esc_html_e( 'Notes for us', 'oxysuppliers-for-woocommerce' ); ?></label></th>
							<td><textarea id="oxysuppliers-po-internal-notes" class="large-text" rows="3" name="internal_notes"><?php echo esc_textarea( $order->internal_notes ); ?></textarea></td>
						</tr>
					</tbody>
				</table>

				<p class="submit">
					<?php submit_button( __( 'Save the order', 'oxysuppliers-for-woocommerce' ), 'primary', 'submit', false ); ?>
				</p>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * The supplier's price list, for adding articles without a search box.
	 *
	 * Everything the supplier sells that is not already on the order, with a
	 * quantity box. No article picker, no autocomplete, no JavaScript: the list
	 * of what they sell is already in the plugin, and showing it is less work
	 * for everybody than making somebody search it.
	 *
	 * @param PurchaseOrder $order The order.
	 * @return void
	 */
	private function render_price_list( PurchaseOrder $order ): void {
		$already = array();

		foreach ( $order->lines as $line ) {
			$already[ $line->product_id . ':' . $line->variation_id ] = true;
		}

		$available = array();

		foreach ( $this->listings->for_supplier( $order->supplier_id, 200 ) as $listing ) {
			if ( ! isset( $already[ $listing->product_id . ':' . $listing->variation_id ] ) ) {
				$available[] = $listing;
			}
		}

		if ( array() === $available ) {
			return;
		}

		?>
		<h2><?php esc_html_e( 'Add from the price list', 'oxysuppliers-for-woocommerce' ); ?></h2>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Article', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Their code', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Cost', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Quantity', 'oxysuppliers-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $available as $listing ) : ?>
				<?php $key = $listing->product_id . '_' . $listing->variation_id; ?>
				<tr>
					<td><?php echo esc_html( $this->article_name( $listing->product_id, $listing->variation_id ) ); ?></td>
					<td><?php echo esc_html( $listing->supplier_sku ); ?></td>
					<td><?php echo esc_html( $listing->unit_cost->to_decimal() ); ?></td>
					<td>
						<input type="number" min="0" step="1" size="5" name="add[<?php echo esc_attr( $key ); ?>]" value="">
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e( 'Fill in a quantity and save. Quantities are taken as they are typed here: the supplier\'s minimum and multiple are applied on the reordering screen, not to a line somebody has decided by hand.', 'oxysuppliers-for-woocommerce' ); ?>
		</p>
		<?php
	}

	/**
	 * The buttons that move an order on.
	 *
	 * Only the moves the state machine allows are drawn.
	 *
	 * @param PurchaseOrder $order The order.
	 * @return void
	 */
	private function render_status_actions( PurchaseOrder $order ): void {
		if ( ! current_user_can( Capabilities::CREATE_ORDERS ) ) {
			return;
		}

		$next = $order->status->allowed_next();

		if ( array() === $next ) {
			return;
		}

		echo '<p>';

		foreach ( $next as $status ) {
			if ( PurchaseOrderStatus::SENT === $status && ! current_user_can( Capabilities::SEND_ORDERS ) ) {
				continue;
			}

			// An empty order has nothing to tell a supplier.
			if ( PurchaseOrderStatus::SENT === $status && ! $order->can_be_sent() ) {
				continue;
			}

			printf(
				'<a class="button %1$s" href="%2$s">%3$s</a> ',
				esc_attr( PurchaseOrderStatus::CANCELLED === $status ? '' : 'button-primary' ),
				esc_url( $this->status_url( $order->id, $status ) ),
				esc_html( $this->status_action_label( $status ) )
			);
		}

		echo '</p>';
	}

	/**
	 * Receiving what has arrived, and what has arrived already.
	 *
	 * @param PurchaseOrder $order The order.
	 * @return void
	 */
	private function render_receiving( PurchaseOrder $order ): void {
		if ( array() === $order->lines ) {
			return;
		}

		$this->render_receipts( $order );

		if ( ! current_user_can( Capabilities::RECEIVE_ORDERS ) ) {
			return;
		}

		// A draft has not been ordered yet, and a cancelled order is not coming.
		if ( ! $order->status->is_expected() && ! $order->status->is_editable() ) {
			return;
		}

		if ( 0 === $order->outstanding() ) {
			return;
		}

		?>
		<h2><?php esc_html_e( 'Receive what has arrived', 'oxysuppliers-for-woocommerce' ); ?></h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::RECEIVE_ACTION ); ?>">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $order->id ); ?>">

			<?php
			/*
			 * The key that makes this happen once. Generated **here**, while the
			 * form is being drawn, so that pressing the button twice, reloading,
			 * going back, or a browser retrying a timed-out request all carry
			 * the same one — and the database refuses the second.
			 */
			?>
			<input type="hidden" name="idempotency_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>">
			<?php wp_nonce_field( self::RECEIVE_ACTION . '_' . $order->id ); ?>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Article', 'oxysuppliers-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Ordered', 'oxysuppliers-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Already in', 'oxysuppliers-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Outstanding', 'oxysuppliers-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Arriving now', 'oxysuppliers-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Price actually charged', 'oxysuppliers-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $order->lines as $line ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( '' !== $line->description ? $line->description : $line->sku ); ?></strong>
							<div class="description"><?php echo esc_html( $line->supplier_sku ); ?></div>
						</td>
						<td><?php echo esc_html( (string) $line->qty_ordered ); ?></td>
						<td><?php echo esc_html( (string) $line->qty_received ); ?></td>
						<td><strong><?php echo esc_html( (string) $line->outstanding() ); ?></strong></td>
						<td>
							<input type="number" min="0" max="<?php echo esc_attr( (string) $line->outstanding() ); ?>" step="1" size="5"
								name="received[<?php echo esc_attr( (string) $line->id ); ?>]" value="">
						</td>
						<td>
							<input type="text" size="8"
								name="cost[<?php echo esc_attr( (string) $line->id ); ?>]"
								placeholder="<?php echo esc_attr( $line->unit_cost->to_decimal() ); ?>">
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="oxysuppliers-receipt-reference"><?php esc_html_e( 'Their delivery note', 'oxysuppliers-for-woocommerce' ); ?></label></th>
						<td><input type="text" id="oxysuppliers-receipt-reference" class="regular-text" name="reference" value=""></td>
					</tr>
					<tr>
						<th scope="row"><label for="oxysuppliers-receipt-notes"><?php esc_html_e( 'Notes', 'oxysuppliers-for-woocommerce' ); ?></label></th>
						<td><textarea id="oxysuppliers-receipt-notes" class="large-text" rows="2" name="notes"></textarea></td>
					</tr>
				</tbody>
			</table>

			<p class="submit">
				<?php submit_button( __( 'Record the delivery', 'oxysuppliers-for-woocommerce' ), 'primary', 'submit', false ); ?>
			</p>

			<p class="description">
				<?php
				echo esc_html(
					true === Settings::get( 'update_stock_on_receipt' )
						? __( 'Stock goes up when this is recorded, for articles WooCommerce is counting. Every movement is written down.', 'oxysuppliers-for-woocommerce' )
						: __( 'Stock is not touched: that has been switched off in the settings. The delivery is still recorded.', 'oxysuppliers-for-woocommerce' )
				);
				?>
			</p>
		</form>
		<?php
	}

	/**
	 * What has already arrived against this order.
	 *
	 * @param PurchaseOrder $order The order.
	 * @return void
	 */
	private function render_receipts( PurchaseOrder $order ): void {
		$receipts = $this->receipts->for_order( $order->id );

		if ( array() === $receipts ) {
			return;
		}

		?>
		<h2><?php esc_html_e( 'Deliveries', 'oxysuppliers-for-woocommerce' ); ?></h2>
		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Delivery note', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Units', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Stock', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $receipts as $receipt ) : ?>
				<tr>
					<td><?php echo esc_html( $receipt->received_at ); ?></td>
					<td>
						<?php echo esc_html( $receipt->reference ); ?>
						<?php if ( $receipt->is_reversal() ) : ?>
							<span class="oxysuppliers-status is-inactive">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: the receipt being corrected. */
										__( 'corrects #%d', 'oxysuppliers-for-woocommerce' ),
										(int) $receipt->reverses_id
									)
								);
								?>
							</span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( sprintf( '%+d', $receipt->total_quantity() ) ); ?></td>
					<td>
						<?php
						echo esc_html(
							$receipt->stock_applied
								? __( 'moved', 'oxysuppliers-for-woocommerce' )
								: __( 'not moved', 'oxysuppliers-for-woocommerce' )
						);
						?>
					</td>
					<td>
						<?php if ( ! $receipt->is_reversal() && current_user_can( Capabilities::RECEIVE_ORDERS ) && ! $this->is_reversed( $receipts, $receipt->id ) ) : ?>
							<a class="button button-small" href="<?php echo esc_url( $this->reverse_url( $receipt->id ) ); ?>">
								<?php esc_html_e( 'Correct this', 'oxysuppliers-for-woocommerce' ); ?>
							</a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e( 'A delivery entered by mistake is corrected by an opposite entry, never by deleting one: both stay, so the history still reads.', 'oxysuppliers-for-woocommerce' ); ?>
		</p>
		<?php
	}

	/**
	 * Whether a receipt has already been corrected.
	 *
	 * @param list<\Oxysoft\OxySuppliers\Domain\Receipt> $receipts All of them.
	 * @param int                                        $id       The one in question.
	 * @return bool
	 */
	private function is_reversed( array $receipts, int $id ): bool {
		foreach ( $receipts as $receipt ) {
			if ( $receipt->reverses_id === $id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Record a delivery.
	 *
	 * @return void
	 */
	public function handle_receive(): void {
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;

		check_admin_referer( self::RECEIVE_ACTION . '_' . $id );

		if ( ! current_user_can( Capabilities::RECEIVE_ORDERS ) ) {
			wp_die( esc_html__( 'You are not allowed to receive goods.', 'oxysuppliers-for-woocommerce' ), 403 );
		}

		$order = $this->orders->find( $id );

		if ( null === $order ) {
			$this->redirect( array( 'notice' => 'missing' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked above.
		$key        = isset( $_POST['idempotency_key'] ) ? sanitize_text_field( wp_unslash( $_POST['idempotency_key'] ) ) : '';
		$quantities = isset( $_POST['received'] ) ? map_deep( wp_unslash( $_POST['received'] ), 'absint' ) : array();
		$costs      = isset( $_POST['cost'] ) ? map_deep( wp_unslash( $_POST['cost'] ), 'sanitize_text_field' ) : array();
		$reference  = isset( $_POST['reference'] ) ? sanitize_text_field( wp_unslash( $_POST['reference'] ) ) : '';
		$notes      = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$outcome = $this->receiver->receive(
			$order,
			is_array( $quantities ) ? $quantities : array(),
			is_array( $costs ) ? $costs : array(),
			$key,
			$reference,
			$notes
		);

		$this->redirect(
			array(
				'action' => 'view',
				'id'     => $id,
				'notice' => 'received_' . $outcome->status,
			)
		);
	}

	/**
	 * Correct a delivery that should not have been entered.
	 *
	 * @return void
	 */
	public function handle_reverse(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified on the next line, against this id.
		$receipt_id = isset( $_GET['receipt'] ) ? absint( wp_unslash( $_GET['receipt'] ) ) : 0;

		check_admin_referer( self::REVERSE_ACTION . '_' . $receipt_id );

		if ( ! current_user_can( Capabilities::RECEIVE_ORDERS ) ) {
			wp_die( esc_html__( 'You are not allowed to receive goods.', 'oxysuppliers-for-woocommerce' ), 403 );
		}

		$receipt = $this->receipts->find( $receipt_id );

		if ( null === $receipt ) {
			$this->redirect( array( 'notice' => 'missing' ) );
		}

		$outcome = $this->receiver->reverse( $receipt, wp_generate_uuid4() );

		$this->redirect(
			array(
				'action' => 'view',
				'id'     => $receipt->po_id,
				'notice' => ReceiptOutcome::RECORDED === $outcome->status ? 'reversed' : 'received_' . $outcome->status,
			)
		);
	}

	/**
	 * Where a correction lives.
	 *
	 * @param int $receipt_id The receipt to correct.
	 * @return string
	 */
	private function reverse_url( int $receipt_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => self::REVERSE_ACTION,
					'receipt' => $receipt_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::REVERSE_ACTION . '_' . $receipt_id
		);
	}

	/**
	 * The document, and the envelope it goes in.
	 *
	 * The recipient is shown, filled in and editable **before** anything is
	 * pressed. Sending is the one thing this plugin does that cannot be undone:
	 * the supplier has the email the moment it leaves.
	 *
	 * @param PurchaseOrder $order The order.
	 * @return void
	 */
	private function render_send_box( PurchaseOrder $order ): void {
		if ( array() === $order->lines ) {
			return;
		}

		$supplier = $this->suppliers->find( $order->supplier_id );
		$sent     = $this->audit->count( AuditLogger::OBJECT_ORDER, (string) $order->id, AuditLogger::ACTION_SENT );

		?>
		<p>
			<a class="button" href="<?php echo esc_url( $this->pdf_url( $order->id ) ); ?>">
				<?php esc_html_e( 'Download the PDF', 'oxysuppliers-for-woocommerce' ); ?>
			</a>
		</p>

		<?php if ( ! current_user_can( Capabilities::SEND_ORDERS ) ) : ?>
			<?php return; ?>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Send it to the supplier', 'oxysuppliers-for-woocommerce' ); ?></h2>

		<?php if ( $sent > 0 ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: how many times it has been sent, 2: when it last went. */
							_n(
								'This order has already been sent %1$d time, on %2$s. Sending it again will send another email.',
								'This order has already been sent %1$d times, the last on %2$s. Sending it again will send another email.',
								$sent,
								'oxysuppliers-for-woocommerce'
							),
							$sent,
							(string) $order->sent_at
						)
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SEND_ACTION ); ?>">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $order->id ); ?>">
			<?php wp_nonce_field( self::SEND_ACTION . '_' . $order->id ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="oxysuppliers-send-to"><?php esc_html_e( 'To', 'oxysuppliers-for-woocommerce' ); ?></label></th>
						<td>
							<input type="email" id="oxysuppliers-send-to" class="regular-text" name="to"
								value="<?php echo esc_attr( null === $supplier ? '' : $supplier->order_email ); ?>" required>
							<?php if ( null !== $supplier && '' === $supplier->order_email ) : ?>
								<p class="description">
									<?php esc_html_e( 'This supplier has no orders email on file. Type one, or add it to the supplier.', 'oxysuppliers-for-woocommerce' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="oxysuppliers-send-cc"><?php esc_html_e( 'Cc', 'oxysuppliers-for-woocommerce' ); ?></label></th>
						<td><input type="text" id="oxysuppliers-send-cc" class="regular-text" name="cc" value="<?php echo esc_attr( (string) Settings::get( 'email_cc' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="oxysuppliers-send-bcc"><?php esc_html_e( 'Bcc', 'oxysuppliers-for-woocommerce' ); ?></label></th>
						<td><input type="text" id="oxysuppliers-send-bcc" class="regular-text" name="bcc" value="<?php echo esc_attr( (string) Settings::get( 'email_bcc' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="oxysuppliers-send-subject"><?php esc_html_e( 'Subject', 'oxysuppliers-for-woocommerce' ); ?></label></th>
						<td>
							<input type="text" id="oxysuppliers-send-subject" class="large-text" name="subject"
								value="<?php echo esc_attr( PurchaseOrderMailer::fill( Settings::default_email_subject(), $order ) ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="oxysuppliers-send-body"><?php esc_html_e( 'Message', 'oxysuppliers-for-woocommerce' ); ?></label></th>
						<td>
							<textarea id="oxysuppliers-send-body" class="large-text" rows="8" name="body">
							<?php
								echo esc_textarea( PurchaseOrderMailer::fill( Settings::default_email_body(), $order ) );
							?>
							</textarea>
							<p class="description"><?php esc_html_e( 'The PDF goes with it as an attachment.', 'oxysuppliers-for-woocommerce' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<p class="submit">
				<?php
				submit_button(
					$sent > 0
						? __( 'Send it again', 'oxysuppliers-for-woocommerce' )
						: __( 'Send to the supplier', 'oxysuppliers-for-woocommerce' ),
					'primary',
					'submit',
					false
				);
				?>
			</p>
		</form>

		<?php $this->render_history( $order ); ?>
		<?php
	}

	/**
	 * What has happened to this order.
	 *
	 * @param PurchaseOrder $order The order.
	 * @return void
	 */
	private function render_history( PurchaseOrder $order ): void {
		$history = $this->audit->history( AuditLogger::OBJECT_ORDER, (string) $order->id, 20 );

		if ( array() === $history ) {
			return;
		}

		?>
		<h2><?php esc_html_e( 'History', 'oxysuppliers-for-woocommerce' ); ?></h2>
		<table class="wp-list-table widefat striped">
			<tbody>
			<?php foreach ( $history as $line ) : ?>
				<tr>
					<td style="width: 180px;"><?php echo esc_html( (string) $line['created_at'] ); ?></td>
					<td style="width: 160px;"><?php echo esc_html( (string) $line['action'] ); ?></td>
					<td><?php echo esc_html( (string) ( $line['message'] ?? '' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Send the PDF to the browser.
	 *
	 * Never a file left in the uploads folder: a purchase order sitting there is
	 * one guessed address away from anybody. It is built on the way out, behind
	 * a capability check and a nonce.
	 *
	 * @return void
	 */
	public function handle_pdf(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified on the next line, against this id.
		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

		check_admin_referer( self::PDF_ACTION . '_' . $id );

		if ( ! current_user_can( Capabilities::VIEW_ORDERS ) ) {
			wp_die( esc_html__( 'You are not allowed to see purchase orders.', 'oxysuppliers-for-woocommerce' ), 403 );
		}

		$order = $this->orders->find( $id );

		if ( null === $order ) {
			wp_die( esc_html__( 'That purchase order no longer exists.', 'oxysuppliers-for-woocommerce' ), 404 );
		}

		$pdf = $this->renderer->render(
			$this->document->html( $order, $this->suppliers->find( $order->supplier_id ) )
		);

		if ( '' === $pdf ) {
			wp_die( esc_html__( 'The PDF could not be made. The plugin may be missing part of its installation.', 'oxysuppliers-for-woocommerce' ), 500 );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $order->number . '.pdf' ) . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );

		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF, not markup.
		exit;
	}

	/**
	 * Send the order to the supplier.
	 *
	 * @return void
	 */
	public function handle_send(): void {
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;

		check_admin_referer( self::SEND_ACTION . '_' . $id );

		if ( ! current_user_can( Capabilities::SEND_ORDERS ) ) {
			wp_die( esc_html__( 'You are not allowed to send purchase orders.', 'oxysuppliers-for-woocommerce' ), 403 );
		}

		$order = $this->orders->find( $id );

		if ( null === $order ) {
			$this->redirect( array( 'notice' => 'missing' ) );
		}

		$result = $this->mailer->send(
			$order,
			$this->suppliers->find( $order->supplier_id ),
			array(
				'to'      => isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '',
				'cc'      => isset( $_POST['cc'] ) ? sanitize_text_field( wp_unslash( $_POST['cc'] ) ) : '',
				'bcc'     => isset( $_POST['bcc'] ) ? sanitize_text_field( wp_unslash( $_POST['bcc'] ) ) : '',
				'subject' => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
				'body'    => isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '',
			)
		);

		if ( ! $result['sent'] ) {
			$this->redirect(
				array(
					'action' => 'view',
					'id'     => $id,
					'notice' => 'not_sent',
				)
			);
		}

		// The first time it goes out, the order moves on. A resend does not
		// move anything: it has already been sent, and saying so twice would
		// overwrite when it first went.
		if ( ! $result['resend'] && $order->status->can_move_to( PurchaseOrderStatus::SENT ) ) {
			$this->orders->mark_sent( $order );

			/** This action is documented in src/Admin/PurchaseOrdersScreen.php */
			do_action( 'oxysuppliers_purchase_order_sent', $order );
		}

		$this->redirect(
			array(
				'action' => 'view',
				'id'     => $id,
				'notice' => $result['resend'] ? 'resent' : 'sent',
			)
		);
	}

	/**
	 * Where the PDF lives.
	 *
	 * @param int $id Order id.
	 * @return string
	 */
	private function pdf_url( int $id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::PDF_ACTION,
					'id'     => $id,
				),
				admin_url( 'admin-post.php' )
			),
			self::PDF_ACTION . '_' . $id
		);
	}

	/**
	 * Start a new order.
	 *
	 * @return void
	 */
	public function handle_create(): void {
		check_admin_referer( self::CREATE_ACTION );

		if ( ! current_user_can( Capabilities::CREATE_ORDERS ) ) {
			wp_die( esc_html__( 'You are not allowed to create purchase orders.', 'oxysuppliers-for-woocommerce' ), 403 );
		}

		$supplier_id = isset( $_POST['supplier_id'] ) ? absint( wp_unslash( $_POST['supplier_id'] ) ) : 0;
		$supplier    = $this->suppliers->find( $supplier_id );

		if ( null === $supplier ) {
			$this->redirect( array( 'notice' => 'no_supplier' ) );
		}

		$order_date = isset( $_POST['order_date'] ) ? sanitize_text_field( wp_unslash( $_POST['order_date'] ) ) : '';
		$order_date = $this->valid_date( $order_date, gmdate( 'Y-m-d' ) );

		$expected = isset( $_POST['expected_date'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_date'] ) ) : '';
		$expected = '' === $expected
			? ExpectedDate::after( $order_date, $supplier->lead_time_days )
			: $this->valid_date( $expected, $order_date );

		$draft = new PurchaseOrder(
			0,
			'',
			$supplier_id,
			PurchaseOrderStatus::DRAFT,
			$supplier->currency(),
			$order_date,
			$expected,
			'',
			'',
			$supplier->payment_terms
		);

		$stored = $this->orders->create( $draft );

		if ( null === $stored ) {
			$this->redirect( array( 'notice' => 'error' ) );
		}

		$this->audit->log(
			AuditLogger::OBJECT_ORDER,
			(string) $stored->id,
			AuditLogger::ACTION_CREATED,
			null,
			array( 'number' => $stored->number )
		);

		/** This action is documented in src/Service/ProposalBuilder.php */
		do_action( 'oxysuppliers_purchase_order_created', $stored );

		$this->redirect(
			array(
				'action' => 'view',
				'id'     => $stored->id,
				'notice' => 'created',
			)
		);
	}

	/**
	 * Save an order's lines and details.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;

		check_admin_referer( self::SAVE_ACTION . '_' . $id );

		if ( ! current_user_can( Capabilities::CREATE_ORDERS ) ) {
			wp_die( esc_html__( 'You are not allowed to change purchase orders.', 'oxysuppliers-for-woocommerce' ), 403 );
		}

		$order = $this->orders->find( $id );

		if ( null === $order ) {
			$this->redirect( array( 'notice' => 'missing' ) );
		}

		// An order that has been sent is part of a story that has already
		// happened. The screen does not offer the fields; this refuses them
		// even when they arrive anyway.
		if ( ! $order->status->is_editable() ) {
			$this->redirect(
				array(
					'action' => 'view',
					'id'     => $id,
					'notice' => 'not_editable',
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
		$submitted = isset( $_POST['lines'] ) ? map_deep( wp_unslash( $_POST['lines'] ), 'sanitize_text_field' ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
		$additions = isset( $_POST['add'] ) ? map_deep( wp_unslash( $_POST['add'] ), 'sanitize_text_field' ) : array();

		$lines = $this->lines_from_request( $order, is_array( $submitted ) ? $submitted : array() );
		$lines = array_merge( $lines, $this->additions_from_request( $order, is_array( $additions ) ? $additions : array() ) );

		$expected = isset( $_POST['expected_date'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_date'] ) ) : '';

		$updated = new PurchaseOrder(
			$order->id,
			$order->number,
			$order->supplier_id,
			$order->status,
			$order->currency,
			$order->order_date,
			'' === $expected ? null : $this->valid_date( $expected, (string) $order->expected_date ),
			isset( $_POST['supplier_reference'] ) ? sanitize_text_field( wp_unslash( $_POST['supplier_reference'] ) ) : '',
			$order->delivery_address,
			$order->payment_terms,
			isset( $_POST['internal_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['internal_notes'] ) ) : '',
			isset( $_POST['supplier_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['supplier_notes'] ) ) : '',
			$lines,
			$order->sent_at
		);

		if ( ! $this->orders->update( $updated ) ) {
			$this->redirect(
				array(
					'action' => 'view',
					'id'     => $id,
					'notice' => 'error',
				)
			);
		}

		$this->audit->log(
			AuditLogger::OBJECT_ORDER,
			(string) $id,
			AuditLogger::ACTION_UPDATED,
			array( 'total' => $order->total()->to_decimal() ),
			array( 'total' => $updated->total()->to_decimal() )
		);

		$this->redirect(
			array(
				'action' => 'view',
				'id'     => $id,
				'notice' => 'saved',
			)
		);
	}

	/**
	 * Move an order to another state.
	 *
	 * @return void
	 */
	public function handle_status(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified below, against this id and status.
		$id     = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		$wanted = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		check_admin_referer( self::STATUS_ACTION . '_' . $id . '_' . $wanted );

		if ( ! current_user_can( Capabilities::CREATE_ORDERS ) ) {
			wp_die( esc_html__( 'You are not allowed to change purchase orders.', 'oxysuppliers-for-woocommerce' ), 403 );
		}

		$order  = $this->orders->find( $id );
		$status = PurchaseOrderStatus::tryFrom( $wanted );

		if ( null === $order || null === $status ) {
			$this->redirect( array( 'notice' => 'missing' ) );
		}

		if ( PurchaseOrderStatus::SENT === $status && ! current_user_can( Capabilities::SEND_ORDERS ) ) {
			wp_die( esc_html__( 'You are not allowed to send purchase orders.', 'oxysuppliers-for-woocommerce' ), 403 );
		}

		try {
			$moved = PurchaseOrderStatus::SENT === $status
				? $this->orders->mark_sent( $order )
				: $this->orders->set_status( $order, $status );
		} catch ( InvalidTransition $refused ) {
			// The domain said no. That is the answer, whatever the address bar
			// said.
			$this->redirect(
				array(
					'action' => 'view',
					'id'     => $id,
					'notice' => 'bad_transition',
				)
			);
		}

		if ( ! $moved ) {
			$this->redirect(
				array(
					'action' => 'view',
					'id'     => $id,
					'notice' => 'error',
				)
			);
		}

		$this->audit->log(
			AuditLogger::OBJECT_ORDER,
			(string) $id,
			AuditLogger::ACTION_STATUS,
			array( 'status' => $order->status->value ),
			array( 'status' => $status->value )
		);

		if ( PurchaseOrderStatus::SENT === $status ) {
			/**
			 * Fires when a purchase order has been marked as sent.
			 *
			 * @since 0.1.0
			 *
			 * @param PurchaseOrder $order The order.
			 */
			do_action( 'oxysuppliers_purchase_order_sent', $order );
		}

		$this->redirect(
			array(
				'action' => 'view',
				'id'     => $id,
				'notice' => 'status',
			)
		);
	}

	/**
	 * The lines as the form left them.
	 *
	 * @param PurchaseOrder       $order     The order being saved.
	 * @param array<string,mixed> $submitted What the form sent.
	 * @return list<PurchaseOrderLine>
	 */
	private function lines_from_request( PurchaseOrder $order, array $submitted ): array {
		$lines = array();

		foreach ( $order->lines as $line ) {
			$row = $submitted[ (string) $line->id ] ?? null;

			if ( ! is_array( $row ) ) {
				$lines[] = $line;

				continue;
			}

			if ( ! empty( $row['remove'] ) ) {
				continue;
			}

			$quantity = max( 0, (int) ( $row['qty_ordered'] ?? $line->qty_ordered ) );
			$cost     = (string) ( $row['unit_cost'] ?? '' );
			$cost     = '' === $cost ? $line->unit_cost->to_decimal() : (string) wc_format_decimal( $cost, false, true );
			$cost     = '' === $cost ? $line->unit_cost->to_decimal() : $cost;

			$lines[] = new PurchaseOrderLine(
				$line->id,
				$line->product_id,
				$line->variation_id,
				$line->sku,
				$line->supplier_sku,
				$line->description,
				$quantity,
				$line->qty_received,
				Money::from_decimal( $cost, $order->currency ),
				$line->discount_bp,
				$line->tax_rate_bp,
				$line->sort_order
			);
		}

		return $lines;
	}

	/**
	 * New lines from the price list, for the ones given a quantity.
	 *
	 * @param PurchaseOrder       $order     The order being saved.
	 * @param array<string,mixed> $additions What the form sent.
	 * @return list<PurchaseOrderLine>
	 */
	private function additions_from_request( PurchaseOrder $order, array $additions ): array {
		$lines = array();

		foreach ( $additions as $key => $quantity ) {
			$quantity = (int) $quantity;

			if ( $quantity <= 0 ) {
				continue;
			}

			$parts = explode( '_', (string) $key );

			if ( 2 !== count( $parts ) ) {
				continue;
			}

			$listings = $this->listings->for_item( (int) $parts[0], (int) $parts[1] );
			$listing  = null;

			foreach ( $listings as $candidate ) {
				if ( $candidate->supplier_id === $order->supplier_id ) {
					$listing = $candidate;
				}
			}

			// Only what this supplier actually sells: an id typed into the
			// address bar must not put somebody else's article on their order.
			if ( null === $listing ) {
				continue;
			}

			$lines[] = PurchaseOrderLine::from_listing(
				$listing,
				$quantity,
				$this->article_sku( $listing->product_id, $listing->variation_id ),
				$this->article_name( $listing->product_id, $listing->variation_id ),
				count( $order->lines ) + count( $lines )
			);
		}

		return $lines;
	}

	/**
	 * A date, or the fallback when it is not one.
	 *
	 * @param string $value    Candidate.
	 * @param string $fallback What to use instead.
	 * @return string
	 */
	private function valid_date( string $value, string $fallback ): string {
		return ExpectedDate::is_valid( $value ) ? $value : $fallback;
	}

	/**
	 * Whether an order should have arrived by now.
	 *
	 * @param PurchaseOrder $order The order.
	 * @return bool
	 */
	private function is_late( PurchaseOrder $order ): bool {
		return null !== $order->expected_date
			&& $order->expected_date < gmdate( 'Y-m-d' )
			&& $order->status->is_expected();
	}

	/**
	 * What to call a state.
	 *
	 * @param PurchaseOrderStatus $status The state.
	 * @return string
	 */
	private function status_label( PurchaseOrderStatus $status ): string {
		return match ( $status ) {
			PurchaseOrderStatus::DRAFT => __( 'Draft', 'oxysuppliers-for-woocommerce' ),
			PurchaseOrderStatus::TO_SEND => __( 'To send', 'oxysuppliers-for-woocommerce' ),
			PurchaseOrderStatus::SENT => __( 'Sent', 'oxysuppliers-for-woocommerce' ),
			PurchaseOrderStatus::CONFIRMED => __( 'Confirmed', 'oxysuppliers-for-woocommerce' ),
			PurchaseOrderStatus::PARTIALLY_RECEIVED => __( 'Partly received', 'oxysuppliers-for-woocommerce' ),
			PurchaseOrderStatus::RECEIVED => __( 'Received', 'oxysuppliers-for-woocommerce' ),
			PurchaseOrderStatus::CANCELLED => __( 'Cancelled', 'oxysuppliers-for-woocommerce' ),
		};
	}

	/**
	 * What to call the button that moves an order to a state.
	 *
	 * @param PurchaseOrderStatus $status The state.
	 * @return string
	 */
	private function status_action_label( PurchaseOrderStatus $status ): string {
		return match ( $status ) {
			PurchaseOrderStatus::DRAFT => __( 'Back to draft', 'oxysuppliers-for-woocommerce' ),
			PurchaseOrderStatus::TO_SEND => __( 'Ready to send', 'oxysuppliers-for-woocommerce' ),
			PurchaseOrderStatus::SENT => __( 'Mark as sent', 'oxysuppliers-for-woocommerce' ),
			PurchaseOrderStatus::CONFIRMED => __( 'Supplier confirmed', 'oxysuppliers-for-woocommerce' ),
			PurchaseOrderStatus::PARTIALLY_RECEIVED => __( 'Partly received', 'oxysuppliers-for-woocommerce' ),
			PurchaseOrderStatus::RECEIVED => __( 'All received', 'oxysuppliers-for-woocommerce' ),
			PurchaseOrderStatus::CANCELLED => __( 'Cancel', 'oxysuppliers-for-woocommerce' ),
		};
	}

	/**
	 * The coloured label for a state.
	 *
	 * @param PurchaseOrderStatus $status The state.
	 * @return void
	 */
	private function render_status_label( PurchaseOrderStatus $status ): void {
		$class = match ( $status ) {
			PurchaseOrderStatus::RECEIVED => 'is-ok',
			PurchaseOrderStatus::CANCELLED => 'is-inactive',
			PurchaseOrderStatus::PARTIALLY_RECEIVED => 'is-low',
			default => 'is-active',
		};

		printf(
			'<span class="oxysuppliers-status %1$s">%2$s</span>',
			esc_attr( $class ),
			esc_html( $this->status_label( $status ) )
		);
	}

	/**
	 * Where a state change lives.
	 *
	 * @param int                 $id     Order id.
	 * @param PurchaseOrderStatus $status Where it is going.
	 * @return string
	 */
	private function status_url( int $id, PurchaseOrderStatus $status ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::STATUS_ACTION,
					'id'     => $id,
					'status' => $status->value,
				),
				admin_url( 'admin-post.php' )
			),
			self::STATUS_ACTION . '_' . $id . '_' . $status->value
		);
	}

	/**
	 * Every supplier, for the filters.
	 *
	 * @return array<int,string>
	 */
	private function supplier_list(): array {
		if ( null === $this->supplier_names ) {
			$this->supplier_names = array();

			foreach ( $this->suppliers->paginate( array( 'per_page' => 500 ) ) as $supplier ) {
				$this->supplier_names[ $supplier->id ] = $supplier->display_name();
			}
		}

		return $this->supplier_names;
	}

	/**
	 * A supplier's name.
	 *
	 * @param int $supplier_id Supplier id.
	 * @return string
	 */
	private function supplier_name( int $supplier_id ): string {
		return $this->supplier_list()[ $supplier_id ] ?? '';
	}

	/**
	 * What WooCommerce calls an article.
	 *
	 * @param int $product_id   Parent product.
	 * @param int $variation_id Variation, 0 for a simple product.
	 * @return string
	 */
	private function article_name( int $product_id, int $variation_id ): string {
		$product = wc_get_product( 0 !== $variation_id ? $variation_id : $product_id );

		return $product instanceof \WC_Product ? $product->get_name() : '';
	}

	/**
	 * Our code for an article.
	 *
	 * @param int $product_id   Parent product.
	 * @param int $variation_id Variation, 0 for a simple product.
	 * @return string
	 */
	private function article_sku( int $product_id, int $variation_id ): string {
		$product = wc_get_product( 0 !== $variation_id ? $variation_id : $product_id );

		return $product instanceof \WC_Product ? $product->get_sku() : '';
	}

	/**
	 * What to say when the id in the address does not exist.
	 *
	 * @return void
	 */
	private function render_missing(): void {
		?>
		<div class="notice notice-error"><p><?php esc_html_e( 'That purchase order no longer exists.', 'oxysuppliers-for-woocommerce' ); ?></p></div>
		<p><a class="button" href="<?php echo esc_url( Menu::url( self::SLUG ) ); ?>"><?php esc_html_e( 'Back to the list', 'oxysuppliers-for-woocommerce' ); ?></a></p>
		<?php
	}

	/**
	 * Show whatever the last action left behind.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Displays a fixed message chosen from a list.
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'created'                   => array( 'success', __( 'Purchase order started. Add what you want to buy.', 'oxysuppliers-for-woocommerce' ) ),
			'saved'                     => array( 'success', __( 'Purchase order saved.', 'oxysuppliers-for-woocommerce' ) ),
			'status'                    => array( 'success', __( 'Purchase order updated.', 'oxysuppliers-for-woocommerce' ) ),
			'proposed'                  => array( 'success', __( 'Draft purchase orders created, one per supplier.', 'oxysuppliers-for-woocommerce' ) ),
			'sent'                      => array( 'success', __( 'Sent to the supplier, with the PDF attached.', 'oxysuppliers-for-woocommerce' ) ),
			'resent'                    => array( 'success', __( 'Sent again. Both sends are in the history below.', 'oxysuppliers-for-woocommerce' ) ),
			'not_sent'                  => array( 'error', __( 'The message could not be sent, so nothing has gone to the supplier. Check the site\'s email settings and the address.', 'oxysuppliers-for-woocommerce' ) ),

			// Receiving. "Already recorded" is a success, not a failure: it is
			// what a second press gets, and the goods are on the shelf once.
			'received_recorded'         => array( 'success', __( 'Delivery recorded, and the stock has been updated.', 'oxysuppliers-for-woocommerce' ) ),
			'received_already_recorded' => array( 'info', __( 'That delivery had already been recorded, so nothing was added a second time.', 'oxysuppliers-for-woocommerce' ) ),
			'received_busy'             => array( 'warning', __( 'Somebody else is receiving this order right now. Look again before entering it a second time.', 'oxysuppliers-for-woocommerce' ) ),
			'received_nothing'          => array( 'warning', __( 'No quantity was entered, so there was nothing to record.', 'oxysuppliers-for-woocommerce' ) ),
			'received_too_many'         => array( 'error', __( 'More was entered than is still outstanding. Nothing was recorded.', 'oxysuppliers-for-woocommerce' ) ),
			'received_failed'           => array( 'error', __( 'The delivery could not be recorded, and nothing was changed.', 'oxysuppliers-for-woocommerce' ) ),
			'reversed'                  => array( 'success', __( 'Correction recorded: the stock has been put back and both entries stay in the history.', 'oxysuppliers-for-woocommerce' ) ),
			'missing'                   => array( 'error', __( 'That purchase order no longer exists.', 'oxysuppliers-for-woocommerce' ) ),
			'no_supplier'               => array( 'error', __( 'Choose a supplier first.', 'oxysuppliers-for-woocommerce' ) ),
			'not_editable'              => array( 'error', __( 'That order has already gone out, so its lines cannot be changed.', 'oxysuppliers-for-woocommerce' ) ),
			'bad_transition'            => array( 'error', __( 'A purchase order cannot go from where it is to there.', 'oxysuppliers-for-woocommerce' ) ),
			'error'                     => array( 'error', __( 'That could not be saved. Nothing was changed.', 'oxysuppliers-for-woocommerce' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}

	/**
	 * Go back to the tab with something to say.
	 *
	 * @param array<string,mixed> $arguments Query arguments.
	 * @return never
	 */
	private function redirect( array $arguments ) {
		wp_safe_redirect( Menu::url( self::SLUG, $arguments ) );
		exit;
	}
}
