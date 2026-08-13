<?php
/**
 * A line of a supplier's price list.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Tests\Unit;

use Oxysoft\OxySuppliers\Domain\SupplierProduct;
use Oxysoft\OxySuppliers\Domain\SupplierProductValidator;
use PHPUnit\Framework\TestCase;

/**
 * Assembling and checking one price list line.
 */
final class SupplierProductTest extends TestCase {

	/**
	 * A complete, sensible line.
	 *
	 * @param array<string,mixed> $overrides Values to change.
	 * @return array<string,mixed>
	 */
	private function fields( array $overrides = array() ): array {
		return array_merge(
			array(
				'supplier_id'    => 7,
				'product_id'     => 42,
				'variation_id'   => 0,
				'supplier_sku'   => 'MX-123',
				'currency'       => 'EUR',
				'unit_cost'      => '11.80',
				'min_order_qty'  => '10',
				'order_multiple' => '5',
				'pack_qty'       => '1',
				'lead_time_days' => '3',
			),
			$overrides
		);
	}

	/**
	 * The values become the object.
	 *
	 * @return void
	 */
	public function test_builds_from_form_values(): void {
		$line = SupplierProduct::from_fields( $this->fields() );

		$this->assertSame( 7, $line->supplier_id );
		$this->assertSame( 42, $line->product_id );
		$this->assertSame( 'MX-123', $line->supplier_sku );
		$this->assertSame( 1180, $line->unit_cost->minor );
		$this->assertSame( 'EUR', $line->unit_cost->currency );
		$this->assertSame( 10, $line->terms->minimum );
		$this->assertSame( 5, $line->terms->multiple );
		$this->assertSame( 3, $line->lead_time_days );
		$this->assertFalse( $line->is_preferred );
		$this->assertFalse( $line->is_variation() );
	}

	/**
	 * A variation is a different article from its parent.
	 *
	 * @return void
	 */
	public function test_knows_it_is_about_a_variation(): void {
		$line = SupplierProduct::from_fields( $this->fields( array( 'variation_id' => 43 ) ) );

		$this->assertTrue( $line->is_variation() );
		$this->assertSame( 43, $line->variation_id );
	}

	/**
	 * No cost is nothing, in the supplier's currency, and not an error: a line
	 * can exist before anybody knows the price.
	 *
	 * @return void
	 */
	public function test_an_empty_cost_is_zero(): void {
		$line = SupplierProduct::from_fields( $this->fields( array( 'unit_cost' => '' ) ) );

		$this->assertTrue( $line->unit_cost->is_zero() );
		$this->assertSame( 'EUR', $line->unit_cost->currency );
	}

	/**
	 * What a quantity would cost, in the same currency.
	 *
	 * @return void
	 */
	public function test_multiplies_the_cost_by_a_quantity(): void {
		$line = SupplierProduct::from_fields( $this->fields() );

		$this->assertSame( '118.00', $line->cost_of( 10 )->to_decimal() );
		$this->assertSame( '0.00', $line->cost_of( 0 )->to_decimal() );
		$this->assertSame( '0.00', $line->cost_of( -3 )->to_decimal() );
	}

	/**
	 * Getting an id, or being made preferred, changes nothing else.
	 *
	 * @return void
	 */
	public function test_keeps_everything_else_when_copied(): void {
		$line = SupplierProduct::from_fields( $this->fields() );

		$stored = $line->with_id( 9 )->with_preferred( true );

		$this->assertSame( 9, $stored->id );
		$this->assertTrue( $stored->is_preferred );
		$this->assertSame( 'MX-123', $stored->supplier_sku );
		$this->assertSame( 1180, $stored->unit_cost->minor );
		$this->assertSame( 5, $stored->terms->multiple );

		// And the original is untouched.
		$this->assertSame( 0, $line->id );
		$this->assertFalse( $line->is_preferred );
	}

	/**
	 * A line has to belong to a supplier and to an article.
	 *
	 * @return void
	 */
	public function test_requires_a_supplier_and_a_product(): void {
		$validator = new SupplierProductValidator();

		$errors = $validator->validate( $this->fields( array( 'supplier_id' => 0 ) ) );
		$this->assertSame( SupplierProductValidator::REQUIRED, $errors['supplier_id'] );

		$errors = $validator->validate( $this->fields( array( 'product_id' => 0 ) ) );
		$this->assertSame( SupplierProductValidator::REQUIRED, $errors['product_id'] );

		$this->assertSame( array(), $validator->validate( $this->fields() ) );
	}

	/**
	 * Quantities are whole and never negative: half a pack is not a pack.
	 *
	 * @return void
	 */
	public function test_refuses_quantities_that_are_not_whole_numbers(): void {
		$validator = new SupplierProductValidator();

		foreach ( array( 'min_order_qty', 'order_multiple', 'pack_qty', 'lead_time_days' ) as $field ) {
			foreach ( array( '-1', '2.5', 'dieci' ) as $bad ) {
				$errors = $validator->validate( $this->fields( array( $field => $bad ) ) );

				$this->assertSame(
					SupplierProductValidator::INVALID_NUMBER,
					$errors[ $field ] ?? '',
					"{$field} should have refused {$bad}"
				);
			}
		}
	}

	/**
	 * A cost is a number, and the locale's comma has been dealt with before it
	 * gets here.
	 *
	 * @return void
	 */
	public function test_refuses_a_cost_that_is_not_a_number(): void {
		$validator = new SupplierProductValidator();

		$this->assertSame(
			SupplierProductValidator::INVALID_NUMBER,
			$validator->validate( $this->fields( array( 'unit_cost' => '11,80' ) ) )['unit_cost']
		);

		$this->assertArrayNotHasKey(
			'unit_cost',
			$validator->validate( $this->fields( array( 'unit_cost' => '' ) ) )
		);
	}

	/**
	 * A supplier code longer than its column would be truncated in silence.
	 *
	 * @return void
	 */
	public function test_refuses_a_supplier_code_that_is_too_long(): void {
		$errors = ( new SupplierProductValidator() )->validate(
			$this->fields( array( 'supplier_sku' => str_repeat( 'x', 101 ) ) )
		);

		$this->assertSame( SupplierProductValidator::TOO_LONG, $errors['supplier_sku'] );
	}
}
