<?php
/**
 * Money.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Tests\Unit;

use Oxysoft\OxySuppliers\Domain\InvalidArgument;
use Oxysoft\OxySuppliers\Domain\Money;
use PHPUnit\Framework\TestCase;

/**
 * An amount is an integer of minor units and a currency, and never a float.
 */
final class MoneyTest extends TestCase {

	/**
	 * A plain decimal becomes minor units.
	 *
	 * @return void
	 */
	public function test_reads_a_decimal_amount(): void {
		$this->assertSame( 1180, Money::from_decimal( '11.80', 'EUR' )->minor );
		$this->assertSame( 5, Money::from_decimal( '0.05', 'EUR' )->minor );
		$this->assertSame( 0, Money::from_decimal( '0', 'EUR' )->minor );
		$this->assertSame( 100000, Money::from_decimal( '1000.00', 'EUR' )->minor );
	}

	/**
	 * Rounding is half away from zero, and done on digits rather than a float.
	 *
	 * @return void
	 */
	public function test_rounds_half_away_from_zero(): void {
		$this->assertSame( 1181, Money::from_decimal( '11.805', 'EUR' )->minor );
		$this->assertSame( 1180, Money::from_decimal( '11.804', 'EUR' )->minor );
		$this->assertSame( -1181, Money::from_decimal( '-11.805', 'EUR' )->minor );

		// Only the first discarded digit decides, which is what rounding means:
		// everything after it cannot lift 11.804... to 11.81.
		$this->assertSame( 1180, Money::from_decimal( '11.8049999', 'EUR' )->minor );
		$this->assertSame( 1180, Money::from_decimal( '11.7999', 'EUR' )->minor );
	}

	/**
	 * A currency with no minor unit does not get two decimals.
	 *
	 * @return void
	 */
	public function test_respects_the_currency_exponent(): void {
		$this->assertSame( 1000, Money::from_decimal( '1000', 'JPY' )->minor );
		$this->assertSame( 2, Money::from_decimal( '1.5', 'JPY' )->minor );
		$this->assertSame( 1234, Money::from_decimal( '1.234', 'BHD' )->minor );
		$this->assertSame( '1000', Money::from_decimal( '1000', 'JPY' )->to_decimal() );
	}

	/**
	 * What goes in comes back out.
	 *
	 * @return void
	 */
	public function test_writes_a_decimal_amount(): void {
		$this->assertSame( '11.80', Money::from_decimal( '11.80', 'EUR' )->to_decimal() );
		$this->assertSame( '0.05', Money::from_minor( 5, 'EUR' )->to_decimal() );
		$this->assertSame( '-0.05', Money::from_minor( -5, 'EUR' )->to_decimal() );
		$this->assertSame( '0.00', Money::zero( 'EUR' )->to_decimal() );
	}

	/**
	 * The currency code is normalised, not trusted.
	 *
	 * @return void
	 */
	public function test_normalises_the_currency_code(): void {
		$this->assertSame( 'EUR', Money::from_decimal( '1.00', 'eur' )->currency );
		$this->assertSame( 'EUR', Money::from_minor( 100, ' eur ' )->currency );
	}

	/**
	 * A locale's separators are somebody else's problem, solved before this
	 * point. Accepting them here would make "1,234" ambiguous.
	 *
	 * @return void
	 */
	public function test_refuses_anything_that_is_not_a_plain_decimal(): void {
		$this->expectException( InvalidArgument::class );

		Money::from_decimal( '11,80', 'EUR' );
	}

	/**
	 * Text is not an amount.
	 *
	 * @return void
	 */
	public function test_refuses_text(): void {
		$this->expectException( InvalidArgument::class );

		Money::from_decimal( 'eleven', 'EUR' );
	}

	/**
	 * Beyond the integer range, refusing beats being quietly wrong.
	 *
	 * @return void
	 */
	public function test_refuses_an_amount_too_large_to_be_exact(): void {
		$this->expectException( InvalidArgument::class );

		Money::from_decimal( '12345678901234567890', 'EUR' );
	}

	/**
	 * A three-letter code, or nothing.
	 *
	 * @return void
	 */
	public function test_refuses_a_currency_that_is_not_a_code(): void {
		$this->expectException( InvalidArgument::class );

		Money::from_minor( 100, 'EURO' );
	}

	/**
	 * Amounts of the same currency combine.
	 *
	 * @return void
	 */
	public function test_adds_and_subtracts(): void {
		$ten  = Money::from_decimal( '10.00', 'EUR' );
		$two  = Money::from_decimal( '2.50', 'EUR' );

		$this->assertSame( '12.50', $ten->add( $two )->to_decimal() );
		$this->assertSame( '7.50', $ten->subtract( $two )->to_decimal() );
		$this->assertSame( '25.00', $two->multiply( 10 )->to_decimal() );
	}

	/**
	 * Amounts of different currencies do not.
	 *
	 * @return void
	 */
	public function test_refuses_to_mix_currencies(): void {
		$this->expectException( InvalidArgument::class );

		Money::from_decimal( '10.00', 'EUR' )->add( Money::from_decimal( '10.00', 'USD' ) );
	}

	/**
	 * Equality is amount and currency, not amount alone.
	 *
	 * @return void
	 */
	public function test_equality_includes_the_currency(): void {
		$euros   = Money::from_minor( 1000, 'EUR' );
		$dollars = Money::from_minor( 1000, 'USD' );

		$this->assertTrue( $euros->equals( Money::from_minor( 1000, 'EUR' ) ) );
		$this->assertFalse( $euros->equals( $dollars ) );
	}
}
