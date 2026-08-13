<?php
/**
 * Receiving goods, against a real database.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Tests\Integration;

use Oxysoft\OxySuppliers\Domain\Money;
use Oxysoft\OxySuppliers\Domain\PurchaseOrder;
use Oxysoft\OxySuppliers\Domain\PurchaseOrderLine;
use Oxysoft\OxySuppliers\Domain\PurchaseOrderStatus;
use Oxysoft\OxySuppliers\Persistence\Migrator;
use Oxysoft\OxySuppliers\Persistence\PurchaseOrderRepository;
use Oxysoft\OxySuppliers\Persistence\ReceiptRepository;
use Oxysoft\OxySuppliers\Persistence\Tables;
use Oxysoft\OxySuppliers\Service\AuditLogger;
use Oxysoft\OxySuppliers\Service\GoodsReceiver;
use Oxysoft\OxySuppliers\Service\PurchaseOrderNumbers;
use Oxysoft\OxySuppliers\Service\ReceiptOutcome;
use WP_UnitTestCase;

/**
 * The sprint where being wrong means a shop sells what it has not got.
 *
 * Every defence gets its own test, and the one that matters most is the second:
 * the same form submitted twice must leave one delivery behind, not two.
 */
final class GoodsReceiverTest extends WP_UnitTestCase {

	private GoodsReceiver $receiver;
	private PurchaseOrderRepository $orders;
	private ReceiptRepository $receipts;

	/**
	 * Empty tables and the collaborators.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		// The schema is rebuilt for every test rather than inherited from whichever
		// one ran first. The harness makes its tables TEMPORARY, and a test that
		// commits or rolls back on its own can take them with it while the option
		// saying "already migrated" survives — after which the migrator politely
		// does nothing and every later test fails on a table that is not there.
		delete_option( Migrator::VERSION_OPTION );
		( new Migrator() )->migrate();

		foreach ( array( Tables::RECEIPT_ITEMS, Tables::RECEIPTS, Tables::ORDER_ITEMS, Tables::PURCHASE_ORDERS, Tables::LOGS ) as $table ) {
			$name = Tables::name( $table );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture, table name from a constant.
			$wpdb->query( "DELETE FROM {$name}" );
		}

		$this->orders   = new PurchaseOrderRepository( new PurchaseOrderNumbers() );
		$this->receipts = new ReceiptRepository();
		$this->receiver = new GoodsReceiver( $this->receipts, $this->orders, new AuditLogger() );
	}

	/**
	 * An order that has been sent, with two lines.
	 *
	 * @return PurchaseOrder
	 */
	private function sent_order(): PurchaseOrder {
		$order = $this->orders->create(
			PurchaseOrder::draft_for( 1, 'EUR', '2026-08-13' )->with_lines(
				array(
					new PurchaseOrderLine( 0, 100, 0, 'MOUSE-X', 'F-MX', 'Mouse X', 20, 0, Money::from_minor( 1180, 'EUR' ) ),
					new PurchaseOrderLine( 0, 200, 0, 'SSD-1TB', 'F-SSD', 'SSD 1 TB', 10, 0, Money::from_minor( 6200, 'EUR' ) ),
				)
			)
		);

		$this->assertNotNull( $order );

		$this->orders->mark_sent( $order );

		$sent = $this->orders->find( $order->id );

		$this->assertNotNull( $sent );

		return $sent;
	}

	/**
	 * Part of a delivery arrives.
	 *
	 * @return void
	 */
	public function test_receives_part_of_an_order(): void {
		$order = $this->sent_order();

		$outcome = $this->receiver->receive(
			$order,
			array( $order->lines[0]->id => 5 ),
			array(),
			'chiave-1',
			'DDT-100'
		);

		$this->assertSame( ReceiptOutcome::RECORDED, $outcome->status );
		$this->assertNotNull( $outcome->receipt );
		$this->assertSame( 5, $outcome->receipt->total_quantity() );

		$after = $this->orders->find( $order->id );

		$this->assertNotNull( $after );
		$this->assertSame( 5, $after->lines[0]->qty_received );
		$this->assertSame( 25, $after->outstanding() );
		$this->assertSame( PurchaseOrderStatus::PARTIALLY_RECEIVED, $after->status );
	}

	/**
	 * **The one that matters.** The same form submitted twice leaves one
	 * delivery behind.
	 *
	 * This is a double click, a reload, a back button, a browser retrying a
	 * request that timed out. All of them carry the key the form was drawn
	 * with, and the second one has to bounce.
	 *
	 * @return void
	 */
	public function test_the_same_form_submitted_twice_receives_once(): void {
		$order = $this->sent_order();

		$first = $this->receiver->receive( $order, array( $order->lines[0]->id => 8 ), array(), 'stessa-chiave' );

		// The second submission carries the same key — and, as in real life,
		// the order object it was drawn from is the stale one.
		$second = $this->receiver->receive( $order, array( $order->lines[0]->id => 8 ), array(), 'stessa-chiave' );

		$this->assertSame( ReceiptOutcome::RECORDED, $first->status );
		$this->assertSame( ReceiptOutcome::ALREADY_RECORDED, $second->status );

		// A second press is not a failure: it points at the delivery that
		// exists.
		$this->assertTrue( $second->is_recorded() );
		$this->assertNotNull( $second->receipt );
		$this->assertSame( $first->receipt->id, $second->receipt->id );

		$this->assertCount( 1, $this->receipts->for_order( $order->id ) );

		$after = $this->orders->find( $order->id );

		$this->assertNotNull( $after );
		$this->assertSame( 8, $after->lines[0]->qty_received, 'Eight arrived, not sixteen.' );
	}

	/**
	 * A different key is a different delivery, and both count.
	 *
	 * @return void
	 */
	public function test_two_real_deliveries_both_count(): void {
		$order = $this->sent_order();

		$this->receiver->receive( $order, array( $order->lines[0]->id => 8 ), array(), 'chiave-a' );

		$again = $this->orders->find( $order->id );

		$this->assertNotNull( $again );

		$this->receiver->receive( $again, array( $again->lines[0]->id => 12 ), array(), 'chiave-b' );

		$after = $this->orders->find( $order->id );

		$this->assertNotNull( $after );
		$this->assertSame( 20, $after->lines[0]->qty_received );
		$this->assertCount( 2, $this->receipts->for_order( $order->id ) );
	}

	/**
	 * Everything arriving completes the order.
	 *
	 * @return void
	 */
	public function test_receiving_everything_completes_the_order(): void {
		$order = $this->sent_order();

		$outcome = $this->receiver->receive(
			$order,
			array(
				$order->lines[0]->id => 20,
				$order->lines[1]->id => 10,
			),
			array(),
			'tutto'
		);

		$this->assertSame( ReceiptOutcome::RECORDED, $outcome->status );

		$after = $this->orders->find( $order->id );

		$this->assertNotNull( $after );
		$this->assertSame( 0, $after->outstanding() );
		$this->assertTrue( $after->is_fully_received() );
		$this->assertSame( PurchaseOrderStatus::RECEIVED, $after->status );
	}

	/**
	 * More than was ordered is refused, and **nothing** is written.
	 *
	 * @return void
	 */
	public function test_refuses_more_than_was_ordered(): void {
		$order = $this->sent_order();

		$outcome = $this->receiver->receive( $order, array( $order->lines[0]->id => 25 ), array(), 'troppi' );

		$this->assertSame( ReceiptOutcome::TOO_MANY, $outcome->status );
		$this->assertSame( array(), $this->receipts->for_order( $order->id ) );

		$after = $this->orders->find( $order->id );

		$this->assertNotNull( $after );
		$this->assertSame( 0, $after->lines[0]->qty_received );

		// And the key is free, so the same delivery can be entered correctly.
		$corrected = $this->receiver->receive( $order, array( $order->lines[0]->id => 20 ), array(), 'troppi' );

		$this->assertSame( ReceiptOutcome::RECORDED, $corrected->status );
	}

	/**
	 * The outstanding quantity is re-read, so a second delivery cannot take the
	 * total past what was ordered.
	 *
	 * @return void
	 */
	public function test_the_outstanding_quantity_is_read_again(): void {
		$order = $this->sent_order();

		$this->receiver->receive( $order, array( $order->lines[0]->id => 15 ), array(), 'primo' );

		// The screen this came from was drawn before the first delivery, so it
		// still believes twenty are outstanding. The stale object is passed on
		// purpose.
		$outcome = $this->receiver->receive( $order, array( $order->lines[0]->id => 15 ), array(), 'secondo' );

		$this->assertSame( ReceiptOutcome::TOO_MANY, $outcome->status );

		$after = $this->orders->find( $order->id );

		$this->assertNotNull( $after );
		$this->assertSame( 15, $after->lines[0]->qty_received );
	}

	/**
	 * Pressing the button with nothing filled in is not a delivery.
	 *
	 * @return void
	 */
	public function test_an_empty_form_records_nothing(): void {
		$order = $this->sent_order();

		$outcome = $this->receiver->receive( $order, array(), array(), 'vuoto' );

		$this->assertSame( ReceiptOutcome::NOTHING, $outcome->status );
		$this->assertSame( array(), $this->receipts->for_order( $order->id ) );

		// The key was not spent on nothing.
		$this->assertNull( $this->receipts->find_by_key( 'vuoto' ) );
	}

	/**
	 * A form with no key at all is refused rather than trusted.
	 *
	 * @return void
	 */
	public function test_a_form_without_a_key_is_refused(): void {
		$order = $this->sent_order();

		$outcome = $this->receiver->receive( $order, array( $order->lines[0]->id => 5 ), array(), '   ' );

		$this->assertSame( ReceiptOutcome::FAILED, $outcome->status );
		$this->assertSame( array(), $this->receipts->for_order( $order->id ) );
	}

	/**
	 * Somebody else is already receiving this order.
	 *
	 * @return void
	 */
	public function test_waits_when_somebody_else_is_receiving(): void {
		$order = $this->sent_order();

		// Another request holds the lock.
		$this->assertTrue( $this->receipts->lock( $order->id, 'un-altro' ) );

		$outcome = $this->receiver->receive( $order, array( $order->lines[0]->id => 5 ), array(), 'contesa' );

		$this->assertSame( ReceiptOutcome::BUSY, $outcome->status );
		$this->assertSame( array(), $this->receipts->for_order( $order->id ) );

		// And once they are done, it goes through.
		$this->receipts->unlock( $order->id, 'un-altro' );

		$this->assertSame(
			ReceiptOutcome::RECORDED,
			$this->receiver->receive( $order, array( $order->lines[0]->id => 5 ), array(), 'contesa' )->status
		);
	}

	/**
	 * The lock is given back, so the next delivery is not blocked.
	 *
	 * @return void
	 */
	public function test_the_lock_is_released_afterwards(): void {
		$order = $this->sent_order();

		$this->receiver->receive( $order, array( $order->lines[0]->id => 5 ), array(), 'una' );

		$this->assertTrue( $this->receipts->lock( $order->id, 'chiunque' ) );
	}

	/**
	 * A lock that is taken can be given back, whatever the token looks like.
	 *
	 * The column holds thirty-two characters and a UUID is thirty-six. MySQL
	 * took the first thirty-two without a word, and the value being matched on
	 * the way out was no longer the value that had been stored: the lock was
	 * taken and never released, and every delivery after the first was told the
	 * order was busy.
	 *
	 * @return void
	 */
	public function test_a_long_token_still_unlocks(): void {
		$order = $this->sent_order();
		$long  = wp_generate_uuid4() . '-and-then-some';

		$this->assertTrue( $this->receipts->lock( $order->id, $long ) );
		$this->assertFalse( $this->receipts->lock( $order->id, 'qualcun-altro' ) );

		$this->receipts->unlock( $order->id, $long );

		$this->assertTrue( $this->receipts->lock( $order->id, 'qualcun-altro' ) );
	}

	/**
	 * Only the holder can release the lock.
	 *
	 * @return void
	 */
	public function test_somebody_else_cannot_release_the_lock(): void {
		$order = $this->sent_order();

		$this->assertTrue( $this->receipts->lock( $order->id, 'primo' ) );

		$this->receipts->unlock( $order->id, 'secondo' );

		$this->assertFalse( $this->receipts->lock( $order->id, 'terzo' ) );
	}

	/**
	 * A mistake is corrected by an opposite entry, and both stay.
	 *
	 * @return void
	 */
	public function test_a_delivery_is_corrected_and_not_deleted(): void {
		$order = $this->sent_order();

		$wrong = $this->receiver->receive( $order, array( $order->lines[0]->id => 12 ), array(), 'sbagliata' );

		$this->assertSame( ReceiptOutcome::RECORDED, $wrong->status );

		$fix = $this->receiver->reverse( $wrong->receipt, 'correzione' );

		$this->assertSame( ReceiptOutcome::RECORDED, $fix->status );
		$this->assertNotNull( $fix->receipt );
		$this->assertTrue( $fix->receipt->is_reversal() );
		$this->assertSame( -12, $fix->receipt->total_quantity() );
		$this->assertSame( $wrong->receipt->id, $fix->receipt->reverses_id );

		// Both entries are still there: a history with a page torn out is not a
		// history.
		$this->assertCount( 2, $this->receipts->for_order( $order->id ) );

		$after = $this->orders->find( $order->id );

		$this->assertNotNull( $after );
		$this->assertSame( 0, $after->lines[0]->qty_received );
		$this->assertSame( 30, $after->outstanding() );
	}

	/**
	 * Correcting a correction is refused: the answer is to receive again.
	 *
	 * @return void
	 */
	public function test_a_correction_cannot_be_corrected(): void {
		$order = $this->sent_order();

		$wrong = $this->receiver->receive( $order, array( $order->lines[0]->id => 4 ), array(), 'una' );
		$fix   = $this->receiver->reverse( $wrong->receipt, 'due' );

		$outcome = $this->receiver->reverse( $fix->receipt, 'tre' );

		$this->assertSame( ReceiptOutcome::FAILED, $outcome->status );
	}

	/**
	 * Correcting a fully received order puts it back to partly received, which
	 * is the state the state machine allows for exactly this.
	 *
	 * @return void
	 */
	public function test_correcting_a_complete_order_puts_it_back(): void {
		$order = $this->sent_order();

		$first = $this->receiver->receive( $order, array( $order->lines[0]->id => 20 ), array(), 'a' );

		$again = $this->orders->find( $order->id );
		$this->assertNotNull( $again );

		$this->receiver->receive( $again, array( $again->lines[1]->id => 10 ), array(), 'b' );

		$complete = $this->orders->find( $order->id );
		$this->assertNotNull( $complete );
		$this->assertSame( PurchaseOrderStatus::RECEIVED, $complete->status );

		$this->receiver->reverse( $first->receipt, 'correzione' );

		$after = $this->orders->find( $order->id );

		$this->assertNotNull( $after );
		$this->assertSame( PurchaseOrderStatus::PARTIALLY_RECEIVED, $after->status );
		$this->assertSame( 20, $after->outstanding() );
	}

	/**
	 * The receipts are the record; the column on the order line is a copy, and
	 * a copy that has drifted is corrected from them.
	 *
	 * @return void
	 */
	public function test_the_receipts_are_the_truth(): void {
		global $wpdb;

		$order = $this->sent_order();

		$this->receiver->receive( $order, array( $order->lines[0]->id => 6 ), array(), 'sei' );

		// Somebody, or something, writes nonsense into the copy.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture: deliberate drift.
		$wpdb->update(
			Tables::name( Tables::ORDER_ITEMS ),
			array( 'qty_received' => 999 ),
			array( 'id' => $order->lines[0]->id ),
			array( '%d' ),
			array( '%d' )
		);

		$drifted = $this->orders->find( $order->id );
		$this->assertNotNull( $drifted );
		$this->assertSame( 999, $drifted->lines[0]->qty_received );

		// The next delivery works from the receipts and puts the copy right.
		$this->receiver->receive( $drifted, array( $drifted->lines[0]->id => 4 ), array(), 'quattro' );

		$after = $this->orders->find( $order->id );

		$this->assertNotNull( $after );
		$this->assertSame( 10, $after->lines[0]->qty_received );
		$this->assertSame( 10, $this->receipts->received_by_line( $order->id )[ $order->lines[0]->id ] );
	}

	/**
	 * What was really charged is kept, because it is the number somebody will
	 * want to compare with the invoice.
	 *
	 * @return void
	 */
	public function test_keeps_the_price_actually_charged(): void {
		$order = $this->sent_order();

		$outcome = $this->receiver->receive(
			$order,
			array( $order->lines[0]->id => 5 ),
			array( $order->lines[0]->id => '12.50' ),
			'con-costo'
		);

		$this->assertSame( ReceiptOutcome::RECORDED, $outcome->status );
		$this->assertNotNull( $outcome->receipt->lines[0]->actual_cost );
		$this->assertSame( 1250, $outcome->receipt->lines[0]->actual_cost->minor );
		$this->assertSame( 'EUR', $outcome->receipt->lines[0]->actual_cost->currency );
	}

	/**
	 * Every delivery leaves a line in the log.
	 *
	 * @return void
	 */
	public function test_every_delivery_is_written_down(): void {
		$order = $this->sent_order();
		$audit = new AuditLogger();

		$outcome = $this->receiver->receive( $order, array( $order->lines[0]->id => 5 ), array(), 'registrata' );

		$this->assertSame(
			1,
			$audit->count( AuditLogger::OBJECT_RECEIPT, (string) $outcome->receipt->id, AuditLogger::ACTION_CREATED )
		);
	}
}
