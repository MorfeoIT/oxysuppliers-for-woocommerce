<?php
/**
 * What we paid, told to OxyProfit in its own words.
 *
 * **This file is loaded only after `interface_exists()` has said yes**, and it
 * lives outside `src/` on purpose: PHP resolves `implements` when a file loads,
 * so on a site without OxyProfit merely loading it is a fatal error. Out here
 * the autoloader cannot reach it even by accident — the only way in is the
 * explicit `require_once` in Integration\OxyProfit, after the check.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Integration;

use Oxysoft\OxyProfit\Domain\Money as ProfitMoney;
use Oxysoft\OxyProfit\Engine\CostQuery;
use Oxysoft\OxyProfit\Engine\CostSource;
use Oxysoft\OxySuppliers\Persistence\CostHistoryRepository;

/**
 * Answers "what did this cost on that date" from what suppliers actually
 * charged.
 *
 * The one rule of their interface that matters: **null when we do not know, and
 * never zero**. A cost of nothing is an answer; not knowing is not, and
 * confusing the two makes a profit look bigger than it was.
 */
final class OxyProfitCostSource implements CostSource {

	/**
	 * Take the record of what things have cost.
	 *
	 * @param CostHistoryRepository $costs The record.
	 */
	public function __construct( private readonly CostHistoryRepository $costs ) {
	}

	/**
	 * Identify this source on the snapshot, so a figure can be explained later.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'oxysuppliers';
	}

	/**
	 * The unit cost this source knows about, or null.
	 *
	 * @param CostQuery $query What is being asked.
	 * @return ProfitMoney|null
	 */
	public function unit_cost( CostQuery $query ): ?ProfitMoney {
		$cost = $this->costs->cost_on( $query->product_id, $query->variation_id, $query->date );

		// A variation we have never bought falls back to what the product cost:
		// the same article in a different size usually came from the same
		// invoice.
		if ( null === $cost && $query->is_variation() ) {
			$cost = $this->costs->cost_on( $query->product_id, 0, $query->date );
		}

		if ( null === $cost ) {
			return null;
		}

		// Two plugins, two Money classes, one seam between them. Converting
		// here is the only place the two worlds touch.
		if ( $cost->currency !== $query->currency ) {
			// Costs are held in the supplier's currency. Handing OxyProfit an
			// amount in the wrong one would be worse than saying nothing.
			return null;
		}

		return ProfitMoney::from_minor( $cost->minor, $cost->currency );
	}
}
