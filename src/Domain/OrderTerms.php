<?php
/**
 * What a supplier will actually accept as a quantity.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Domain;

/*
 * Developer-facing exception messages in a layer with no WordPress in it:
 * esc_html() does not exist here and nothing here is ever echoed.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Minimum, order multiple and pack size, and the one calculation that uses them.
 *
 * Working out that you need fifteen is the easy half. The other half is that the
 * supplier sells in boxes of ten and will not look at an order under twenty, and
 * getting that wrong means an order that comes back rejected — or, worse, one
 * that is quietly changed by the supplier and no longer matches what was
 * ordered.
 */
final class OrderTerms {

	/**
	 * Take the terms.
	 *
	 * A minimum of zero means there is no minimum. A multiple or pack size of
	 * zero means the same as one: no constraint. Storing them that way keeps
	 * "not filled in" and "one at a time" from having to be told apart
	 * everywhere else.
	 *
	 * @param int $minimum  Smallest quantity the supplier will accept.
	 * @param int $multiple Quantity must be a multiple of this.
	 * @param int $pack     Units per pack, when only whole packs are sold.
	 * @throws InvalidArgument When any of them is negative.
	 */
	public function __construct(
		public readonly int $minimum = 0,
		public readonly int $multiple = 1,
		public readonly int $pack = 1
	) {
		if ( $minimum < 0 || $multiple < 0 || $pack < 0 ) {
			throw new InvalidArgument( 'Order terms cannot be negative.' );
		}
	}

	/**
	 * Terms that constrain nothing.
	 *
	 * @return self
	 */
	public static function none(): self {
		return new self( 0, 1, 1 );
	}

	/**
	 * The smallest step the supplier deals in.
	 *
	 * A supplier who sells in packs of six **and** in multiples of ten will only
	 * accept quantities that satisfy both, which is every thirty. Taking the
	 * larger of the two would propose an order the supplier cannot fill; taking
	 * the least common multiple is the only answer that is always valid.
	 *
	 * @return int Always at least 1.
	 */
	public function step(): int {
		$multiple = max( 1, $this->multiple );
		$pack     = max( 1, $this->pack );

		if ( $multiple === $pack ) {
			return $multiple;
		}

		return intdiv( $multiple, self::greatest_common_divisor( $multiple, $pack ) ) * $pack;
	}

	/**
	 * The quantity to order for a given need.
	 *
	 * Needing nothing means ordering nothing: a minimum order quantity is a
	 * condition of buying, not a reason to buy. Everything else is rounded up,
	 * never down, because ordering less than is needed leaves the shortage in
	 * place and hides it behind an order that looks like it was placed.
	 *
	 * @param int $needed How many are actually wanted.
	 * @return int
	 */
	public function round_up( int $needed ): int {
		if ( $needed <= 0 ) {
			return 0;
		}

		$step   = $this->step();
		$wanted = max( $needed, $this->minimum );

		// The minimum is rounded up to the step as well: a supplier asking for
		// at least ten, in packs of four, means twelve.
		return (int) ( ceil( $wanted / $step ) * $step );
	}

	/**
	 * Whether a quantity is one the supplier would accept.
	 *
	 * @param int $quantity Quantity to check.
	 * @return bool
	 */
	public function accepts( int $quantity ): bool {
		if ( $quantity <= 0 ) {
			return false;
		}

		return $quantity >= $this->minimum && 0 === $quantity % $this->step();
	}

	/**
	 * Euclid.
	 *
	 * @param int $first  First number.
	 * @param int $second Second number.
	 * @return int
	 */
	private static function greatest_common_divisor( int $first, int $second ): int {
		while ( 0 !== $second ) {
			$remainder = $first % $second;
			$first     = $second;
			$second    = $remainder;
		}

		return $first;
	}
}
