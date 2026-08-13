<?php
/**
 * Turning a shortage into an order line.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Engine;

use Oxysoft\OxySuppliers\Domain\OrderTerms;
use Oxysoft\OxySuppliers\Domain\Requirement;
use Oxysoft\OxySuppliers\Domain\RequirementContext;
use Oxysoft\OxySuppliers\Domain\RequirementStatus;

/**
 * Asks the strategy how much is missing, then does the part that never changes.
 *
 * Rounding to the supplier's terms and working out what the row is saying live
 * here rather than in the strategy, so that swapping the strategy swaps one
 * number and nothing else. Nothing in this class calls WordPress.
 */
final class RequirementCalculator {

	/**
	 * Take the strategy to ask.
	 *
	 * @param RequirementStrategy $strategy How much is missing.
	 */
	public function __construct( private readonly RequirementStrategy $strategy ) {
	}

	/**
	 * Work out one article.
	 *
	 * @param RequirementContext $context The article and its numbers.
	 * @return Requirement
	 */
	public function calculate( RequirementContext $context ): Requirement {
		$needed = max( 0, $this->strategy->needed( $context ) );
		$terms  = null === $context->supplier ? OrderTerms::none() : $context->supplier->terms;

		$suggested = $terms->round_up( $needed );
		$value     = null;

		if ( null !== $context->supplier && $suggested > 0 ) {
			$value = $context->supplier->cost_of( $suggested );
		}

		return new Requirement( $context, $needed, $suggested, $value, $this->status_of( $context, $needed ) );
	}

	/**
	 * Work out a page of them.
	 *
	 * @param list<RequirementContext> $contexts The articles.
	 * @return list<Requirement>
	 */
	public function calculate_all( array $contexts ): array {
		return array_values( array_map( array( $this, 'calculate' ), $contexts ) );
	}

	/**
	 * What the row is saying.
	 *
	 * The order matters. A shortage nobody can fill is reported as the missing
	 * supplier rather than as the shortage: the shortage is visible in the
	 * numbers, the reason it cannot be fixed is not.
	 *
	 * @param RequirementContext $context The article.
	 * @param int                $needed  Units short.
	 * @return RequirementStatus
	 */
	private function status_of( RequirementContext $context, int $needed ): RequirementStatus {
		if ( ! $context->is_stock_managed() ) {
			return RequirementStatus::NOT_TRACKED;
		}

		$wants_buying = $needed > 0 || $context->is_below_reorder_point();

		if ( $wants_buying && null === $context->supplier ) {
			return RequirementStatus::NO_SUPPLIER;
		}

		if ( $wants_buying && null !== $context->supplier && $context->supplier->unit_cost->is_zero() ) {
			return RequirementStatus::NO_COST;
		}

		if ( $context->is_out_of_stock() ) {
			return RequirementStatus::OUT_OF_STOCK;
		}

		if ( $context->is_below_reorder_point() ) {
			return RequirementStatus::BELOW_REORDER_POINT;
		}

		return RequirementStatus::OK;
	}
}
