<?php
/**
 * What one supplier charges for one article.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Domain;

/**
 * A line of a supplier's price list.
 *
 * The article is a product, or a variation of one. `variation_id` is 0 rather
 * than null for a simple product, because null in a unique index does not stop
 * duplicates, and stopping duplicates is the whole point of that index.
 */
final class SupplierProduct {

	/**
	 * How long each text field may be, matching the columns that hold them.
	 *
	 * @var array<string,int>
	 */
	public const MAX_LENGTHS = array(
		'supplier_sku'         => 100,
		'supplier_description' => 191,
		'notes'                => 65535,
	);

	/**
	 * Hold a price list line.
	 *
	 * @param int         $id                   Row id, 0 when not yet stored.
	 * @param int         $supplier_id          Who sells it.
	 * @param int         $product_id           WooCommerce product.
	 * @param int         $variation_id         Variation, 0 when not a variation.
	 * @param string      $supplier_sku         Their code for it, which is what goes on the order.
	 * @param string      $supplier_description Their description, when it differs from ours.
	 * @param Money       $unit_cost            What they charge.
	 * @param OrderTerms  $terms                Minimum, multiple and pack size.
	 * @param int         $lead_time_days       Days from order to delivery, when it differs from the supplier's usual.
	 * @param Money|null  $last_cost            What they last actually charged.
	 * @param string|null $last_cost_at         When that was, as "Y-m-d H:i:s", or null.
	 * @param bool        $is_preferred         Whether this is the one to buy from by default.
	 * @param string      $notes                Internal notes.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $supplier_id,
		public readonly int $product_id,
		public readonly int $variation_id,
		public readonly string $supplier_sku,
		public readonly string $supplier_description,
		public readonly Money $unit_cost,
		public readonly OrderTerms $terms,
		public readonly int $lead_time_days,
		public readonly ?Money $last_cost,
		public readonly ?string $last_cost_at,
		public readonly bool $is_preferred,
		public readonly string $notes
	) {
	}

	/**
	 * Whether this line is about a variation rather than a whole product.
	 *
	 * @return bool
	 */
	public function is_variation(): bool {
		return 0 !== $this->variation_id;
	}

	/**
	 * What one order of a given quantity would cost.
	 *
	 * @param int $quantity How many.
	 * @return Money
	 */
	public function cost_of( int $quantity ): Money {
		return $this->unit_cost->multiply( max( 0, $quantity ) );
	}

	/**
	 * The same line with an id, as it comes back from an insert.
	 *
	 * @param int $id Row id.
	 * @return self
	 */
	public function with_id( int $id ): self {
		return new self(
			$id,
			$this->supplier_id,
			$this->product_id,
			$this->variation_id,
			$this->supplier_sku,
			$this->supplier_description,
			$this->unit_cost,
			$this->terms,
			$this->lead_time_days,
			$this->last_cost,
			$this->last_cost_at,
			$this->is_preferred,
			$this->notes
		);
	}

	/**
	 * The same line, told whether it is the preferred one.
	 *
	 * @param bool $preferred Whether it is preferred.
	 * @return self
	 */
	public function with_preferred( bool $preferred ): self {
		return new self(
			$this->id,
			$this->supplier_id,
			$this->product_id,
			$this->variation_id,
			$this->supplier_sku,
			$this->supplier_description,
			$this->unit_cost,
			$this->terms,
			$this->lead_time_days,
			$this->last_cost,
			$this->last_cost_at,
			$preferred,
			$this->notes
		);
	}

	/**
	 * Build from validated field values.
	 *
	 * @param array<string,mixed> $fields Field values keyed by name.
	 * @param int                 $id     Row id, 0 for a new line.
	 * @return self
	 * @throws InvalidArgument When the currency or an amount cannot be represented.
	 */
	public static function from_fields( array $fields, int $id = 0 ): self {
		$currency = Currency::normalise( (string) ( $fields['currency'] ?? 'EUR' ) );
		$cost     = trim( (string) ( $fields['unit_cost'] ?? '' ) );

		return new self(
			$id,
			(int) ( $fields['supplier_id'] ?? 0 ),
			(int) ( $fields['product_id'] ?? 0 ),
			(int) ( $fields['variation_id'] ?? 0 ),
			trim( (string) ( $fields['supplier_sku'] ?? '' ) ),
			trim( (string) ( $fields['supplier_description'] ?? '' ) ),
			Money::from_decimal( '' === $cost ? '0' : $cost, $currency ),
			new OrderTerms(
				max( 0, (int) ( $fields['min_order_qty'] ?? 0 ) ),
				max( 0, (int) ( $fields['order_multiple'] ?? 1 ) ),
				max( 0, (int) ( $fields['pack_qty'] ?? 1 ) )
			),
			max( 0, (int) ( $fields['lead_time_days'] ?? 0 ) ),
			null,
			null,
			! empty( $fields['is_preferred'] ),
			trim( (string) ( $fields['notes'] ?? '' ) )
		);
	}
}
