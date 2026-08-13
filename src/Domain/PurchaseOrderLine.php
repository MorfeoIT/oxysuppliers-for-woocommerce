<?php
/**
 * One line of a purchase order.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Domain;

/*
 * Developer-facing exception messages in a layer with no WordPress in it.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * What was ordered, how much of it, and what it costs.
 *
 * The line carries the supplier's own code and description as they were when
 * the order was placed. Reading them from the price list at print time would
 * make an order sent last month change its wording this month, which is not
 * what a document does.
 *
 * The outstanding quantity is not stored anywhere: it is ordered minus
 * received. A third number that can disagree with the other two is one more way
 * to be wrong.
 */
final class PurchaseOrderLine {

	/**
	 * Basis points in one whole, so a discount of 12.5% is 1250.
	 *
	 * Percentages are held as integers for the same reason as money: a rate of
	 * 0.1 is not a number a computer can hold, and three of them added up are
	 * visibly not 0.3.
	 */
	public const BASIS_POINTS = 10000;

	/**
	 * Hold a line.
	 *
	 * @param int    $id            Row id, 0 when not yet stored.
	 * @param int    $product_id    WooCommerce product.
	 * @param int    $variation_id  Variation, 0 when not a variation.
	 * @param string $sku           Our code.
	 * @param string $supplier_sku  Their code, which is what they will look for.
	 * @param string $description   What it is called on the order.
	 * @param int    $qty_ordered   How many were asked for.
	 * @param int    $qty_received  How many have arrived.
	 * @param Money  $unit_cost     Price each, before discount.
	 * @param int    $discount_bp   Discount in basis points.
	 * @param int    $tax_rate_bp   Tax rate in basis points.
	 * @param int    $sort_order    Position on the order.
	 * @throws InvalidArgument When a quantity or a rate is impossible.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $product_id,
		public readonly int $variation_id,
		public readonly string $sku,
		public readonly string $supplier_sku,
		public readonly string $description,
		public readonly int $qty_ordered,
		public readonly int $qty_received,
		public readonly Money $unit_cost,
		public readonly int $discount_bp = 0,
		public readonly int $tax_rate_bp = 0,
		public readonly int $sort_order = 0
	) {
		if ( $qty_ordered < 0 ) {
			throw new InvalidArgument( 'A purchase order line cannot ask for a negative quantity.' );
		}

		if ( $discount_bp < 0 || $discount_bp > self::BASIS_POINTS ) {
			throw new InvalidArgument( 'A discount runs from 0 to 100 per cent.' );
		}

		if ( $tax_rate_bp < 0 ) {
			throw new InvalidArgument( 'A tax rate cannot be negative.' );
		}
	}

	/**
	 * How many are still to come.
	 *
	 * Never negative: receiving more than was ordered is an over-delivery, not
	 * a negative outstanding quantity.
	 *
	 * @return int
	 */
	public function outstanding(): int {
		return max( 0, $this->qty_ordered - $this->qty_received );
	}

	/**
	 * Whether this line is complete.
	 *
	 * @return bool
	 */
	public function is_fully_received(): bool {
		return $this->qty_received >= $this->qty_ordered;
	}

	/**
	 * Whether more arrived than was asked for.
	 *
	 * @return bool
	 */
	public function is_over_received(): bool {
		return $this->qty_received > $this->qty_ordered;
	}

	/**
	 * What the line costs, discount applied, before tax.
	 *
	 * Rounded half up, on integers. Truncating would quietly favour the buyer
	 * by a penny a line, which is the kind of difference that turns into an
	 * argument with a supplier over an invoice.
	 *
	 * @return Money
	 */
	public function net_total(): Money {
		$gross = $this->qty_ordered * $this->unit_cost->minor;
		$net   = intdiv(
			$gross * ( self::BASIS_POINTS - $this->discount_bp ) + intdiv( self::BASIS_POINTS, 2 ),
			self::BASIS_POINTS
		);

		return Money::from_minor( $net, $this->unit_cost->currency );
	}

	/**
	 * The tax on the line.
	 *
	 * @return Money
	 */
	public function tax_total(): Money {
		$net = $this->net_total()->minor;
		$tax = intdiv( $net * $this->tax_rate_bp + intdiv( self::BASIS_POINTS, 2 ), self::BASIS_POINTS );

		return Money::from_minor( $tax, $this->unit_cost->currency );
	}

	/**
	 * The same line with an id.
	 *
	 * @param int $id Row id.
	 * @return self
	 */
	public function with_id( int $id ): self {
		return $this->copy( array( 'id' => $id ) );
	}

	/**
	 * The same line, told how many have arrived.
	 *
	 * @param int $received Total received so far.
	 * @return self
	 */
	public function with_received( int $received ): self {
		return $this->copy( array( 'qty_received' => max( 0, $received ) ) );
	}

	/**
	 * A copy with some fields changed.
	 *
	 * @param array<string,mixed> $changes Fields to change.
	 * @return self
	 */
	private function copy( array $changes ): self {
		return new self(
			(int) ( $changes['id'] ?? $this->id ),
			(int) ( $changes['product_id'] ?? $this->product_id ),
			(int) ( $changes['variation_id'] ?? $this->variation_id ),
			(string) ( $changes['sku'] ?? $this->sku ),
			(string) ( $changes['supplier_sku'] ?? $this->supplier_sku ),
			(string) ( $changes['description'] ?? $this->description ),
			(int) ( $changes['qty_ordered'] ?? $this->qty_ordered ),
			(int) ( $changes['qty_received'] ?? $this->qty_received ),
			$changes['unit_cost'] ?? $this->unit_cost,
			(int) ( $changes['discount_bp'] ?? $this->discount_bp ),
			(int) ( $changes['tax_rate_bp'] ?? $this->tax_rate_bp ),
			(int) ( $changes['sort_order'] ?? $this->sort_order )
		);
	}

	/**
	 * Build a line to buy an article from a supplier's price list.
	 *
	 * @param SupplierProduct $listing  What the supplier charges.
	 * @param int             $quantity How many to order.
	 * @param string          $sku      Our code for it.
	 * @param string          $name     What we call it.
	 * @param int             $position Position on the order.
	 * @return self
	 */
	public static function from_listing(
		SupplierProduct $listing,
		int $quantity,
		string $sku = '',
		string $name = '',
		int $position = 0
	): self {
		return new self(
			0,
			$listing->product_id,
			$listing->variation_id,
			$sku,
			$listing->supplier_sku,
			'' !== $listing->supplier_description ? $listing->supplier_description : $name,
			max( 0, $quantity ),
			0,
			$listing->unit_cost,
			0,
			0,
			$position
		);
	}
}
