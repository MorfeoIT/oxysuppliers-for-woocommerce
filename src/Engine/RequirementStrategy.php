<?php
/**
 * How much of something is needed.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Engine;

use Oxysoft\OxySuppliers\Domain\RequirementContext;

/**
 * One question, one answer: how many units short is this article.
 *
 * This is the seam the pro plugin replaces, and it is deliberately narrow. The
 * rounding to the supplier's terms and the state of the row are worked out
 * elsewhere, once, so that a second implementation of this interface cannot
 * quietly disagree with the first about anything except the number it was asked
 * for.
 *
 * See docs/03_FREE_VS_PRO.md, rule 1: the pro plugin contains a provider, not a
 * copy of the formula.
 */
interface RequirementStrategy {

	/**
	 * How many units are missing.
	 *
	 * Zero or less means nothing to do. Implementations must not round: that
	 * happens once, afterwards, for every strategy.
	 *
	 * @param RequirementContext $context The article and its numbers.
	 * @return int
	 */
	public function needed( RequirementContext $context ): int;

	/**
	 * Short identifier, recorded so a suggestion can be explained later.
	 *
	 * @return string
	 */
	public function name(): string;
}
