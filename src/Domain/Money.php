<?php
/**
 * An amount of money.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Domain;

/*
 * These exception messages are developer-facing and are never echoed: this
 * layer has no WordPress in it, so esc_html() does not exist here and the
 * escaping sniff has nothing to protect. The exclusion is stated in the code
 * rather than in phpcs.xml.dist because Plugin Check ignores that file.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Minor units and a currency, and never a float.
 *
 * Two rules the rest of the plugin depends on: an amount always carries its own
 * currency, and amounts in different currencies do not combine. Both are here so
 * that no caller has to remember them.
 */
final class Money {

	/**
	 * The widest decimal string this class will convert.
	 *
	 * Beyond this the integer cast stops being exact, and an amount that is
	 * silently not the amount you typed is worse than a refusal.
	 */
	private const MAX_DIGITS = 18;

	/**
	 * Hold an amount.
	 *
	 * @param int    $minor    Amount in minor units.
	 * @param string $currency Normalised three-letter code.
	 */
	private function __construct(
		public readonly int $minor,
		public readonly string $currency
	) {
	}

	/**
	 * Build from minor units.
	 *
	 * @param int    $minor    Amount in minor units.
	 * @param string $currency Currency code.
	 * @return self
	 */
	public static function from_minor( int $minor, string $currency ): self {
		return new self( $minor, Currency::normalise( $currency ) );
	}

	/**
	 * Nothing, in a currency.
	 *
	 * @param string $currency Currency code.
	 * @return self
	 */
	public static function zero( string $currency ): self {
		return self::from_minor( 0, $currency );
	}

	/**
	 * Build from a canonical decimal string, such as "11.80".
	 *
	 * The separator is always a full stop: locale is a presentation problem and
	 * is solved before the value gets here. Rounding is half away from zero, and
	 * done on the digits rather than through a float.
	 *
	 * @param string $amount   Decimal string.
	 * @param string $currency Currency code.
	 * @return self
	 * @throws InvalidArgument When the string is not a plain decimal number.
	 */
	public static function from_decimal( string $amount, string $currency ): self {
		$currency = Currency::normalise( $currency );
		$trimmed  = trim( $amount );
		$exponent = Currency::exponent( $currency );

		if ( 1 !== preg_match( '/^([+-]?)(\d+)(?:\.(\d+))?$/', $trimmed, $matches ) ) {
			throw new InvalidArgument( 'Not a decimal amount: ' . $amount );
		}

		$negative = '-' === $matches[1];
		$integer  = ltrim( $matches[2], '0' );
		$integer  = '' === $integer ? '0' : $integer;
		$fraction = $matches[3] ?? '';

		if ( strlen( $integer ) + $exponent > self::MAX_DIGITS ) {
			throw new InvalidArgument( 'Amount too large to represent exactly: ' . $amount );
		}

		// One digit more than we keep: that extra digit is the one that decides
		// the rounding.
		$fraction = str_pad( substr( $fraction, 0, $exponent + 1 ), $exponent + 1, '0' );
		$kept     = substr( $fraction, 0, $exponent );
		$next     = (int) $fraction[ $exponent ];

		$minor = (int) ( $integer . $kept );

		if ( $next >= 5 ) {
			++$minor;
		}

		return new self( $negative ? -$minor : $minor, $currency );
	}

	/**
	 * The amount as a canonical decimal string.
	 *
	 * @return string
	 */
	public function to_decimal(): string {
		$exponent = Currency::exponent( $this->currency );
		$sign     = $this->minor < 0 ? '-' : '';
		$digits   = (string) abs( $this->minor );

		if ( 0 === $exponent ) {
			return $sign . $digits;
		}

		$digits = str_pad( $digits, $exponent + 1, '0', STR_PAD_LEFT );

		return $sign . substr( $digits, 0, -$exponent ) . '.' . substr( $digits, -$exponent );
	}

	/**
	 * Whether this is nothing.
	 *
	 * @return bool
	 */
	public function is_zero(): bool {
		return 0 === $this->minor;
	}

	/**
	 * Add another amount of the same currency.
	 *
	 * @param self $other Amount to add.
	 * @return self
	 * @throws InvalidArgument When the currencies differ.
	 */
	public function add( self $other ): self {
		$this->assert_same_currency( $other );

		return new self( $this->minor + $other->minor, $this->currency );
	}

	/**
	 * Subtract another amount of the same currency.
	 *
	 * @param self $other Amount to subtract.
	 * @return self
	 * @throws InvalidArgument When the currencies differ.
	 */
	public function subtract( self $other ): self {
		$this->assert_same_currency( $other );

		return new self( $this->minor - $other->minor, $this->currency );
	}

	/**
	 * Multiply by a whole number, such as a quantity.
	 *
	 * @param int $factor Multiplier.
	 * @return self
	 */
	public function multiply( int $factor ): self {
		return new self( $this->minor * $factor, $this->currency );
	}

	/**
	 * Whether two amounts are the same amount of the same currency.
	 *
	 * @param self $other Amount to compare.
	 * @return bool
	 */
	public function equals( self $other ): bool {
		return $this->minor === $other->minor && $this->currency === $other->currency;
	}

	/**
	 * Refuse to mix currencies.
	 *
	 * @param self $other The other amount.
	 * @return void
	 * @throws InvalidArgument When the currencies differ.
	 */
	private function assert_same_currency( self $other ): void {
		if ( $this->currency !== $other->currency ) {
			throw new InvalidArgument(
				'Cannot combine ' . $this->currency . ' with ' . $other->currency . '.'
			);
		}
	}
}
