<?php
/**
 * Telling OxyProfit what things cost.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Integration;

use Oxysoft\OxySuppliers\Persistence\CostHistoryRepository;

use function Oxysoft\OxySuppliers\plugin_dir;

/**
 * The one place that knows OxyProfit might be installed.
 *
 * Two rules govern this file, and both were paid for elsewhere in the family:
 *
 * **The class that implements their interface lives in a separate file**, and
 * that file is included only after the interface is known to exist. PHP resolves
 * an interface when it *loads* the file, not when the class is first used, so a
 * check inside a constructor arrives after the fatal error. `Requires Plugins`
 * governs activation, not include order, and nothing decides the order between
 * two plugins anyway.
 *
 * **The check uses the full name as a string.** Without the `use` statement,
 * `CostSource::class` would resolve inside *this* namespace — that is, to
 * nothing — and the check would always answer no.
 *
 * A shop that has never heard of OxyProfit notices none of this: the hook fires,
 * the interface is not there, and nothing else happens.
 */
final class OxyProfit {

	/**
	 * The interface OxyProfit asks cost sources to implement.
	 */
	private const COST_SOURCE = 'Oxysoft\\OxyProfit\\Engine\\CostSource';

	/**
	 * Listen for OxyProfit, without depending on it.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'plugins_loaded', array( self::class, 'maybe_register' ), 20 );
	}

	/**
	 * Offer our costs to OxyProfit, if OxyProfit is there.
	 *
	 * @return void
	 */
	public static function maybe_register(): void {
		if ( ! interface_exists( self::COST_SOURCE ) ) {
			return;
		}

		// Outside src/, where the autoloader cannot reach it: see the note at
		// the top of that file.
		require_once plugin_dir() . 'integrations/oxyprofit-cost-source.php';

		add_filter(
			'oxyprofit_cost_sources',
			static function ( $sources ) {
				if ( ! is_array( $sources ) ) {
					$sources = array();
				}

				// First in the list: a cost somebody actually paid a supplier
				// beats one typed into a product screen months ago.
				array_unshift( $sources, new OxyProfitCostSource( new CostHistoryRepository() ) );

				return $sources;
			}
		);
	}
}
