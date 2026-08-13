<?php
/**
 * Price list storage, against a real database.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Tests\Integration;

use Oxysoft\OxySuppliers\Domain\SupplierProduct;
use Oxysoft\OxySuppliers\Persistence\Migrator;
use Oxysoft\OxySuppliers\Persistence\SupplierProductRepository;
use Oxysoft\OxySuppliers\Persistence\Tables;
use WP_UnitTestCase;

/**
 * One supplier, one article, one line — and what "preferred" has to mean.
 */
final class SupplierProductRepositoryTest extends WP_UnitTestCase {

	private SupplierProductRepository $lines;

	/**
	 * Make sure the table is there and empty.
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

		$table = Tables::name( Tables::SUPPLIER_PRODUCTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture, table name from a constant.
		$wpdb->query( "DELETE FROM {$table}" );

		$this->lines = new SupplierProductRepository();
	}

	/**
	 * A line, from the fields a form would send.
	 *
	 * @param array<string,mixed> $overrides Values to change.
	 * @return SupplierProduct
	 */
	private function line( array $overrides = array() ): SupplierProduct {
		return SupplierProduct::from_fields(
			array_merge(
				array(
					'supplier_id'    => 1,
					'product_id'     => 100,
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
			)
		);
	}

	/**
	 * What goes in comes back out.
	 *
	 * @return void
	 */
	public function test_stores_and_reads_a_line(): void {
		$id = $this->lines->save( $this->line() );

		$this->assertGreaterThan( 0, $id );

		$stored = $this->lines->find( $id );

		$this->assertNotNull( $stored );
		$this->assertSame( 1, $stored->supplier_id );
		$this->assertSame( 100, $stored->product_id );
		$this->assertSame( 'MX-123', $stored->supplier_sku );
		$this->assertSame( 1180, $stored->unit_cost->minor );
		$this->assertSame( 10, $stored->terms->minimum );
		$this->assertSame( 5, $stored->terms->multiple );
		$this->assertSame( 3, $stored->lead_time_days );
	}

	/**
	 * Saving the same supplier and article twice is an edit, not a second line.
	 *
	 * Two lines for one pair would mean two costs for the same purchase, with
	 * nothing to say which is right.
	 *
	 * @return void
	 */
	public function test_saving_the_same_pair_twice_updates_it(): void {
		$first  = $this->lines->save( $this->line() );
		$second = $this->lines->save( $this->line( array( 'unit_cost' => '9.90' ) ) );

		$this->assertSame( $first, $second );
		$this->assertCount( 1, $this->lines->for_item( 100 ) );

		$stored = $this->lines->find( $first );

		$this->assertNotNull( $stored );
		$this->assertSame( 990, $stored->unit_cost->minor );
	}

	/**
	 * A variation is a different article from its parent.
	 *
	 * @return void
	 */
	public function test_a_variation_is_a_different_article(): void {
		$this->lines->save( $this->line() );
		$this->lines->save( $this->line( array( 'variation_id' => 101 ) ) );

		$this->assertCount( 1, $this->lines->for_item( 100, 0 ) );
		$this->assertCount( 1, $this->lines->for_item( 100, 101 ) );
	}

	/**
	 * The exit criterion of the sprint: two suppliers on one article, with
	 * different terms, and one of them preferred.
	 *
	 * @return void
	 */
	public function test_two_suppliers_for_one_article(): void {
		$abc = $this->lines->save(
			$this->line(
				array(
					'supplier_id'    => 1,
					'unit_cost'      => '11.80',
					'min_order_qty'  => '10',
					'order_multiple' => '5',
					'lead_time_days' => '3',
				)
			)
		);

		$def = $this->lines->save(
			$this->line(
				array(
					'supplier_id'    => 2,
					'supplier_sku'   => '7719',
					'unit_cost'      => '11.20',
					'min_order_qty'  => '20',
					'order_multiple' => '10',
					'lead_time_days' => '7',
				)
			)
		);

		$found = $this->lines->for_item( 100 );

		$this->assertCount( 2, $found );

		// Nobody preferred yet, so the cheapest leads.
		$this->assertSame( $def, $found[0]->id );
		$this->assertSame( 1120, $found[0]->unit_cost->minor );

		// The cheapest is not always the right one, which is the whole reason
		// the flag exists.
		$this->lines->set_preferred( $abc );

		$found = $this->lines->for_item( 100 );

		$this->assertSame( $abc, $found[0]->id );
		$this->assertTrue( $found[0]->is_preferred );
		$this->assertFalse( $found[1]->is_preferred );

		$preferred = $this->lines->preferred_for( 100 );

		$this->assertNotNull( $preferred );
		$this->assertSame( $abc, $preferred->id );

		// And their terms are their own.
		$this->assertSame( 20, $this->lines->find( $def )->terms->round_up( 15 ) );
		$this->assertSame( 15, $this->lines->find( $abc )->terms->round_up( 15 ) );
	}

	/**
	 * Two preferred suppliers for one article is the same as none.
	 *
	 * @return void
	 */
	public function test_only_one_line_can_be_preferred(): void {
		$first  = $this->lines->save( $this->line( array( 'supplier_id' => 1 ) ) );
		$second = $this->lines->save( $this->line( array( 'supplier_id' => 2 ) ) );

		$this->lines->set_preferred( $first );
		$this->lines->set_preferred( $second );

		$preferred = array_filter(
			$this->lines->for_item( 100 ),
			static fn ( SupplierProduct $line ): bool => $line->is_preferred
		);

		$this->assertCount( 1, $preferred );
		$this->assertSame( $second, array_values( $preferred )[0]->id );
	}

	/**
	 * Choosing a preferred supplier for one variation leaves the others alone.
	 *
	 * @return void
	 */
	public function test_preferring_one_variation_does_not_touch_another(): void {
		$small = $this->lines->save( $this->line( array( 'variation_id' => 101 ) ) );
		$large = $this->lines->save( $this->line( array( 'variation_id' => 102 ) ) );

		$this->lines->set_preferred( $small );
		$this->lines->set_preferred( $large );

		$this->assertTrue( $this->lines->find( $small )->is_preferred );
		$this->assertTrue( $this->lines->find( $large )->is_preferred );
	}

	/**
	 * A shop with one supplier should not have to tick a box to say so.
	 *
	 * @return void
	 */
	public function test_the_cheapest_stands_in_when_nobody_is_preferred(): void {
		$this->lines->save( $this->line( array( 'supplier_id' => 1, 'unit_cost' => '11.80' ) ) );
		$cheap = $this->lines->save( $this->line( array( 'supplier_id' => 2, 'unit_cost' => '9.90' ) ) );

		$preferred = $this->lines->preferred_for( 100 );

		$this->assertNotNull( $preferred );
		$this->assertSame( $cheap, $preferred->id );
		$this->assertFalse( $preferred->is_preferred );
	}

	/**
	 * An article nobody sells has no preferred supplier, and that is not an
	 * error.
	 *
	 * @return void
	 */
	public function test_an_article_with_no_supplier_has_none(): void {
		$this->assertNull( $this->lines->preferred_for( 999 ) );
		$this->assertSame( array(), $this->lines->for_item( 999 ) );
	}

	/**
	 * Clearing leaves the lines and takes away the choice.
	 *
	 * @return void
	 */
	public function test_clearing_the_preference_keeps_the_lines(): void {
		$id = $this->lines->save( $this->line() );

		$this->lines->set_preferred( $id );
		$this->lines->clear_preferred( 100, 0 );

		$this->assertCount( 1, $this->lines->for_item( 100 ) );
		$this->assertFalse( $this->lines->find( $id )->is_preferred );
	}

	/**
	 * A price list line describes an offer, not a document, so it really goes.
	 *
	 * @return void
	 */
	public function test_deletes_a_line(): void {
		$id = $this->lines->save( $this->line() );

		$this->assertTrue( $this->lines->delete( $id ) );
		$this->assertNull( $this->lines->find( $id ) );
		$this->assertFalse( $this->lines->delete( $id ) );
	}

	/**
	 * The database refuses a duplicate even if the code above it does not.
	 *
	 * @return void
	 */
	public function test_the_database_refuses_a_second_line_for_the_same_pair(): void {
		global $wpdb;

		$this->lines->save( $this->line() );

		$table = Tables::name( Tables::SUPPLIER_PRODUCTS );
		$row   = array(
			'supplier_id'  => 1,
			'product_id'   => 100,
			'variation_id' => 0,
			'currency'     => 'EUR',
			'created_at'   => '2026-08-13 00:00:00',
			'updated_at'   => '2026-08-13 00:00:00',
		);

		$wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture: this one must fail.
		$second = $wpdb->insert( $table, $row );

		$wpdb->suppress_errors( false );

		$this->assertFalse( $second );
	}

	/**
	 * How many articles a supplier prices.
	 *
	 * @return void
	 */
	public function test_counts_a_suppliers_articles(): void {
		$this->lines->save( $this->line( array( 'product_id' => 100 ) ) );
		$this->lines->save( $this->line( array( 'product_id' => 200 ) ) );
		$this->lines->save( $this->line( array( 'supplier_id' => 2, 'product_id' => 100 ) ) );

		$this->assertSame( 2, $this->lines->count_for_supplier( 1 ) );
		$this->assertSame( 1, $this->lines->count_for_supplier( 2 ) );
		$this->assertSame( 0, $this->lines->count_for_supplier( 3 ) );
	}
}
