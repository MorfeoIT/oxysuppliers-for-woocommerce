<?php
/**
 * The answer for one article.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Domain;

/**
 * What to order, from whom, and what it would cost.
 *
 * Carries the facts it was worked out from, so the screen and the CSV do not
 * have to hold two objects side by side and keep them in step.
 */
final class Requirement {

	/**
	 * Hold the answer.
	 *
	 * @param RequirementContext $context   What it was worked out from.
	 * @param int                $needed    Units short, before the supplier's terms.
	 * @param int                $suggested Units to order, after them.
	 * @param Money|null         $value     What that would cost, or null without a price.
	 * @param RequirementStatus  $status    What the row is saying.
	 */
	public function __construct(
		public readonly RequirementContext $context,
		public readonly int $needed,
		public readonly int $suggested,
		public readonly ?Money $value,
		public readonly RequirementStatus $status
	) {
	}

	/**
	 * Whether there is something to order.
	 *
	 * @return bool
	 */
	public function is_orderable(): bool {
		return $this->suggested > 0 && null !== $this->context->supplier;
	}

	/**
	 * The supplier's terms rounded the quantity up past what is needed.
	 *
	 * Worth showing: somebody looking at "I need 15, it says 30" deserves to be
	 * told it is the pack size and not a mistake.
	 *
	 * @return bool
	 */
	public function was_rounded_up(): bool {
		return $this->suggested > $this->needed && $this->needed > 0;
	}
}
