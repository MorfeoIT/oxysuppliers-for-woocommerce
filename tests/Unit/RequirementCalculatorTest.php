<?php
/**
 * What to order, and why.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Tests\Unit;

use Oxysoft\OxySuppliers\Domain\RequirementContext;
use Oxysoft\OxySuppliers\Domain\RequirementStatus;
use Oxysoft\OxySuppliers\Domain\SupplierProduct;
use Oxysoft\OxySuppliers\Engine\RequirementCalculator;
use Oxysoft\OxySuppliers\Engine\TargetStockStrategy;
use PHPUnit\Framework\TestCase;

/**
 * The whole of the free suggestion, with no database anywhere near it.
 */
final class RequirementCalculatorTest extends TestCase {

	private RequirementCalculator $calculator;

	/**
	 * Build the free calculator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calculator = new RequirementCalculator( new TargetStockStrategy() );
	}

	/**
	 * A price list line to buy from.
	 *
	 * @param array<string,mixed> $overrides Values to change.
	 * @return SupplierProduct
	 */
	private function supplier( array $overrides = array() ): SupplierProduct {
		return SupplierProduct::from_fields(
			array_merge(
				array(
					'supplier_id'    => 1,
					'product_id'     => 100,
					'currency'       => 'EUR',
					'unit_cost'      => '11.80',
					'min_order_qty'  => '0',
					'order_multiple' => '1',
					'pack_qty'       => '1',
				),
				$overrides
			),
			5
		);
	}

	/**
	 * One article.
	 *
	 * @param array<string,mixed> $overrides Values to change.
	 * @return RequirementContext
	 */
	private function article( array $overrides = array() ): RequirementContext {
		$values = array_merge(
			array(
				'stock'         => 3,
				'reserved'      => 0,
				'incoming'      => 0,
				'reorder_point' => 9,
				'target'        => 18,
				'supplier'      => $this->supplier(),
			),
			$overrides
		);

		return new RequirementContext(
			100,
			0,
			'MOUSE-X',
			'Mouse X',
			$values['stock'],
			$values['reserved'],
			$values['incoming'],
			$values['reorder_point'],
			$values['target'],
			0,
			0,
			0,
			$values['supplier']
		);
	}

	/**
	 * The example from the specification: three on the shelf, eighteen wanted,
	 * fifteen short.
	 *
	 * @return void
	 */
	public function test_the_example_from_the_specification(): void {
		$answer = $this->calculator->calculate( $this->article() );

		$this->assertSame( 15, $answer->needed );
		$this->assertSame( 15, $answer->suggested );
		$this->assertSame( RequirementStatus::BELOW_REORDER_POINT, $answer->status );
	}

	/**
	 * And with a supplier who sells in tens, the same shortage is twenty.
	 *
	 * @return void
	 */
	public function test_the_supplier_terms_round_the_answer_up(): void {
		$answer = $this->calculator->calculate(
			$this->article( array( 'supplier' => $this->supplier( array( 'order_multiple' => '10' ) ) ) )
		);

		$this->assertSame( 15, $answer->needed );
		$this->assertSame( 20, $answer->suggested );
		$this->assertTrue( $answer->was_rounded_up() );
		$this->assertSame( '236.00', $answer->value->to_decimal() );
	}

	/**
	 * Goods already on their way count. Not subtracting them is how a shop
	 * orders the same thing twice.
	 *
	 * @return void
	 */
	public function test_what_is_already_on_its_way_is_subtracted(): void {
		$answer = $this->calculator->calculate( $this->article( array( 'incoming' => 10 ) ) );

		$this->assertSame( 5, $answer->needed );

		// Enough on order to cover the whole shortage: nothing more to do.
		$covered = $this->calculator->calculate( $this->article( array( 'incoming' => 40 ) ) );

		$this->assertSame( 0, $covered->needed );
		$this->assertSame( 0, $covered->suggested );
		$this->assertFalse( $covered->is_orderable() );
	}

	/**
	 * Stock held for orders being paid for is not stock you can sell.
	 *
	 * @return void
	 */
	public function test_reserved_stock_is_not_available(): void {
		$answer = $this->calculator->calculate( $this->article( array( 'stock' => 10, 'reserved' => 4 ) ) );

		$this->assertSame( 6, $answer->context->available() );
		$this->assertSame( 12, $answer->needed );
	}

	/**
	 * A shop that has oversold is below zero, and the shortage is bigger than
	 * the target.
	 *
	 * @return void
	 */
	public function test_negative_stock_is_a_bigger_shortage(): void {
		$answer = $this->calculator->calculate( $this->article( array( 'stock' => -12 ) ) );

		$this->assertSame( 30, $answer->needed );
		$this->assertSame( RequirementStatus::OUT_OF_STOCK, $answer->status );
	}

	/**
	 * Enough on the shelf is nothing to do.
	 *
	 * @return void
	 */
	public function test_a_full_shelf_needs_nothing(): void {
		$answer = $this->calculator->calculate( $this->article( array( 'stock' => 40 ) ) );

		$this->assertSame( 0, $answer->needed );
		$this->assertSame( 0, $answer->suggested );
		$this->assertNull( $answer->value );
		$this->assertSame( RequirementStatus::OK, $answer->status );
		$this->assertFalse( $answer->status->needs_attention() );
	}

	/**
	 * A minimum order quantity is a condition of buying, not a reason to buy.
	 *
	 * @return void
	 */
	public function test_a_minimum_does_not_create_an_order(): void {
		$answer = $this->calculator->calculate(
			$this->article(
				array(
					'stock'    => 40,
					'supplier' => $this->supplier( array( 'min_order_qty' => '50' ) ),
				)
			)
		);

		$this->assertSame( 0, $answer->suggested );
	}

	/**
	 * An article WooCommerce is not counting has no shortage to report.
	 *
	 * @return void
	 */
	public function test_an_untracked_article_is_never_short(): void {
		$answer = $this->calculator->calculate( $this->article( array( 'stock' => null ) ) );

		$this->assertSame( 0, $answer->needed );
		$this->assertSame( RequirementStatus::NOT_TRACKED, $answer->status );
		$this->assertFalse( $answer->status->needs_attention() );
	}

	/**
	 * A shortage nobody can fill is reported as the missing supplier.
	 *
	 * The shortage is visible in the numbers; the reason it cannot be fixed is
	 * not, and hiding it would make the screen tidier and less true.
	 *
	 * @return void
	 */
	public function test_a_shortage_with_no_supplier_says_so(): void {
		$answer = $this->calculator->calculate( $this->article( array( 'supplier' => null ) ) );

		$this->assertSame( 15, $answer->needed );
		$this->assertSame( 15, $answer->suggested );
		$this->assertNull( $answer->value );
		$this->assertFalse( $answer->is_orderable() );
		$this->assertSame( RequirementStatus::NO_SUPPLIER, $answer->status );
		$this->assertTrue( $answer->status->is_incomplete() );
	}

	/**
	 * A supplier with no price is the other half of the same problem.
	 *
	 * @return void
	 */
	public function test_a_supplier_with_no_price_says_so(): void {
		$answer = $this->calculator->calculate(
			$this->article( array( 'supplier' => $this->supplier( array( 'unit_cost' => '0' ) ) ) )
		);

		$this->assertSame( RequirementStatus::NO_COST, $answer->status );
		$this->assertTrue( $answer->status->is_incomplete() );
	}

	/**
	 * A well stocked article with no supplier is not a problem yet.
	 *
	 * @return void
	 */
	public function test_a_full_shelf_with_no_supplier_is_not_a_problem(): void {
		$answer = $this->calculator->calculate(
			$this->article(
				array(
					'stock'    => 40,
					'supplier' => null,
				)
			)
		);

		$this->assertSame( RequirementStatus::OK, $answer->status );
	}

	/**
	 * Sitting exactly on the reorder point counts as being on it.
	 *
	 * @return void
	 */
	public function test_sitting_on_the_reorder_point_counts(): void {
		$answer = $this->calculator->calculate( $this->article( array( 'stock' => 9 ) ) );

		$this->assertTrue( $answer->context->is_below_reorder_point() );
		$this->assertSame( RequirementStatus::BELOW_REORDER_POINT, $answer->status );
	}

	/**
	 * A target of zero asks for nothing, whatever the shelf looks like.
	 *
	 * @return void
	 */
	public function test_a_target_of_zero_asks_for_nothing(): void {
		$answer = $this->calculator->calculate(
			$this->article(
				array(
					'target'        => 0,
					'reorder_point' => 0,
					'stock'         => 0,
				)
			)
		);

		$this->assertSame( 0, $answer->needed );
		$this->assertSame( RequirementStatus::OUT_OF_STOCK, $answer->status );
	}

	/**
	 * A whole page at once.
	 *
	 * @return void
	 */
	public function test_calculates_a_page(): void {
		$answers = $this->calculator->calculate_all(
			array(
				$this->article(),
				$this->article( array( 'stock' => 40 ) ),
				$this->article( array( 'stock' => null ) ),
			)
		);

		$this->assertCount( 3, $answers );
		$this->assertSame( 15, $answers[0]->needed );
		$this->assertSame( 0, $answers[1]->needed );
		$this->assertSame( RequirementStatus::NOT_TRACKED, $answers[2]->status );
	}
}
