<?php
/**
 * The free plugin's way of deciding how much is missing.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Engine;

use Oxysoft\OxySuppliers\Domain\RequirementContext;

/**
 * Fill it back up to the target, counting what is already on its way: the
 * shortage is the target, less what can be sold, less what has been ordered.
 *
 * The specification (§6) puts "goods already ordered and not yet received" in
 * the pro plugin. It is here instead, and on purpose: not subtracting it means
 * telling somebody to reorder what is already in a van, so they order twice and
 * pay twice. What is genuinely worth paying for is the hard part — predicting
 * what will sell *during* the lead time — and that is what the pro strategy
 * does.
 *
 * An article WooCommerce is not counting is never short: there is no number to
 * be short of.
 */
final class TargetStockStrategy implements RequirementStrategy {

	/**
	 * How many units are missing.
	 *
	 * @param RequirementContext $context The article and its numbers.
	 * @return int
	 */
	public function needed( RequirementContext $context ): int {
		if ( ! $context->is_stock_managed() ) {
			return 0;
		}

		return max( 0, $context->target - $context->available() - $context->incoming );
	}

	/**
	 * Identify this strategy.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'target_stock';
	}
}
