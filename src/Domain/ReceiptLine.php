<?php
/**
 * One article arriving.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Domain;

/**
 * What arrived against one line of a purchase order.
 *
 * The quantity is **signed**. A delivery is positive; reversing one is a
 * negative line pointing at the same order line. Nothing is ever deleted and no
 * quantity is ever rewritten, which is what makes the history readable a year
 * later: the receipt that was wrong is still there, and so is the one that
 * corrected it.
 */
final class ReceiptLine {

	/**
	 * Hold a line.
	 *
	 * @param int        $id           Row id, 0 when not yet stored.
	 * @param int        $po_line_id   The order line it is against.
	 * @param int        $product_id   WooCommerce product.
	 * @param int        $variation_id Variation, 0 when not a variation.
	 * @param int        $quantity     How many arrived. Negative reverses.
	 * @param Money|null $actual_cost  What they actually charged, when it is known.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $po_line_id,
		public readonly int $product_id,
		public readonly int $variation_id,
		public readonly int $quantity,
		public readonly ?Money $actual_cost = null
	) {
	}

	/**
	 * Whether this line undoes rather than delivers.
	 *
	 * @return bool
	 */
	public function is_reversal(): bool {
		return $this->quantity < 0;
	}

	/**
	 * The article, as WooCommerce counts it.
	 *
	 * Stock lives on the variation when there is one, and on the product when
	 * there is not.
	 *
	 * @return int
	 */
	public function stock_id(): int {
		return 0 !== $this->variation_id ? $this->variation_id : $this->product_id;
	}

	/**
	 * The same line, the other way round.
	 *
	 * @return self
	 */
	public function reversed(): self {
		return new self(
			0,
			$this->po_line_id,
			$this->product_id,
			$this->variation_id,
			- $this->quantity,
			$this->actual_cost
		);
	}
}
