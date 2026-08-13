<?php
/**
 * The reordering screen.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Admin;

use Oxysoft\OxySuppliers\Domain\Requirement;
use Oxysoft\OxySuppliers\Domain\RequirementStatus;
use Oxysoft\OxySuppliers\Engine\RequirementCalculator;
use Oxysoft\OxySuppliers\Export\CsvWriter;
use Oxysoft\OxySuppliers\Persistence\CatalogueRepository;
use Oxysoft\OxySuppliers\Persistence\SupplierRepository;
use Oxysoft\OxySuppliers\Service\ProposalBuilder;
use Oxysoft\OxySuppliers\Support\Capabilities;

/**
 * What to order, from whom, and how much.
 *
 * The screen the whole plugin exists for. It answers in one page, and it is
 * honest about the two ways it can fail to answer: an article nobody sells, and
 * a supplier with no price. Both are shown rather than filtered away, and both
 * have a filter of their own so they can be cleaned up in one sitting.
 */
final class RequirementsScreen implements Screen {

	public const SLUG = 'requirements';

	public const EXPORT_ACTION  = 'oxysuppliers_export_requirements';
	public const PROPOSE_ACTION = 'oxysuppliers_propose_orders';

	/**
	 * How many articles a page holds.
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
	 * @param CatalogueRepository   $catalogue  Stock, sales and thresholds.
	 * @param RequirementCalculator $calculator The suggestion.
	 * @param SupplierRepository    $suppliers  For the supplier filter.
	 * @param ProposalBuilder       $proposals  Turns ticked rows into drafts.
	 */
	public function __construct(
		private readonly CatalogueRepository $catalogue,
		private readonly RequirementCalculator $calculator,
		private readonly SupplierRepository $suppliers,
		private readonly ProposalBuilder $proposals
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
		return __( 'Reordering', 'oxysuppliers-for-woocommerce' );
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
	 * Hook up the export.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'handle_export' ) );
		add_action( 'admin_post_' . self::PROPOSE_ACTION, array( $this, 'handle_propose' ) );
	}

	/**
	 * Draw the screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to see this page.', 'oxysuppliers-for-woocommerce' ), 403 );
		}

		$query     = $this->query_from_request();
		$rows      = $this->calculator->calculate_all( $this->catalogue->paginate( $query ) );
		$total     = $this->catalogue->count( $query );
		$pages     = (int) ceil( $total / self::PER_PAGE );
		$can_order = current_user_can( Capabilities::CREATE_ORDERS );

		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'What to reorder', 'oxysuppliers-for-woocommerce' ); ?></h1>
		<hr class="wp-header-end">

		<?php $this->render_notice(); ?>
		<?php $this->render_sales_warning(); ?>
		<?php $this->render_filters( $query ); ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( self::PROPOSE_ACTION ); ?>">
		<?php wp_nonce_field( self::PROPOSE_ACTION ); ?>

		<table class="wp-list-table widefat fixed striped oxysuppliers-requirements">
			<thead>
				<tr>
					<?php if ( $can_order ) : ?>
						<td class="check-column"></td>
					<?php endif; ?>
					<th scope="col"><?php esc_html_e( 'Article', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Available', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Reorder at / up to', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Sold 7 / 30 / 90', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Supplier', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'To order', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'oxysuppliers-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( array() === $rows ) : ?>
				<tr>
					<td colspan="8"><?php $this->render_empty_message( $query ); ?></td>
				</tr>
			<?php endif; ?>

			<?php foreach ( $rows as $row ) : ?>
				<?php $this->render_row( $row, $can_order ); ?>
			<?php endforeach; ?>
			</tbody>
		</table>

		<p>
			<?php if ( $can_order ) : ?>
				<?php submit_button( __( 'Create purchase orders from the ticked rows', 'oxysuppliers-for-woocommerce' ), 'primary', 'submit', false ); ?>
			<?php endif; ?>
			<a class="button" href="<?php echo esc_url( $this->export_url( $query ) ); ?>">
				<?php esc_html_e( 'Export what is shown (CSV)', 'oxysuppliers-for-woocommerce' ); ?>
			</a>
		</p>

		<?php if ( $can_order ) : ?>
			<p class="description">
				<?php esc_html_e( 'One draft order per supplier, grouped for you. Nothing is sent: a suggestion that posts itself is a suggestion nobody trusts.', 'oxysuppliers-for-woocommerce' ); ?>
			</p>
		<?php endif; ?>
		</form>

		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'total'     => $pages,
								'current'   => (int) $query['page'],
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
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
	 * One article.
	 *
	 * @param Requirement $row       The answer for it.
	 * @param bool        $can_order Whether this user may turn it into an order.
	 * @return void
	 */
	private function render_row( Requirement $row, bool $can_order = false ): void {
		$article  = $row->context;
		$supplier = $article->supplier;

		// A variation has no screen of its own: it is edited on its parent.
		$edit = get_edit_post_link( $article->product_id );

		?>
		<tr>
			<?php if ( $can_order ) : ?>
				<th scope="row" class="check-column">
					<?php if ( $row->is_orderable() ) : ?>
						<input type="checkbox" name="articles[]"
							value="<?php echo esc_attr( $article->key() ); ?>"
							<?php checked( $row->status->needs_attention() ); ?>>
					<?php endif; ?>
				</th>
			<?php endif; ?>
			<td>
				<strong>
					<?php if ( $edit ) : ?>
						<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $article->name ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $article->name ); ?>
					<?php endif; ?>
				</strong>
				<?php if ( '' !== $article->sku ) : ?>
					<div class="description"><?php echo esc_html( $article->sku ); ?></div>
				<?php endif; ?>
			</td>
			<td>
				<strong><?php echo esc_html( (string) $article->available() ); ?></strong>
				<?php if ( $article->reserved > 0 ) : ?>
					<div class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: stock on the shelf, 2: units held for orders being paid for. */
								__( '%1$d on the shelf, %2$d held', 'oxysuppliers-for-woocommerce' ),
								(int) $article->stock,
								$article->reserved
							)
						);
						?>
					</div>
				<?php endif; ?>
				<?php if ( $article->incoming > 0 ) : ?>
					<div class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: units already ordered from a supplier. */
								__( '%d on the way', 'oxysuppliers-for-woocommerce' ),
								$article->incoming
							)
						);
						?>
					</div>
				<?php endif; ?>
			</td>
			<td>
				<?php echo esc_html( $article->reorder_point . ' / ' . $article->target ); ?>
			</td>
			<td>
				<?php echo esc_html( $article->sold_7 . ' / ' . $article->sold_30 . ' / ' . $article->sold_90 ); ?>
			</td>
			<td>
				<?php if ( null === $supplier ) : ?>
					<span class="description">&mdash;</span>
				<?php else : ?>
					<?php echo esc_html( $this->supplier_name( $supplier->supplier_id ) ); ?>
					<div class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: unit cost, 2: lead time in days. */
								__( '%1$s each, %2$d days', 'oxysuppliers-for-woocommerce' ),
								$supplier->unit_cost->to_decimal() . ' ' . $supplier->unit_cost->currency,
								$supplier->lead_time_days
							)
						);
						?>
					</div>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( $row->suggested > 0 ) : ?>
					<strong><?php echo esc_html( (string) $row->suggested ); ?></strong>
					<?php if ( null !== $row->value ) : ?>
						<div class="description">
							<?php echo esc_html( $row->value->to_decimal() . ' ' . $row->value->currency ); ?>
						</div>
					<?php endif; ?>
					<?php if ( $row->was_rounded_up() ) : ?>
						<div class="description">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: units actually short. */
									__( '%d short, rounded up to the supplier terms', 'oxysuppliers-for-woocommerce' ),
									$row->needed
								)
							);
							?>
						</div>
					<?php endif; ?>
				<?php else : ?>
					<span class="description">&mdash;</span>
				<?php endif; ?>
			</td>
			<td><?php $this->render_status( $row->status ); ?></td>
		</tr>
		<?php
	}

	/**
	 * The coloured label for a state.
	 *
	 * @param RequirementStatus $status The state.
	 * @return void
	 */
	private function render_status( RequirementStatus $status ): void {
		$labels = array(
			RequirementStatus::OK->value                  => array( 'is-ok', __( 'Fine', 'oxysuppliers-for-woocommerce' ) ),
			RequirementStatus::BELOW_REORDER_POINT->value => array( 'is-low', __( 'Below reorder point', 'oxysuppliers-for-woocommerce' ) ),
			RequirementStatus::OUT_OF_STOCK->value        => array( 'is-out', __( 'Out of stock', 'oxysuppliers-for-woocommerce' ) ),
			RequirementStatus::NO_SUPPLIER->value         => array( 'is-missing', __( 'No supplier', 'oxysuppliers-for-woocommerce' ) ),
			RequirementStatus::NO_COST->value             => array( 'is-missing', __( 'No cost', 'oxysuppliers-for-woocommerce' ) ),
			RequirementStatus::NOT_TRACKED->value         => array( 'is-ok', __( 'Not tracked', 'oxysuppliers-for-woocommerce' ) ),
		);

		$label = $labels[ $status->value ] ?? array( 'is-ok', $status->value );

		printf(
			'<span class="oxysuppliers-status %1$s">%2$s</span>',
			esc_attr( $label[0] ),
			esc_html( $label[1] )
		);
	}

	/**
	 * Say when the sales figures cannot be believed.
	 *
	 * @return void
	 */
	private function render_sales_warning(): void {
		if ( ! $this->catalogue->sales_data_is_stale() ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'The sales figures on this page are not complete.', 'oxysuppliers-for-woocommerce' ); ?></strong>
				<?php esc_html_e( 'WooCommerce keeps them in a separate table that it fills in the background, and on this shop it is empty while there are orders. Until it has been filled, every article here looks as if it has sold nothing.', 'oxysuppliers-for-woocommerce' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=action-scheduler&status=pending' ) ); ?>">
					<?php esc_html_e( 'See what is queued', 'oxysuppliers-for-woocommerce' ); ?>
				</a>
				&nbsp;|&nbsp;
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-admin&path=/analytics/settings' ) ); ?>">
					<?php esc_html_e( 'Import historical data', 'oxysuppliers-for-woocommerce' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * The filter bar.
	 *
	 * @param array<string,mixed> $query Current query.
	 * @return void
	 */
	private function render_filters( array $query ): void {
		$suppliers = $this->suppliers->paginate( array( 'per_page' => 200 ) );

		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="oxysuppliers-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( Menu::SLUG ); ?>">
			<input type="hidden" name="tab" value="<?php echo esc_attr( self::SLUG ); ?>">

			<p class="search-box">
				<label class="screen-reader-text" for="oxysuppliers-requirements-search">
					<?php esc_html_e( 'Search articles', 'oxysuppliers-for-woocommerce' ); ?>
				</label>
				<input type="search" id="oxysuppliers-requirements-search" name="s"
					value="<?php echo esc_attr( (string) $query['search'] ); ?>"
					placeholder="<?php esc_attr_e( 'Name or SKU', 'oxysuppliers-for-woocommerce' ); ?>">

				<select name="supplier_id">
					<option value="0"><?php esc_html_e( 'Any supplier', 'oxysuppliers-for-woocommerce' ); ?></option>
					<?php foreach ( $suppliers as $supplier ) : ?>
						<option value="<?php echo esc_attr( (string) $supplier->id ); ?>" <?php selected( $supplier->id, $query['supplier_id'] ); ?>>
							<?php echo esc_html( $supplier->display_name() ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<?php
				wp_dropdown_categories(
					array(
						'taxonomy'          => 'product_cat',
						'name'              => 'category_id',
						'show_option_none'  => __( 'Any category', 'oxysuppliers-for-woocommerce' ),
						'option_none_value' => '0',
						'selected'          => (int) $query['category_id'],
						'hierarchical'      => true,
						'hide_empty'        => false,
						'value_field'       => 'term_taxonomy_id',
					)
				);
				?>

				<?php submit_button( __( 'Filter', 'oxysuppliers-for-woocommerce' ), '', '', false ); ?>
			</p>

			<p>
				<label>
					<input type="checkbox" name="below" value="1" <?php checked( ! empty( $query['below_reorder_point'] ) ); ?>>
					<?php esc_html_e( 'Only below the reorder point', 'oxysuppliers-for-woocommerce' ); ?>
				</label>
				&nbsp;&nbsp;
				<label>
					<input type="checkbox" name="out" value="1" <?php checked( ! empty( $query['out_of_stock'] ) ); ?>>
					<?php esc_html_e( 'Only out of stock', 'oxysuppliers-for-woocommerce' ); ?>
				</label>
				&nbsp;&nbsp;
				<label>
					<input type="checkbox" name="no_supplier" value="1" <?php checked( ! empty( $query['without_supplier'] ) ); ?>>
					<?php esc_html_e( 'Only without a supplier', 'oxysuppliers-for-woocommerce' ); ?>
				</label>
				&nbsp;&nbsp;
				<label>
					<input type="checkbox" name="no_cost" value="1" <?php checked( ! empty( $query['without_cost'] ) ); ?>>
					<?php esc_html_e( 'Only without a cost', 'oxysuppliers-for-woocommerce' ); ?>
				</label>
			</p>
		</form>
		<?php
	}

	/**
	 * What to say when nothing came back.
	 *
	 * @param array<string,mixed> $query Current query.
	 * @return void
	 */
	private function render_empty_message( array $query ): void {
		$filtered = '' !== (string) $query['search']
			|| $query['supplier_id'] > 0
			|| $query['category_id'] > 0
			|| ! empty( $query['below_reorder_point'] )
			|| ! empty( $query['out_of_stock'] )
			|| ! empty( $query['without_supplier'] )
			|| ! empty( $query['without_cost'] );

		if ( $filtered ) {
			esc_html_e( 'Nothing matches those filters.', 'oxysuppliers-for-woocommerce' );

			return;
		}

		esc_html_e( 'No article has stock management switched on, so there is nothing to reorder yet.', 'oxysuppliers-for-woocommerce' );
	}

	/**
	 * Send the current view as a CSV file.
	 *
	 * @return void
	 */
	public function handle_export(): void {
		check_admin_referer( self::EXPORT_ACTION );

		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to export this.', 'oxysuppliers-for-woocommerce' ), 403 );
		}

		$query             = $this->query_from_request();
		$query['per_page'] = 500;
		$query['page']     = 1;

		$rows = $this->calculator->calculate_all( $this->catalogue->paginate( $query ) );

		$columns = array(
			__( 'SKU', 'oxysuppliers-for-woocommerce' ),
			__( 'Article', 'oxysuppliers-for-woocommerce' ),
			__( 'Stock', 'oxysuppliers-for-woocommerce' ),
			__( 'Reserved', 'oxysuppliers-for-woocommerce' ),
			__( 'On the way', 'oxysuppliers-for-woocommerce' ),
			__( 'Available', 'oxysuppliers-for-woocommerce' ),
			__( 'Reorder point', 'oxysuppliers-for-woocommerce' ),
			__( 'Target', 'oxysuppliers-for-woocommerce' ),
			__( 'Sold 7', 'oxysuppliers-for-woocommerce' ),
			__( 'Sold 30', 'oxysuppliers-for-woocommerce' ),
			__( 'Sold 90', 'oxysuppliers-for-woocommerce' ),
			__( 'Supplier', 'oxysuppliers-for-woocommerce' ),
			__( 'Supplier code', 'oxysuppliers-for-woocommerce' ),
			__( 'Unit cost', 'oxysuppliers-for-woocommerce' ),
			__( 'Currency', 'oxysuppliers-for-woocommerce' ),
			__( 'Short', 'oxysuppliers-for-woocommerce' ),
			__( 'To order', 'oxysuppliers-for-woocommerce' ),
			__( 'Order value', 'oxysuppliers-for-woocommerce' ),
			__( 'Status', 'oxysuppliers-for-woocommerce' ),
		);

		$lines = array();

		foreach ( $rows as $row ) {
			$article  = $row->context;
			$supplier = $article->supplier;

			$lines[] = array(
				$article->sku,
				$article->name,
				null === $article->stock ? '' : (string) $article->stock,
				(string) $article->reserved,
				(string) $article->incoming,
				(string) $article->available(),
				(string) $article->reorder_point,
				(string) $article->target,
				(string) $article->sold_7,
				(string) $article->sold_30,
				(string) $article->sold_90,
				null === $supplier ? '' : $this->supplier_name( $supplier->supplier_id ),
				null === $supplier ? '' : $supplier->supplier_sku,
				null === $supplier ? '' : $supplier->unit_cost->to_decimal(),
				null === $supplier ? '' : $supplier->unit_cost->currency,
				(string) $row->needed,
				(string) $row->suggested,
				null === $row->value ? '' : $row->value->to_decimal(),
				$row->status->value,
			);
		}

		( new CsvWriter() )->send( 'oxysuppliers-reordering', $columns, $lines );
	}

	/**
	 * Turn the ticked rows into draft purchase orders.
	 *
	 * The quantities are **worked out again here**, from the stock as it is
	 * now. What was on the screen a minute ago was true a minute ago, and an
	 * order placed from a stale number is an order for the wrong amount.
	 *
	 * @return void
	 */
	public function handle_propose(): void {
		check_admin_referer( self::PROPOSE_ACTION );

		if ( ! current_user_can( Capabilities::CREATE_ORDERS ) ) {
			wp_die( esc_html__( 'You are not allowed to create purchase orders.', 'oxysuppliers-for-woocommerce' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
		$ticked = isset( $_POST['articles'] ) ? map_deep( wp_unslash( $_POST['articles'] ), 'sanitize_text_field' ) : array();

		$articles = array();

		foreach ( (array) $ticked as $key ) {
			$parts = explode( ':', (string) $key );

			if ( 2 === count( $parts ) && (int) $parts[0] > 0 ) {
				$articles[] = array( (int) $parts[0], (int) $parts[1] );
			}
		}

		if ( array() === $articles ) {
			wp_safe_redirect( Menu::url( self::SLUG, array( 'notice' => 'nothing_ticked' ) ) );
			exit;
		}

		$rows = $this->calculator->calculate_all(
			$this->catalogue->paginate(
				array(
					'articles' => $articles,
					'per_page' => 500,
				)
			)
		);

		$made = $this->proposals->build( $rows, gmdate( 'Y-m-d' ) );

		if ( array() === $made ) {
			wp_safe_redirect( Menu::url( self::SLUG, array( 'notice' => 'nothing_to_order' ) ) );
			exit;
		}

		wp_safe_redirect( Menu::url( PurchaseOrdersScreen::SLUG, array( 'notice' => 'proposed' ) ) );
		exit;
	}

	/**
	 * Show whatever the last action left behind.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Displays a fixed message chosen from a list.
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';

		$messages = array(
			'nothing_ticked'   => __( 'Tick the rows you want to order first.', 'oxysuppliers-for-woocommerce' ),
			'nothing_to_order' => __( 'Nothing on those rows could be ordered: they have no supplier, or nothing is missing.', 'oxysuppliers-for-woocommerce' ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			esc_html( $messages[ $notice ] )
		);
	}

	/**
	 * The filters, read from the address bar.
	 *
	 * @return array<string,mixed>
	 */
	private function query_from_request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only listing and filtering.
		$query = array(
			'search'              => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'supplier_id'         => isset( $_GET['supplier_id'] ) ? absint( wp_unslash( $_GET['supplier_id'] ) ) : 0,
			'category_id'         => isset( $_GET['category_id'] ) ? absint( wp_unslash( $_GET['category_id'] ) ) : 0,
			'below_reorder_point' => isset( $_GET['below'] ),
			'out_of_stock'        => isset( $_GET['out'] ),
			'without_supplier'    => isset( $_GET['no_supplier'] ),
			'without_cost'        => isset( $_GET['no_cost'] ),
			'orderby'             => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'shortfall',
			'order'               => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'asc',
			'page'                => isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1,
			'per_page'            => self::PER_PAGE,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $query;
	}

	/**
	 * Where the export lives, carrying the same filters.
	 *
	 * @param array<string,mixed> $query Current query.
	 * @return string
	 */
	private function export_url( array $query ): string {
		$arguments = array( 'action' => self::EXPORT_ACTION );

		if ( '' !== (string) $query['search'] ) {
			$arguments['s'] = (string) $query['search'];
		}

		foreach ( array( 'supplier_id', 'category_id' ) as $field ) {
			if ( $query[ $field ] > 0 ) {
				$arguments[ $field ] = $query[ $field ];
			}
		}

		foreach ( array(
			'below'       => 'below_reorder_point',
			'out'         => 'out_of_stock',
			'no_supplier' => 'without_supplier',
			'no_cost'     => 'without_cost',
		) as $parameter => $field ) {
			if ( ! empty( $query[ $field ] ) ) {
				$arguments[ $parameter ] = '1';
			}
		}

		return wp_nonce_url( add_query_arg( $arguments, admin_url( 'admin-post.php' ) ), self::EXPORT_ACTION );
	}

	/**
	 * A supplier's name.
	 *
	 * Every supplier is fetched once, in one query, the first time a name is
	 * wanted. Looking each one up as its row is drawn would be a query per
	 * supplier, which is the same mistake as a query per row wearing a hat.
	 *
	 * @param int $supplier_id Supplier id.
	 * @return string
	 */
	private function supplier_name( int $supplier_id ): string {
		if ( null === $this->supplier_names ) {
			$this->supplier_names = array();

			foreach ( $this->suppliers->paginate( array( 'per_page' => 500 ) ) as $supplier ) {
				$this->supplier_names[ $supplier->id ] = $supplier->display_name();
			}
		}

		return $this->supplier_names[ $supplier_id ] ?? '';
	}
}
