<?php
/**
 * Purchase order storage, against a real database.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Tests\Integration;

use Oxysoft\OxySuppliers\Domain\InvalidTransition;
use Oxysoft\OxySuppliers\Domain\Money;
use Oxysoft\OxySuppliers\Domain\PurchaseOrder;
use Oxysoft\OxySuppliers\Domain\PurchaseOrderLine;
use Oxysoft\OxySuppliers\Domain\PurchaseOrderStatus;
use Oxysoft\OxySuppliers\Persistence\Migrator;
use Oxysoft\OxySuppliers\Persistence\PurchaseOrderRepository;
use Oxysoft\OxySuppliers\Persistence\Tables;
use Oxysoft\OxySuppliers\Service\PurchaseOrderNumbers;
use WP_UnitTestCase;

/**
 * Numbering, lines, totals and the states an order may reach — the half that
 * only a real database can answer.
 */
final class PurchaseOrderRepositoryTest extends WP_UnitTestCase {

	private PurchaseOrderRepository $orders;

	/**
	 * Empty tables and a fresh repository.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		( new Migrator() )->migrate();

		foreach ( array( Tables::ORDER_ITEMS, Tables::PURCHASE_ORDERS ) as $table ) {
			$name = Tables::name( $table );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture, table name from a constant.
			$wpdb->query( "DELETE FROM {$name}" );
		}

		$this->orders = new PurchaseOrderRepository( new PurchaseOrderNumbers() );
	}

	/**
	 * A line.
	 *
	 * @param int $quantity   How many.
	 * @param int $cost_minor Price each.
	 * @return PurchaseOrderLine
	 */
	private function line( int $quantity = 10, int $cost_minor = 1180 ): PurchaseOrderLine {
		return new PurchaseOrderLine(
			0,
			100,
			0,
			'MOUSE-X',
			'MX-123',
			'Mouse X',
			$quantity,
			0,
			Money::from_minor( $cost_minor, 'EUR' )
		);
	}

	/**
	 * A draft with one line.
	 *
	 * @return PurchaseOrder
	 */
	private function draft(): PurchaseOrder {
		return PurchaseOrder::draft_for( 1, 'EUR', '2026-08-13' )->with_lines( array( $this->line() ) );
	}

	/**
	 * What goes in comes back out, lines and all.
	 *
	 * @return void
	 */
	public function test_stores_an_order_with_its_lines(): void {
		global $wpdb;

		$stored = $this->orders->create( $this->draft() );

		$this->assertNotNull(
			$stored,
			'last_error: ' . $wpdb->last_error . ' | last_query: ' . $wpdb->last_query
		);
		$this->assertGreaterThan( 0, $stored->id );
		$this->assertNotSame( '', $stored->number );
		$this->assertCount( 1, $stored->lines );
		$this->assertSame( 'MX-123', $stored->lines[0]->supplier_sku );
		$this->assertSame( '118.00', $stored->total()->to_decimal() );
		$this->assertSame( PurchaseOrderStatus::DRAFT, $stored->status );
	}

	/**
	 * The number is given in sequence.
	 *
	 * @return void
	 */
	public function test_numbers_run_in_sequence(): void {
		$first  = $this->orders->create( $this->draft() );
		$second = $this->orders->create( $this->draft() );

		$this->assertNotNull( $first );
		$this->assertNotNull( $second );
		$this->assertNotSame( $first->number, $second->number );
		$this->assertStringContainsString( gmdate( 'Y' ), $first->number );
	}

	/**
	 * The exit criterion: two orders saved in the same instant get two
	 * different numbers.
	 *
	 * The second insert loses the race against the unique index and is tried
	 * again with a fresh number, which is exactly what would happen with two
	 * people pressing save at once. A counter in an option would lose one of
	 * them, and do it silently.
	 *
	 * @return void
	 */
	public function test_two_orders_saved_at_once_get_two_numbers(): void {
		$numbers = new PurchaseOrderNumbers();

		// Both work out the same next number, because neither has been stored
		// yet: this is the race, made repeatable.
		$proposed = $numbers->next();

		$first  = $this->orders->create( $this->draft()->with_number( $proposed ) );
		$second = $this->orders->create( $this->draft()->with_number( $proposed ) );

		$this->assertNotNull( $first );
		$this->assertNotNull( $second, 'The second order must be stored, with another number.' );
		$this->assertSame( $proposed, $first->number );
		$this->assertNotSame( $first->number, $second->number );
	}

	/**
	 * And the database refuses a duplicate even without the retry.
	 *
	 * @return void
	 */
	public function test_the_database_refuses_a_duplicate_number(): void {
		global $wpdb;

		$stored = $this->orders->create( $this->draft() );

		$this->assertNotNull( $stored );

		$wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture: this one must fail.
		$second = $wpdb->insert(
			Tables::name( Tables::PURCHASE_ORDERS ),
			array(
				'po_number'   => $stored->number,
				'supplier_id' => 1,
				'currency'    => 'EUR',
				'order_date'  => '2026-08-13',
				'created_at'  => '2026-08-13 00:00:00',
				'updated_at'  => '2026-08-13 00:00:00',
			)
		);

		$wpdb->suppress_errors( false );

		$this->assertFalse( $second );
	}

	/**
	 * The stored totals are rewritten from the lines on every save.
	 *
	 * @return void
	 */
	public function test_the_stored_total_follows_the_lines(): void {
		$stored = $this->orders->create( $this->draft() );

		$this->assertNotNull( $stored );

		$totals = $this->orders->totals_for( array( $stored->id ) );
		$this->assertSame( 11800, $totals[ $stored->id ] );

		$this->orders->update( $stored->with_lines( array( $stored->lines[0], $this->line( 5, 900 ) ) ) );

		$totals = $this->orders->totals_for( array( $stored->id ) );
		$this->assertSame( 16300, $totals[ $stored->id ] );
	}

	/**
	 * Saving replaces the lines: the ones left off are gone.
	 *
	 * @return void
	 */
	public function test_saving_removes_the_lines_left_off(): void {
		$stored = $this->orders->create(
			$this->draft()->with_lines( array( $this->line(), $this->line( 5, 900 ) ) )
		);

		$this->assertNotNull( $stored );
		$this->assertCount( 2, $stored->lines );

		$this->orders->update( $stored->with_lines( array( $stored->lines[0] ) ) );

		$again = $this->orders->find( $stored->id );

		$this->assertNotNull( $again );
		$this->assertCount( 1, $again->lines );
	}

	/**
	 * Every line off the order leaves an order with no lines, not an error.
	 *
	 * @return void
	 */
	public function test_an_order_can_be_emptied(): void {
		$stored = $this->orders->create( $this->draft() );

		$this->assertNotNull( $stored );

		$this->orders->update( $stored->with_lines( array() ) );

		$again = $this->orders->find( $stored->id );

		$this->assertNotNull( $again );
		$this->assertSame( array(), $again->lines );
		$this->assertFalse( $again->can_be_sent() );
	}

	/**
	 * The number is never rewritten: it is on a document that may have gone
	 * out.
	 *
	 * @return void
	 */
	public function test_an_update_cannot_change_the_number(): void {
		$stored = $this->orders->create( $this->draft() );

		$this->assertNotNull( $stored );

		$this->orders->update( $stored->with_number( 'PO-QUALCOSA' ) );

		$again = $this->orders->find( $stored->id );

		$this->assertNotNull( $again );
		$this->assertSame( $stored->number, $again->number );
	}

	/**
	 * Moving through the states.
	 *
	 * @return void
	 */
	public function test_moves_through_the_states(): void {
		$stored = $this->orders->create( $this->draft() );

		$this->assertNotNull( $stored );
		$this->assertTrue( $this->orders->mark_sent( $stored ) );

		$sent = $this->orders->find( $stored->id );

		$this->assertNotNull( $sent );
		$this->assertSame( PurchaseOrderStatus::SENT, $sent->status );
		$this->assertNotNull( $sent->sent_at );
		$this->assertFalse( $sent->status->is_editable() );
	}

	/**
	 * A move the domain forbids never reaches the database.
	 *
	 * @return void
	 */
	public function test_a_forbidden_move_is_refused_before_it_is_written(): void {
		$stored = $this->orders->create( $this->draft() );

		$this->assertNotNull( $stored );

		$this->orders->set_status( $stored, PurchaseOrderStatus::CANCELLED );

		$cancelled = $this->orders->find( $stored->id );

		$this->assertNotNull( $cancelled );

		$refused = false;

		try {
			$this->orders->set_status( $cancelled, PurchaseOrderStatus::SENT );
		} catch ( InvalidTransition $stopped ) {
			$refused = true;
		}

		$this->assertTrue( $refused, 'A cancelled order must not be sendable.' );

		$again = $this->orders->find( $stored->id );

		$this->assertNotNull( $again );
		$this->assertSame( PurchaseOrderStatus::CANCELLED, $again->status );
	}

	/**
	 * Recording what has arrived.
	 *
	 * @return void
	 */
	public function test_records_what_has_arrived(): void {
		$stored = $this->orders->create( $this->draft() );

		$this->assertNotNull( $stored );

		$this->orders->set_line_received( $stored->lines[0]->id, 4 );

		$again = $this->orders->find( $stored->id );

		$this->assertNotNull( $again );
		$this->assertSame( 4, $again->lines[0]->qty_received );
		$this->assertSame( 6, $again->outstanding() );
		$this->assertSame( PurchaseOrderStatus::PARTIALLY_RECEIVED, $again->status_after_receiving() );
	}

	/**
	 * Finding and filtering.
	 *
	 * @return void
	 */
	public function test_finds_and_filters(): void {
		$first = $this->orders->create( $this->draft() );

		$second = PurchaseOrder::draft_for( 2, 'EUR', '2026-08-13' )->with_lines( array( $this->line() ) );
		$second = $this->orders->create( $second );

		$this->assertNotNull( $first );
		$this->assertNotNull( $second );

		$this->assertSame( 2, $this->orders->count() );
		$this->assertCount( 1, $this->orders->paginate( array( 'supplier_id' => 2 ) ) );
		$this->assertCount( 2, $this->orders->paginate( array( 'status' => array( 'draft' ) ) ) );
		$this->assertCount( 0, $this->orders->paginate( array( 'status' => array( 'received' ) ) ) );

		// Nonsense in the status filter is ignored rather than obeyed.
		$this->assertCount( 2, $this->orders->paginate( array( 'status' => array( 'qualcosa' ) ) ) );

		$found = $this->orders->find_by_number( $first->number );

		$this->assertNotNull( $found );
		$this->assertSame( $first->id, $found->id );
		$this->assertNull( $this->orders->find_by_number( 'PO-NON-ESISTE' ) );
	}

	/**
	 * The list does not carry lines, and the totals come from one query.
	 *
	 * @return void
	 */
	public function test_the_list_is_cheap(): void {
		global $wpdb;

		$this->orders->create( $this->draft() );
		$this->orders->create( $this->draft() );

		$before = $wpdb->num_queries;

		$orders = $this->orders->paginate();
		$this->orders->totals_for( array_map( static fn ( PurchaseOrder $one ): int => $one->id, $orders ) );

		$this->assertSame( 2, $wpdb->num_queries - $before, 'A page of orders should cost two queries.' );
	}
}
