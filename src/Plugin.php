<?php
/**
 * Wiring.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers;

use Oxysoft\OxySuppliers\Admin\ImportScreen;
use Oxysoft\OxySuppliers\Admin\Menu;
use Oxysoft\OxySuppliers\Admin\ProductSupplierPanel;
use Oxysoft\OxySuppliers\Admin\PurchaseOrdersScreen;
use Oxysoft\OxySuppliers\Admin\ReportsScreen;
use Oxysoft\OxySuppliers\Admin\RequirementsScreen;
use Oxysoft\OxySuppliers\Admin\SuppliersScreen;
use Oxysoft\OxySuppliers\Domain\SupplierProductValidator;
use Oxysoft\OxySuppliers\Domain\SupplierValidator;
use Oxysoft\OxySuppliers\Engine\RequirementCalculator;
use Oxysoft\OxySuppliers\Engine\RequirementStrategy;
use Oxysoft\OxySuppliers\Engine\TargetStockStrategy;
use Oxysoft\OxySuppliers\Import\PriceListImporter;
use Oxysoft\OxySuppliers\Import\SupplierCsvParser;
use Oxysoft\OxySuppliers\Integration\OxyProfit as OxyProfitIntegration;
use Oxysoft\OxySuppliers\Persistence\CatalogueRepository;
use Oxysoft\OxySuppliers\Persistence\CostHistoryRepository;
use Oxysoft\OxySuppliers\Persistence\Migrator;
use Oxysoft\OxySuppliers\Persistence\PurchaseOrderRepository;
use Oxysoft\OxySuppliers\Persistence\ReceiptRepository;
use Oxysoft\OxySuppliers\Persistence\SupplierProductRepository;
use Oxysoft\OxySuppliers\Persistence\SupplierRepository;
use Oxysoft\OxySuppliers\Pdf\PdfRenderer;
use Oxysoft\OxySuppliers\Rest\Controller as RestController;
use Oxysoft\OxySuppliers\Pdf\PurchaseOrderDocument;
use Oxysoft\OxySuppliers\Service\AuditLogger;
use Oxysoft\OxySuppliers\Service\GoodsReceiver;
use Oxysoft\OxySuppliers\Service\ProposalBuilder;
use Oxysoft\OxySuppliers\Service\PurchaseOrderMailer;
use Oxysoft\OxySuppliers\Service\PurchaseOrderNumbers;
use Oxysoft\OxySuppliers\Support\Capabilities;

/**
 * Builds the object graph and registers the hooks.
 *
 * Deliberately plain: a container would add a dependency to solve a problem
 * this plugin does not have.
 */
final class Plugin {

	/**
	 * Start the plugin.
	 *
	 * @return void
	 */
	public function boot(): void {
		$migrator = new Migrator();

		// Not only on activation. A network activation never runs the activation
		// hook for the individual sites, and an update that adds a table has no
		// activation to hang off either.
		if ( $migrator->needs_migration() ) {
			$migrator->migrate();
		}

		Capabilities::ensure_granted();

		// Outside is_admin(): what an article costs is asked on the front end
		// and by other plugins' background work, not only on a screen.
		OxyProfitIntegration::register();

		$suppliers  = new SupplierRepository();
		$listings   = new SupplierProductRepository();
		$audit      = new AuditLogger();
		$orders     = new PurchaseOrderRepository( new PurchaseOrderNumbers() );
		$catalogue  = new CatalogueRepository();
		$calculator = new RequirementCalculator( $this->requirement_strategy() );

		// Outside is_admin(): the REST API is not an admin request, and its own
		// permission callbacks are what guard it.
		( new RestController( $catalogue, $calculator, $orders, $suppliers ) )->register();

		if ( is_admin() ) {
			$menu = new Menu();

			// First tab, because it is the question the plugin exists to
			// answer.
			$menu->add_tab(
				new RequirementsScreen(
					$catalogue,
					$calculator,
					$suppliers,
					new ProposalBuilder( $orders, $suppliers )
				)
			);

			$document = new PurchaseOrderDocument();
			$renderer = new PdfRenderer();
			$receipts = new ReceiptRepository();

			$menu->add_tab(
				new PurchaseOrdersScreen(
					$orders,
					$suppliers,
					$listings,
					$audit,
					$document,
					$renderer,
					new PurchaseOrderMailer( $document, $renderer, $audit ),
					$receipts,
					new GoodsReceiver( $receipts, $orders, $audit, new CostHistoryRepository() )
				)
			);
			$menu->add_tab( new SuppliersScreen( $suppliers, new SupplierValidator(), $audit ) );
			$menu->add_tab( new ReportsScreen( $orders, $catalogue ) );
			$menu->add_tab(
				new ImportScreen(
					new SupplierCsvParser(),
					new PriceListImporter( $suppliers, $listings )
				)
			);

			$menu->register();

			( new ProductSupplierPanel(
				$listings,
				$suppliers,
				new SupplierProductValidator(),
				$audit,
				$orders
			) )->register();
		}
	}

	/**
	 * How this shop decides what it is short of.
	 *
	 * The filter holds **one** strategy: a pro plugin's replaces the free one
	 * rather than adding to it. Anything that is not a strategy is dropped
	 * rather than trusted, so a badly written add-on cannot leave the screen
	 * with nothing to ask.
	 *
	 * @return RequirementStrategy
	 */
	private function requirement_strategy(): RequirementStrategy {
		$free = new TargetStockStrategy();

		/**
		 * Filters the strategy that decides how much of something is needed.
		 *
		 * @since 0.1.0
		 *
		 * @param RequirementStrategy $strategy The free strategy.
		 */
		$chosen = apply_filters( 'oxysuppliers_requirement_strategy', $free );

		return $chosen instanceof RequirementStrategy ? $chosen : $free;
	}

	/**
	 * Run on activation, before anything else exists.
	 *
	 * @return void
	 */
	public static function activate(): void {
		( new Migrator() )->migrate();

		Capabilities::ensure_granted();
	}
}
