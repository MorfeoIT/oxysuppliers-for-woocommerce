<?php
/**
 * The reports tab.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Admin;

use Oxysoft\OxySuppliers\Domain\Money;
use Oxysoft\OxySuppliers\Domain\PurchaseOrderStatus;
use Oxysoft\OxySuppliers\Persistence\CatalogueRepository;
use Oxysoft\OxySuppliers\Persistence\PurchaseOrderRepository;
use Oxysoft\OxySuppliers\Support\Capabilities;

/**
 * Four numbers, and a way to act on each of them.
 *
 * The free reports of the specification (§18). Deliberately four numbers rather
 * than four charts: somebody opening this wants to know whether there is
 * anything to do this morning, and a figure that cannot be clicked through to
 * the thing it counts is trivia.
 */
final class ReportsScreen implements Screen {

	public const SLUG = 'reports';

	/**
	 * Take the collaborators.
	 *
	 * @param PurchaseOrderRepository $orders    Storage for orders.
	 * @param CatalogueRepository     $catalogue Stock and thresholds.
	 */
	public function __construct(
		private readonly PurchaseOrderRepository $orders,
		private readonly CatalogueRepository $catalogue
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
		return __( 'Reports', 'oxysuppliers-for-woocommerce' );
	}

	/**
	 * Capability required to open it.
	 *
	 * @return string
	 */
	public function capability(): string {
		return Capabilities::VIEW_REPORTS;
	}

	/**
	 * Nothing to hook up.
	 *
	 * @return void
	 */
	public function register(): void {
	}

	/**
	 * Draw the screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to see the reports.', 'oxysuppliers-for-woocommerce' ), 403 );
		}

		$open      = array( 'status' => PurchaseOrderStatus::expected_values() );
		$open_ids  = array_map( static fn ( $order ): int => $order->id, $this->orders->paginate( array_merge( $open, array( 'per_page' => 200 ) ) ) );
		$totals    = $this->orders->totals_for( $open_ids );
		$committed = array_sum( $totals );

		$below = $this->catalogue->count( array( 'below_reorder_point' => true ) );
		$late  = $this->orders->count( array( 'overdue' => true ) );
		$gaps  = $this->catalogue->count( array( 'without_supplier' => true ) );

		?>
		<h1><?php esc_html_e( 'Reports', 'oxysuppliers-for-woocommerce' ); ?></h1>

		<div class="oxysuppliers-cards">
			<?php
			$this->render_card(
				__( 'Money committed', 'oxysuppliers-for-woocommerce' ),
				Money::from_minor( (int) $committed, get_woocommerce_currency() )->to_decimal() . ' ' . get_woocommerce_currency(),
				__( 'On orders that have gone out and not arrived.', 'oxysuppliers-for-woocommerce' ),
				Menu::url( PurchaseOrdersScreen::SLUG, array( 'status' => PurchaseOrderStatus::SENT->value ) ),
				__( 'See the orders', 'oxysuppliers-for-woocommerce' )
			);

			$this->render_card(
				__( 'Open orders', 'oxysuppliers-for-woocommerce' ),
				(string) count( $open_ids ),
				__( 'Sent, confirmed or partly received.', 'oxysuppliers-for-woocommerce' ),
				Menu::url( PurchaseOrdersScreen::SLUG ),
				__( 'See the orders', 'oxysuppliers-for-woocommerce' )
			);

			$this->render_card(
				__( 'Below the reorder point', 'oxysuppliers-for-woocommerce' ),
				(string) $below,
				__( 'Articles at or under the level the shop wanted to buy at.', 'oxysuppliers-for-woocommerce' ),
				Menu::url( RequirementsScreen::SLUG, array( 'below' => '1' ) ),
				__( 'See what to reorder', 'oxysuppliers-for-woocommerce' ),
				$below > 0
			);

			$this->render_card(
				__( 'Late', 'oxysuppliers-for-woocommerce' ),
				(string) $late,
				__( 'Orders that should have arrived by now.', 'oxysuppliers-for-woocommerce' ),
				Menu::url( PurchaseOrdersScreen::SLUG, array( 'overdue' => '1' ) ),
				__( 'See the late ones', 'oxysuppliers-for-woocommerce' ),
				$late > 0
			);
			?>
		</div>

		<?php if ( $gaps > 0 ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of articles. */
							_n(
								'%d article has nobody on file who sells it, so it can never be reordered.',
								'%d articles have nobody on file who sells them, so they can never be reordered.',
								$gaps,
								'oxysuppliers-for-woocommerce'
							),
							$gaps
						)
					);
					?>
					<a href="<?php echo esc_url( Menu::url( RequirementsScreen::SLUG, array( 'no_supplier' => '1' ) ) ); ?>">
						<?php esc_html_e( 'Fill them in', 'oxysuppliers-for-woocommerce' ); ?>
					</a>
				</p>
			</div>
		<?php endif; ?>

		<p class="description">
			<?php esc_html_e( 'Cost history, supplier comparison, delivery reliability and spend by supplier are in the paid add-on.', 'oxysuppliers-for-woocommerce' ); ?>
		</p>
		<?php
	}

	/**
	 * One number, and where to go about it.
	 *
	 * @param string $title    What it counts.
	 * @param string $figure   The number.
	 * @param string $detail   What it means.
	 * @param string $url      Where to act on it.
	 * @param string $action   What the link says.
	 * @param bool   $wanted   Whether it wants attention.
	 * @return void
	 */
	private function render_card( string $title, string $figure, string $detail, string $url, string $action, bool $wanted = false ): void {
		?>
		<div class="oxysuppliers-card <?php echo $wanted ? 'wants-attention' : ''; ?>">
			<h2><?php echo esc_html( $title ); ?></h2>
			<p class="figure"><?php echo esc_html( $figure ); ?></p>
			<p class="description"><?php echo esc_html( $detail ); ?></p>
			<p><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $action ); ?></a></p>
		</div>
		<?php
	}
}
