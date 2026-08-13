<?php
/**
 * What a row of the reordering screen is telling you.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Domain;

/**
 * The state of one article, in the order of how much it wants attention.
 *
 * The two "incomplete" states matter as much as the two shortages: an article
 * that is running out and has no supplier is a problem you cannot solve by
 * ordering, and hiding it would make the screen look tidier and be less true.
 */
enum RequirementStatus: string {

	/** Enough on the shelf, or on its way. */
	case OK = 'ok';

	/** At or under the level the shop wanted to buy at. */
	case BELOW_REORDER_POINT = 'below_reorder_point';

	/** Nothing left to sell. */
	case OUT_OF_STOCK = 'out_of_stock';

	/** Needs buying, and nobody is on file who sells it. */
	case NO_SUPPLIER = 'no_supplier';

	/** Has a supplier, who has no price. */
	case NO_COST = 'no_cost';

	/** WooCommerce is not counting this one. */
	case NOT_TRACKED = 'not_tracked';

	/**
	 * Whether this state is one somebody has to do something about.
	 *
	 * @return bool
	 */
	public function needs_attention(): bool {
		return self::OK !== $this && self::NOT_TRACKED !== $this;
	}

	/**
	 * Whether the row is missing something the shop has to fill in.
	 *
	 * @return bool
	 */
	public function is_incomplete(): bool {
		return self::NO_SUPPLIER === $this || self::NO_COST === $this;
	}
}
