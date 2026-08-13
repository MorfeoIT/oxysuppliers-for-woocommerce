<?php
/**
 * Rounding a need into a quantity a supplier will accept.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Tests\Unit;

use Oxysoft\OxySuppliers\Domain\InvalidArgument;
use Oxysoft\OxySuppliers\Domain\OrderTerms;
use PHPUnit\Framework\TestCase;

/**
 * The half of the suggestion that has nothing to do with stock.
 */
final class OrderTermsTest extends TestCase {

	/**
	 * The example from the specification: needing fifteen from a supplier who
	 * sells in tens means ordering twenty.
	 *
	 * @return void
	 */
	public function test_the_example_from_the_specification(): void {
		$terms = new OrderTerms( 0, 10, 1 );

		$this->assertSame( 20, $terms->round_up( 15 ) );
	}

	/**
	 * With nothing to respect, the need is the quantity.
	 *
	 * @return void
	 */
	public function test_terms_that_constrain_nothing_change_nothing(): void {
		$terms = OrderTerms::none();

		$this->assertSame( 1, $terms->round_up( 1 ) );
		$this->assertSame( 137, $terms->round_up( 137 ) );
	}

	/**
	 * Zero is treated as one: "not filled in" and "one at a time" are the same
	 * constraint, and telling them apart everywhere else would be noise.
	 *
	 * @return void
	 */
	public function test_a_multiple_of_zero_is_a_multiple_of_one(): void {
		$terms = new OrderTerms( 0, 0, 0 );

		$this->assertSame( 1, $terms->step() );
		$this->assertSame( 7, $terms->round_up( 7 ) );
	}

	/**
	 * Needing nothing means ordering nothing. A minimum order quantity is a
	 * condition of buying, not a reason to buy.
	 *
	 * @return void
	 */
	public function test_no_need_means_no_order_even_with_a_minimum(): void {
		$terms = new OrderTerms( 50, 10, 1 );

		$this->assertSame( 0, $terms->round_up( 0 ) );
		$this->assertSame( 0, $terms->round_up( -5 ) );
	}

	/**
	 * A minimum larger than the need wins.
	 *
	 * @return void
	 */
	public function test_the_minimum_lifts_a_small_need(): void {
		$terms = new OrderTerms( 20, 1, 1 );

		$this->assertSame( 20, $terms->round_up( 1 ) );
		$this->assertSame( 25, $terms->round_up( 25 ) );
	}

	/**
	 * A minimum that is not itself a valid quantity is rounded up too.
	 *
	 * Ten, in packs of four, is twelve. Answering "ten" would be answering with
	 * a quantity the supplier cannot ship.
	 *
	 * @return void
	 */
	public function test_the_minimum_is_rounded_to_the_step_as_well(): void {
		$terms = new OrderTerms( 10, 4, 1 );

		$this->assertSame( 12, $terms->round_up( 1 ) );
		$this->assertTrue( $terms->accepts( 12 ) );
		$this->assertFalse( $terms->accepts( 10 ) );
	}

	/**
	 * A multiple and a pack size are two constraints, and both have to hold.
	 *
	 * Packs of six and multiples of ten leave only the multiples of thirty.
	 * Taking the larger of the two would propose an order that cannot be filled.
	 *
	 * @return void
	 */
	public function test_a_multiple_and_a_pack_size_are_both_respected(): void {
		$terms = new OrderTerms( 0, 10, 6 );

		$this->assertSame( 30, $terms->step() );
		$this->assertSame( 30, $terms->round_up( 15 ) );
		$this->assertSame( 60, $terms->round_up( 31 ) );
		$this->assertTrue( $terms->accepts( 30 ) );
		$this->assertFalse( $terms->accepts( 10 ) );
		$this->assertFalse( $terms->accepts( 6 ) );
	}

	/**
	 * When they are the same, the step is that number and not its square.
	 *
	 * @return void
	 */
	public function test_the_same_multiple_and_pack_size_do_not_multiply(): void {
		$terms = new OrderTerms( 0, 12, 12 );

		$this->assertSame( 12, $terms->step() );
		$this->assertSame( 24, $terms->round_up( 13 ) );
	}

	/**
	 * One dividing the other leaves the larger.
	 *
	 * @return void
	 */
	public function test_a_pack_that_divides_the_multiple(): void {
		$terms = new OrderTerms( 0, 12, 4 );

		$this->assertSame( 12, $terms->step() );
	}

	/**
	 * A need that already fits is left alone.
	 *
	 * @return void
	 */
	public function test_a_need_that_already_fits_is_not_moved(): void {
		$terms = new OrderTerms( 10, 10, 1 );

		$this->assertSame( 20, $terms->round_up( 20 ) );
		$this->assertSame( 10, $terms->round_up( 10 ) );
	}

	/**
	 * Nothing is ever rounded down: ordering less than is needed leaves the
	 * shortage in place behind an order that looks like it was placed.
	 *
	 * @return void
	 */
	public function test_never_rounds_down(): void {
		$terms = new OrderTerms( 0, 25, 1 );

		foreach ( range( 1, 60 ) as $needed ) {
			$this->assertGreaterThanOrEqual( $needed, $terms->round_up( $needed ) );
		}
	}

	/**
	 * Whatever comes out is something the supplier would accept.
	 *
	 * @return void
	 */
	public function test_what_it_returns_is_always_acceptable(): void {
		$terms = new OrderTerms( 17, 4, 6 );

		foreach ( range( 1, 80 ) as $needed ) {
			$this->assertTrue(
				$terms->accepts( $terms->round_up( $needed ) ),
				"Rounding {$needed} produced a quantity the supplier would refuse."
			);
		}
	}

	/**
	 * Negative terms are not terms.
	 *
	 * @return void
	 */
	public function test_refuses_negative_terms(): void {
		$this->expectException( InvalidArgument::class );

		new OrderTerms( -1, 1, 1 );
	}
}
