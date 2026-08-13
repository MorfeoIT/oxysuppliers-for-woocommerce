<?php
/**
 * The purchase order document.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Tests\Unit;

use Oxysoft\OxySuppliers\Domain\InvalidArgument;
use Oxysoft\OxySuppliers\Domain\InvalidTransition;
use Oxysoft\OxySuppliers\Domain\Money;
use Oxysoft\OxySuppliers\Domain\PurchaseOrder;
use Oxysoft\OxySuppliers\Domain\PurchaseOrderLine;
use Oxysoft\OxySuppliers\Domain\PurchaseOrderStatus;
use PHPUnit\Framework\TestCase;

/**
 * Totals, outstanding quantities and the states an order may reach.
 */
final class PurchaseOrderTest extends TestCase {

	/**
	 * A line.
	 *
	 * @param int $quantity    How many.
	 * @param int $cost_minor  Price each.
	 * @param int $discount_bp Discount in basis points.
	 * @param int $tax_bp      Tax rate in basis points.
	 * @param int $received    How many have arrived.
	 * @return PurchaseOrderLine
	 */
	private function line( int $quantity, int $cost_minor, int $discount_bp = 0, int $tax_bp = 0, int $received = 0 ): PurchaseOrderLine {
		return new PurchaseOrderLine(
			0,
			100,
			0,
			'MOUSE-X',
			'MX-123',
			'Mouse X',
			$quantity,
			$received,
			Money::from_minor( $cost_minor, 'EUR' ),
			$discount_bp,
			$tax_bp
		);
	}

	/**
	 * An order with these lines.
	 *
	 * @param list<PurchaseOrderLine> $lines The lines.
	 * @return PurchaseOrder
	 */
	private function order( array $lines ): PurchaseOrder {
		return PurchaseOrder::draft_for( 1, 'EUR', '2026-08-13' )->with_lines( $lines );
	}

	/**
	 * Quantity times price.
	 *
	 * @return void
	 */
	public function test_a_line_costs_quantity_times_price(): void {
		$this->assertSame( '118.00', $this->line( 10, 1180 )->net_total()->to_decimal() );
		$this->assertSame( '0.00', $this->line( 0, 1180 )->net_total()->to_decimal() );
	}

	/**
	 * A discount comes off, and is rounded half up rather than truncated.
	 *
	 * Truncating would favour the buyer by a penny a line, which is the sort of
	 * difference that turns into an argument over an invoice.
	 *
	 * @return void
	 */
	public function test_a_discount_is_rounded_and_not_truncated(): void {
		// 3 × 1.15 = 3.45, less 12.5% = 3.01875, which is 3.02 and not 3.01.
		$this->assertSame( '3.02', $this->line( 3, 115, 1250 )->net_total()->to_decimal() );

		// A hundred per cent off is free, and is allowed.
		$this->assertSame( '0.00', $this->line( 10, 1180, 10000 )->net_total()->to_decimal() );
	}

	/**
	 * Tax is worked out on what is actually being paid.
	 *
	 * @return void
	 */
	public function test_tax_is_charged_on_the_discounted_amount(): void {
		$line = $this->line( 10, 1000, 1000, 2200 );

		$this->assertSame( '90.00', $line->net_total()->to_decimal() );
		$this->assertSame( '19.80', $line->tax_total()->to_decimal() );
	}

	/**
	 * A discount outside nought to a hundred per cent is not a discount.
	 *
	 * @return void
	 */
	public function test_refuses_an_impossible_discount(): void {
		$this->expectException( InvalidArgument::class );

		$this->line( 1, 100, 10001 );
	}

	/**
	 * Ordering a negative quantity is not ordering.
	 *
	 * @return void
	 */
	public function test_refuses_a_negative_quantity(): void {
		$this->expectException( InvalidArgument::class );

		$this->line( -1, 100 );
	}

	/**
	 * The order is the sum of its lines, tax kept apart.
	 *
	 * @return void
	 */
	public function test_the_order_adds_up_its_lines(): void {
		$order = $this->order(
			array(
				$this->line( 10, 1180, 0, 2200 ),
				$this->line( 5, 900, 0, 2200 ),
			)
		);

		$this->assertSame( '163.00', $order->subtotal()->to_decimal() );
		$this->assertSame( '35.86', $order->tax()->to_decimal() );
		$this->assertSame( '198.86', $order->total()->to_decimal() );
	}

	/**
	 * An order with no lines comes to nothing, in its own currency.
	 *
	 * @return void
	 */
	public function test_an_empty_order_comes_to_nothing(): void {
		$order = PurchaseOrder::draft_for( 1, 'USD', '2026-08-13' );

		$this->assertSame( '0.00', $order->total()->to_decimal() );
		$this->assertSame( 'USD', $order->total()->currency );
	}

	/**
	 * What is still to come, never negative.
	 *
	 * @return void
	 */
	public function test_counts_what_is_still_to_come(): void {
		$order = $this->order(
			array(
				$this->line( 20, 100, 0, 0, 15 ),
				$this->line( 10, 100, 0, 0, 10 ),
			)
		);

		$this->assertSame( 5, $order->outstanding() );
		$this->assertFalse( $order->is_fully_received() );
		$this->assertTrue( $order->is_partially_received() );
	}

	/**
	 * More arriving than was ordered is an over-delivery, not a negative
	 * outstanding quantity.
	 *
	 * @return void
	 */
	public function test_an_over_delivery_does_not_go_negative(): void {
		$line = $this->line( 10, 100, 0, 0, 12 );

		$this->assertSame( 0, $line->outstanding() );
		$this->assertTrue( $line->is_over_received() );
		$this->assertTrue( $line->is_fully_received() );
	}

	/**
	 * An empty order is not a complete one.
	 *
	 * @return void
	 */
	public function test_an_empty_order_is_not_complete(): void {
		$order = PurchaseOrder::draft_for( 1, 'EUR', '2026-08-13' );

		$this->assertFalse( $order->is_fully_received() );
		$this->assertFalse( $order->can_be_sent() );
	}

	/**
	 * An order with lines can go out.
	 *
	 * @return void
	 */
	public function test_an_order_with_lines_can_be_sent(): void {
		$this->assertTrue( $this->order( array( $this->line( 10, 100 ) ) )->can_be_sent() );
	}

	/**
	 * The states an order may reach.
	 *
	 * @return void
	 */
	public function test_the_states_it_may_reach(): void {
		$draft = PurchaseOrderStatus::DRAFT;

		$this->assertTrue( $draft->can_move_to( PurchaseOrderStatus::SENT ) );
		$this->assertTrue( $draft->can_move_to( PurchaseOrderStatus::CANCELLED ) );
		$this->assertFalse( $draft->can_move_to( PurchaseOrderStatus::RECEIVED ) );

		// Staying put is not a transition.
		$this->assertTrue( $draft->can_move_to( PurchaseOrderStatus::DRAFT ) );
	}

	/**
	 * A cancelled order stays cancelled. Re-opening one would hide the fact
	 * that it was cancelled.
	 *
	 * @return void
	 */
	public function test_a_cancelled_order_is_the_end_of_it(): void {
		$cancelled = PurchaseOrderStatus::CANCELLED;

		$this->assertSame( array(), $cancelled->allowed_next() );
		$this->assertTrue( $cancelled->is_final() );

		foreach ( PurchaseOrderStatus::cases() as $status ) {
			if ( PurchaseOrderStatus::CANCELLED === $status ) {
				continue;
			}

			$this->assertFalse( $cancelled->can_move_to( $status ), "Cancelled should not reach {$status->value}" );
		}
	}

	/**
	 * A fully received order can go back to partly received, because reversing
	 * a receipt has to leave the order saying something true.
	 *
	 * @return void
	 */
	public function test_a_received_order_can_go_back_when_a_receipt_is_reversed(): void {
		$this->assertTrue(
			PurchaseOrderStatus::RECEIVED->can_move_to( PurchaseOrderStatus::PARTIALLY_RECEIVED )
		);
		$this->assertFalse(
			PurchaseOrderStatus::RECEIVED->can_move_to( PurchaseOrderStatus::DRAFT )
		);
	}

	/**
	 * The domain refuses the move, not the screen.
	 *
	 * @return void
	 */
	public function test_the_domain_refuses_a_move_it_does_not_allow(): void {
		$this->expectException( InvalidTransition::class );

		$this->order( array( $this->line( 10, 100 ) ) )->with_status( PurchaseOrderStatus::RECEIVED );
	}

	/**
	 * An order that has been sent is no longer something to edit.
	 *
	 * @return void
	 */
	public function test_only_a_draft_can_be_edited(): void {
		$this->assertTrue( PurchaseOrderStatus::DRAFT->is_editable() );
		$this->assertTrue( PurchaseOrderStatus::TO_SEND->is_editable() );
		$this->assertFalse( PurchaseOrderStatus::SENT->is_editable() );
		$this->assertFalse( PurchaseOrderStatus::RECEIVED->is_editable() );
	}

	/**
	 * What has arrived decides what the order has become.
	 *
	 * @return void
	 */
	public function test_receiving_decides_the_state(): void {
		$partly = $this->order( array( $this->line( 20, 100, 0, 0, 5 ) ) )
			->with_status( PurchaseOrderStatus::SENT );

		$this->assertSame( PurchaseOrderStatus::PARTIALLY_RECEIVED, $partly->status_after_receiving() );

		$whole = $this->order( array( $this->line( 20, 100, 0, 0, 20 ) ) )
			->with_status( PurchaseOrderStatus::SENT );

		$this->assertSame( PurchaseOrderStatus::RECEIVED, $whole->status_after_receiving() );
	}

	/**
	 * Reversing everything takes the order back, and never past cancelled.
	 *
	 * @return void
	 */
	public function test_reversing_everything_takes_it_back(): void {
		$sent = $this->order( array( $this->line( 20, 100 ) ) )->with_status( PurchaseOrderStatus::SENT );

		$this->assertSame( PurchaseOrderStatus::SENT, $sent->status_after_receiving() );

		$cancelled = $this->order( array( $this->line( 20, 100, 0, 0, 20 ) ) )
			->with_status( PurchaseOrderStatus::CANCELLED );

		$this->assertSame( PurchaseOrderStatus::CANCELLED, $cancelled->status_after_receiving() );
	}

	/**
	 * Which orders count as still coming, for the reordering screen.
	 *
	 * @return void
	 */
	public function test_which_orders_are_still_expected(): void {
		$this->assertTrue( PurchaseOrderStatus::SENT->is_expected() );
		$this->assertTrue( PurchaseOrderStatus::PARTIALLY_RECEIVED->is_expected() );

		// A draft is a thought, not an order: counting it would stop the shop
		// from ever being told to order what it has been meaning to order.
		$this->assertFalse( PurchaseOrderStatus::DRAFT->is_expected() );
		$this->assertFalse( PurchaseOrderStatus::RECEIVED->is_expected() );
		$this->assertFalse( PurchaseOrderStatus::CANCELLED->is_expected() );
	}
}
