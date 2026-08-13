<?php
/**
 * Everything needed to decide whether to reorder one article.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Domain;

/**
 * One row of the reordering screen, before anybody has worked anything out.
 *
 * Plain data, gathered in bulk and handed over: nothing in here goes and looks
 * something up. That is what keeps the number of queries the same whether the
 * page shows twenty rows or two hundred.
 */
final class RequirementContext {

	/**
	 * Hold the facts about one article.
	 *
	 * @param int                  $product_id    Parent product.
	 * @param int                  $variation_id  Variation, 0 when not a variation.
	 * @param string               $sku           Our code for it.
	 * @param string               $name          What it is called.
	 * @param int|null             $stock         On the shelf, or null when stock is not managed.
	 * @param int                  $reserved      Held for orders being paid for.
	 * @param int                  $incoming      Ordered from a supplier and not yet received.
	 * @param int                  $reorder_point The level at which the shop wants to buy more.
	 * @param int                  $target        How full to fill it back up.
	 * @param int                  $sold_7        Units sold in the last week.
	 * @param int                  $sold_30       Units sold in the last month.
	 * @param int                  $sold_90       Units sold in the last quarter.
	 * @param SupplierProduct|null $supplier      The price list line to buy from, if there is one.
	 */
	public function __construct(
		public readonly int $product_id,
		public readonly int $variation_id,
		public readonly string $sku,
		public readonly string $name,
		public readonly ?int $stock,
		public readonly int $reserved = 0,
		public readonly int $incoming = 0,
		public readonly int $reorder_point = 0,
		public readonly int $target = 0,
		public readonly int $sold_7 = 0,
		public readonly int $sold_30 = 0,
		public readonly int $sold_90 = 0,
		public readonly ?SupplierProduct $supplier = null
	) {
	}

	/**
	 * Whether this article is a variation rather than a whole product.
	 *
	 * @return bool
	 */
	public function is_variation(): bool {
		return 0 !== $this->variation_id;
	}

	/**
	 * Whether WooCommerce is counting this article at all.
	 *
	 * A service, or anything else with stock management off, has no shortage to
	 * report: it is not out of stock, it is not counted.
	 *
	 * @return bool
	 */
	public function is_stock_managed(): bool {
		return null !== $this->stock;
	}

	/**
	 * What can actually be sold: what is there, less what is spoken for.
	 *
	 * @return int
	 */
	public function available(): int {
		return (int) $this->stock - $this->reserved;
	}

	/**
	 * Whether the shop has run out.
	 *
	 * @return bool
	 */
	public function is_out_of_stock(): bool {
		return $this->is_stock_managed() && $this->available() <= 0;
	}

	/**
	 * Whether the shop is at or under the level it wanted to buy at.
	 *
	 * @return bool
	 */
	public function is_below_reorder_point(): bool {
		return $this->is_stock_managed() && $this->available() <= $this->reorder_point;
	}

	/**
	 * The same article, told what is on its way.
	 *
	 * @param int $incoming Units on order and not yet received.
	 * @return self
	 */
	public function with_incoming( int $incoming ): self {
		return new self(
			$this->product_id,
			$this->variation_id,
			$this->sku,
			$this->name,
			$this->stock,
			$this->reserved,
			$incoming,
			$this->reorder_point,
			$this->target,
			$this->sold_7,
			$this->sold_30,
			$this->sold_90,
			$this->supplier
		);
	}

	/**
	 * The same article, told who sells it.
	 *
	 * @param SupplierProduct|null $supplier The price list line, or null.
	 * @return self
	 */
	public function with_supplier( ?SupplierProduct $supplier ): self {
		return new self(
			$this->product_id,
			$this->variation_id,
			$this->sku,
			$this->name,
			$this->stock,
			$this->reserved,
			$this->incoming,
			$this->reorder_point,
			$this->target,
			$this->sold_7,
			$this->sold_30,
			$this->sold_90,
			$supplier
		);
	}

	/**
	 * A key that tells a variation apart from its parent.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->product_id . ':' . $this->variation_id;
	}
}
