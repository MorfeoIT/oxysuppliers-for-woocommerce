<?php
/**
 * The supplier record.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Tests\Unit;

use Oxysoft\OxySuppliers\Domain\Supplier;
use Oxysoft\OxySuppliers\Domain\SupplierStatus;
use PHPUnit\Framework\TestCase;

/**
 * Assembling a supplier from what a form sent.
 */
final class SupplierTest extends TestCase {

	/**
	 * Whitespace and case are tidied on the way in.
	 *
	 * @return void
	 */
	public function test_cleans_the_values_it_is_given(): void {
		$supplier = Supplier::from_fields(
			array(
				'company_name' => '  ABC Srl  ',
				'country'      => 'it',
				'currency'     => 'eur',
				'city'         => " Milano\t",
			)
		);

		$this->assertSame( 'ABC Srl', $supplier->company_name );
		$this->assertSame( 'IT', $supplier->country );
		$this->assertSame( 'EUR', $supplier->currency() );
		$this->assertSame( 'Milano', $supplier->city );
	}

	/**
	 * No minimum means zero, in the supplier's own currency.
	 *
	 * @return void
	 */
	public function test_an_empty_minimum_is_zero(): void {
		$supplier = Supplier::from_fields(
			array(
				'company_name'    => 'ABC Srl',
				'currency'        => 'USD',
				'min_order_value' => '',
			)
		);

		$this->assertTrue( $supplier->min_order_value->is_zero() );
		$this->assertSame( 'USD', $supplier->min_order_value->currency );
	}

	/**
	 * A negative lead time is nonsense, so it becomes none.
	 *
	 * @return void
	 */
	public function test_lead_time_is_never_negative(): void {
		$supplier = Supplier::from_fields(
			array(
				'company_name'   => 'ABC Srl',
				'currency'       => 'EUR',
				'lead_time_days' => '-5',
			)
		);

		$this->assertSame( 0, $supplier->lead_time_days );
	}

	/**
	 * The trading name is what people call them, when there is one.
	 *
	 * @return void
	 */
	public function test_shows_the_trading_name_when_there_is_one(): void {
		$with = Supplier::from_fields(
			array(
				'company_name' => 'Alfa Beta Gamma Srl',
				'trade_name'   => 'ABG',
				'currency'     => 'EUR',
			)
		);

		$without = Supplier::from_fields(
			array(
				'company_name' => 'Alfa Beta Gamma Srl',
				'currency'     => 'EUR',
			)
		);

		$this->assertSame( 'ABG', $with->display_name() );
		$this->assertSame( 'Alfa Beta Gamma Srl', $without->display_name() );
	}

	/**
	 * A new supplier is one we buy from, and a status nobody recognises is
	 * treated as a supplier that exists rather than one that vanished.
	 *
	 * @return void
	 */
	public function test_status_defaults_to_active(): void {
		$default = Supplier::from_fields(
			array(
				'company_name' => 'ABC Srl',
				'currency'     => 'EUR',
			)
		);

		$corrupt = Supplier::from_fields(
			array(
				'company_name' => 'ABC Srl',
				'currency'     => 'EUR',
				'status'       => 'whatever',
			)
		);

		$this->assertTrue( $default->is_active() );
		$this->assertTrue( $corrupt->is_active() );
		$this->assertSame( SupplierStatus::ACTIVE, $corrupt->status );
	}

	/**
	 * An inactive supplier stays inactive.
	 *
	 * @return void
	 */
	public function test_keeps_an_inactive_status(): void {
		$supplier = Supplier::from_fields(
			array(
				'company_name' => 'ABC Srl',
				'currency'     => 'EUR',
				'status'       => 'inactive',
			)
		);

		$this->assertFalse( $supplier->is_active() );
	}

	/**
	 * Getting an id back from an insert changes nothing else.
	 *
	 * @return void
	 */
	public function test_takes_an_id_without_losing_anything(): void {
		$supplier = Supplier::from_fields(
			array(
				'company_name'    => 'ABC Srl',
				'trade_name'      => 'ABC',
				'currency'        => 'EUR',
				'min_order_value' => '250.00',
				'lead_time_days'  => '3',
				'notes'           => 'Calls on Tuesdays.',
			)
		);

		$stored = $supplier->with_id( 42 );

		$this->assertSame( 42, $stored->id );
		$this->assertSame( 0, $supplier->id );
		$this->assertSame( 'ABC Srl', $stored->company_name );
		$this->assertSame( 'ABC', $stored->trade_name );
		$this->assertSame( 3, $stored->lead_time_days );
		$this->assertSame( 'Calls on Tuesdays.', $stored->notes );
		$this->assertTrue( $stored->min_order_value->equals( $supplier->min_order_value ) );
	}
}
