<?php
/**
 * Working out when an order should turn up.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Tests\Unit;

use Oxysoft\OxySuppliers\Domain\ExpectedDate;
use PHPUnit\Framework\TestCase;

/**
 * Small, and worth its own tests: the obvious implementation puts deliveries in
 * 1970.
 */
final class ExpectedDateTest extends TestCase {

	/**
	 * The order date plus the wait.
	 *
	 * @return void
	 */
	public function test_adds_the_lead_time(): void {
		$this->assertSame( '2026-08-16', ExpectedDate::after( '2026-08-13', 3 ) );
		$this->assertSame( '2026-08-13', ExpectedDate::after( '2026-08-13', 0 ) );
	}

	/**
	 * Across a month, a year and a leap day.
	 *
	 * @return void
	 */
	public function test_crosses_the_awkward_boundaries(): void {
		$this->assertSame( '2026-09-02', ExpectedDate::after( '2026-08-30', 3 ) );
		$this->assertSame( '2027-01-02', ExpectedDate::after( '2026-12-30', 3 ) );
		$this->assertSame( '2028-02-29', ExpectedDate::after( '2028-02-27', 2 ) );
	}

	/**
	 * A negative wait is no wait, not a delivery in the past.
	 *
	 * @return void
	 */
	public function test_a_negative_lead_time_is_no_lead_time(): void {
		$this->assertSame( '2026-08-13', ExpectedDate::after( '2026-08-13', -10 ) );
	}

	/**
	 * A date it cannot read comes back untouched.
	 *
	 * The naive version hands strtotime()'s false to gmdate(), which reads it
	 * as the first of January 1970 and quietly promises a delivery fifty-six
	 * years ago.
	 *
	 * @return void
	 */
	public function test_an_unreadable_date_is_not_turned_into_1970(): void {
		$this->assertSame( 'domani', ExpectedDate::after( 'domani', 3 ) );
		$this->assertSame( '', ExpectedDate::after( '', 3 ) );
	}

	/**
	 * A date has to be a real day, not just the right shape.
	 *
	 * @return void
	 */
	public function test_knows_a_real_date_from_one_that_only_looks_like_one(): void {
		$this->assertTrue( ExpectedDate::is_valid( '2026-08-13' ) );
		$this->assertTrue( ExpectedDate::is_valid( '2028-02-29' ) );

		$this->assertFalse( ExpectedDate::is_valid( '2026-02-31' ) );
		$this->assertFalse( ExpectedDate::is_valid( '2027-02-29' ) );
		$this->assertFalse( ExpectedDate::is_valid( '13/08/2026' ) );
		$this->assertFalse( ExpectedDate::is_valid( '' ) );
	}
}
