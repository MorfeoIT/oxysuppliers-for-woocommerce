<?php
/**
 * Supplier storage, against a real database.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Tests\Integration;

use Oxysoft\OxySuppliers\Domain\Supplier;
use Oxysoft\OxySuppliers\Persistence\Migrator;
use Oxysoft\OxySuppliers\Persistence\SupplierRepository;
use Oxysoft\OxySuppliers\Persistence\Tables;
use WP_UnitTestCase;

/**
 * Round trips, searching, and the rule that a supplier with orders stays.
 */
final class SupplierRepositoryTest extends WP_UnitTestCase {

	private SupplierRepository $suppliers;

	/**
	 * Make sure the tables are there and empty.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		( new Migrator() )->migrate();

		foreach ( array( Tables::SUPPLIERS, Tables::SUPPLIER_PRODUCTS, Tables::PURCHASE_ORDERS ) as $table ) {
			$name = Tables::name( $table );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture, table name from a constant.
			$wpdb->query( "DELETE FROM {$name}" );
		}

		$this->suppliers = new SupplierRepository();
	}

	/**
	 * Build a supplier from the fields a form would send.
	 *
	 * @param array<string,string> $overrides Values to change.
	 * @return Supplier
	 */
	private function supplier( array $overrides = array() ): Supplier {
		return Supplier::from_fields(
			array_merge(
				array(
					'company_name'    => 'ABC Srl',
					'trade_name'      => 'ABC',
					'vat_number'      => 'IT01234567890',
					'city'            => 'Milano',
					'country'         => 'IT',
					'order_email'     => 'orders@example.test',
					'currency'        => 'EUR',
					'lead_time_days'  => '3',
					'min_order_value' => '250.00',
					'notes'           => 'Calls on Tuesdays.',
				),
				$overrides
			)
		);
	}

	/**
	 * What goes in comes back out, unchanged.
	 *
	 * @return void
	 */
	public function test_stores_and_reads_a_supplier(): void {
		$id = $this->suppliers->insert( $this->supplier() );

		$this->assertGreaterThan( 0, $id );

		$stored = $this->suppliers->find( $id );

		$this->assertNotNull( $stored );
		$this->assertSame( 'ABC Srl', $stored->company_name );
		$this->assertSame( 'ABC', $stored->trade_name );
		$this->assertSame( 'IT', $stored->country );
		$this->assertSame( 3, $stored->lead_time_days );
		$this->assertSame( 'EUR', $stored->currency() );
		$this->assertSame( '250.00', $stored->min_order_value->to_decimal() );
		$this->assertSame( 'Calls on Tuesdays.', $stored->notes );
		$this->assertTrue( $stored->is_active() );
	}

	/**
	 * An amount survives the round trip as an integer, not a float.
	 *
	 * @return void
	 */
	public function test_money_survives_the_round_trip(): void {
		$id = $this->suppliers->insert( $this->supplier( array( 'min_order_value' => '11.80' ) ) );

		$stored = $this->suppliers->find( $id );

		$this->assertNotNull( $stored );
		$this->assertSame( 1180, $stored->min_order_value->minor );
	}

	/**
	 * Editing changes what was asked for and nothing else.
	 *
	 * @return void
	 */
	public function test_updates_a_supplier(): void {
		$id = $this->suppliers->insert( $this->supplier() );

		$changed = Supplier::from_fields(
			array(
				'company_name'    => 'ABC Srl',
				'trade_name'      => 'ABC Forniture',
				'city'            => 'Torino',
				'country'         => 'IT',
				'currency'        => 'EUR',
				'lead_time_days'  => '5',
				'min_order_value' => '300.00',
				'status'          => 'inactive',
			),
			$id
		);

		$this->assertTrue( $this->suppliers->update( $changed ) );

		$stored = $this->suppliers->find( $id );

		$this->assertNotNull( $stored );
		$this->assertSame( 'ABC Forniture', $stored->trade_name );
		$this->assertSame( 'Torino', $stored->city );
		$this->assertSame( 5, $stored->lead_time_days );
		$this->assertFalse( $stored->is_active() );
	}

	/**
	 * An id that does not exist is nothing, not an error.
	 *
	 * @return void
	 */
	public function test_a_missing_supplier_is_null(): void {
		$this->assertNull( $this->suppliers->find( 987654 ) );
	}

	/**
	 * Searching looks where somebody would expect it to.
	 *
	 * @return void
	 */
	public function test_searches_name_trading_name_vat_and_city(): void {
		$this->suppliers->insert( $this->supplier() );
		$this->suppliers->insert(
			$this->supplier(
				array(
					'company_name' => 'DEF Spa',
					'trade_name'   => '',
					'vat_number'   => 'IT99999999999',
					'city'         => 'Bologna',
				)
			)
		);

		$this->assertCount( 1, $this->suppliers->paginate( array( 'search' => 'Bologna' ) ) );
		$this->assertCount( 1, $this->suppliers->paginate( array( 'search' => 'DEF' ) ) );
		$this->assertCount( 1, $this->suppliers->paginate( array( 'search' => 'IT0123' ) ) );
		$this->assertCount( 2, $this->suppliers->paginate() );
		$this->assertSame( 2, $this->suppliers->count() );
	}

	/**
	 * A percent sign in a search is a character, not a wildcard.
	 *
	 * @return void
	 */
	public function test_a_wildcard_in_the_search_is_taken_literally(): void {
		$this->suppliers->insert( $this->supplier() );

		$this->assertCount( 0, $this->suppliers->paginate( array( 'search' => '%' ) ) );
	}

	/**
	 * The status filter only accepts a status.
	 *
	 * @return void
	 */
	public function test_filters_by_status(): void {
		$this->suppliers->insert( $this->supplier() );
		$this->suppliers->insert( $this->supplier( array( 'company_name' => 'DEF Spa', 'status' => 'inactive' ) ) );

		$this->assertCount( 1, $this->suppliers->paginate( array( 'status' => 'active' ) ) );
		$this->assertCount( 1, $this->suppliers->paginate( array( 'status' => 'inactive' ) ) );

		// Nonsense is ignored rather than obeyed, so the list is not empty by
		// accident.
		$this->assertCount( 2, $this->suppliers->paginate( array( 'status' => 'whatever' ) ) );
	}

	/**
	 * Sorting only ever uses a column from the allowlist.
	 *
	 * @return void
	 */
	public function test_sorting_ignores_a_column_it_does_not_know(): void {
		$this->suppliers->insert( $this->supplier( array( 'company_name' => 'Zeta Srl' ) ) );
		$this->suppliers->insert( $this->supplier( array( 'company_name' => 'Alfa Srl' ) ) );

		$rows = $this->suppliers->paginate( array( 'orderby' => 'id; DROP TABLE wp_posts' ) );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'Alfa Srl', $rows[0]->company_name );
	}

	/**
	 * A page is a page.
	 *
	 * @return void
	 */
	public function test_paginates(): void {
		for ( $index = 1; $index <= 5; $index++ ) {
			$this->suppliers->insert( $this->supplier( array( 'company_name' => 'Supplier ' . $index ) ) );
		}

		$first  = $this->suppliers->paginate( array( 'per_page' => 2, 'page' => 1 ) );
		$second = $this->suppliers->paginate( array( 'per_page' => 2, 'page' => 2 ) );

		$this->assertCount( 2, $first );
		$this->assertCount( 2, $second );
		$this->assertSame( 5, $this->suppliers->count() );
		$this->assertNotSame( $first[0]->id, $second[0]->id );
	}

	/**
	 * A supplier nothing refers to can go, and takes their price list with them.
	 *
	 * @return void
	 */
	public function test_deletes_a_supplier_nothing_refers_to(): void {
		global $wpdb;

		$id = $this->suppliers->insert( $this->supplier() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture.
		$wpdb->insert(
			Tables::name( Tables::SUPPLIER_PRODUCTS ),
			array(
				'supplier_id' => $id,
				'product_id'  => 10,
				'currency'    => 'EUR',
				'created_at'  => '2026-08-12 00:00:00',
				'updated_at'  => '2026-08-12 00:00:00',
			)
		);

		$this->assertTrue( $this->suppliers->is_deletable( $id ) );
		$this->assertTrue( $this->suppliers->delete( $id ) );
		$this->assertNull( $this->suppliers->find( $id ) );

		$products = Tables::name( Tables::SUPPLIER_PRODUCTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion, table name from a constant.
		$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$products} WHERE supplier_id = %d", $id ) );

		$this->assertSame( 0, $left );
	}

	/**
	 * A supplier named on a purchase order stays, whatever anybody clicks.
	 *
	 * @return void
	 */
	public function test_refuses_to_delete_a_supplier_with_purchase_orders(): void {
		global $wpdb;

		$id = $this->suppliers->insert( $this->supplier() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture.
		$wpdb->insert(
			Tables::name( Tables::PURCHASE_ORDERS ),
			array(
				'po_number'   => 'PO-0001',
				'supplier_id' => $id,
				'currency'    => 'EUR',
				'order_date'  => '2026-08-12',
				'created_at'  => '2026-08-12 00:00:00',
				'updated_at'  => '2026-08-12 00:00:00',
			)
		);

		$this->assertSame( 1, $this->suppliers->purchase_order_count( $id ) );
		$this->assertFalse( $this->suppliers->is_deletable( $id ) );
		$this->assertFalse( $this->suppliers->delete( $id ) );
		$this->assertNotNull( $this->suppliers->find( $id ) );
	}

	/**
	 * A supplier that was never stored cannot be updated into existence.
	 *
	 * @return void
	 */
	public function test_refuses_to_update_something_without_an_id(): void {
		$this->assertFalse( $this->suppliers->update( $this->supplier() ) );
	}
}
