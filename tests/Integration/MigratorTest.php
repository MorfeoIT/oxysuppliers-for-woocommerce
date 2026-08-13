<?php
/**
 * The schema, inside a real WordPress.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Tests\Integration;

use Oxysoft\OxySuppliers\Persistence\Migrator;
use Oxysoft\OxySuppliers\Persistence\Tables;
use WP_UnitTestCase;

/**
 * dbDelta is fussy enough that the only way to know the schema is right is to
 * run it.
 */
final class MigratorTest extends WP_UnitTestCase {

	/**
	 * Start each test from a database with no tables and no version.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		foreach ( Tables::all() as $table ) {
			$name = Tables::name( $table );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture, table name from a constant.
			$wpdb->query( "DROP TABLE IF EXISTS {$name}" );
		}

		delete_option( Migrator::VERSION_OPTION );
	}

	/**
	 * Every table the plugin owns exists after migrating.
	 *
	 * @return void
	 */
	public function test_creates_every_table(): void {
		( new Migrator() )->migrate();

		$existing = $this->existing_tables();

		foreach ( Tables::all() as $table ) {
			$name = Tables::name( $table );

			$this->assertContains(
				$name,
				$existing,
				"Missing table: {$name}\nFound: " . implode( ', ', $existing ) . "\n" . $this->diagnosis()
			);
		}

		$this->assertSame( 8, count( Tables::all() ) );
	}

	/**
	 * The plugin's tables that the database will admit to having.
	 *
	 * @return list<string>
	 */
	private function existing_tables(): array {
		global $wpdb;

		$like = $wpdb->esc_like( $wpdb->prefix . 'oxysuppliers' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Test assertion.
		return array_values( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) );
	}

	/**
	 * What dbDelta actually did, statement by statement.
	 *
	 * Only ever built when an assertion has already failed. A missing table has
	 * two very different causes — a statement MySQL refused, or a statement it
	 * accepted and this connection cannot yet see — and this tells them apart
	 * instead of leaving it to guesswork.
	 *
	 * @return string
	 */
	private function diagnosis(): string {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$schema = new \ReflectionMethod( Migrator::class, 'schema' );
		$schema->setAccessible( true );

		/** @var list<string> $statements */
		$statements = $schema->invoke( new Migrator() );
		$report     = array( 'dbDelta, run again by hand:' );

		foreach ( $statements as $statement ) {
			preg_match( '/CREATE TABLE (\S+)/', $statement, $matches );

			$result = dbDelta( $statement );

			$report[] = sprintf(
				'  %s => %s | last_error: %s',
				$matches[1] ?? '?',
				(string) wp_json_encode( $result ),
				'' === $wpdb->last_error ? '(none)' : $wpdb->last_error
			);
		}

		return implode( "\n", $report );
	}

	/**
	 * The version is recorded, and a second run does nothing.
	 *
	 * @return void
	 */
	public function test_is_idempotent(): void {
		$migrator = new Migrator();

		$this->assertTrue( $migrator->needs_migration() );

		$migrator->migrate();

		$this->assertFalse( $migrator->needs_migration() );
		$this->assertSame( Migrator::SCHEMA_VERSION, (int) get_option( Migrator::VERSION_OPTION ) );

		// Running it again must be safe: an upgrade interrupted halfway is
		// simply run again.
		$migrator->migrate();

		$this->assertFalse( $migrator->needs_migration() );
	}

	/**
	 * The columns the rest of the plugin depends on are really there.
	 *
	 * A table can exist and still be the wrong shape: dbDelta silently skips a
	 * statement it cannot parse.
	 *
	 * @return void
	 */
	public function test_the_columns_that_matter_exist(): void {
		global $wpdb;

		( new Migrator() )->migrate();

		$expected = array(
			Tables::SUPPLIERS         => array( 'id', 'company_name', 'currency', 'min_order_value_minor', 'status' ),
			Tables::SUPPLIER_PRODUCTS => array( 'supplier_id', 'product_id', 'variation_id', 'unit_cost_minor', 'order_multiple', 'is_preferred' ),
			Tables::PURCHASE_ORDERS   => array( 'po_number', 'supplier_id', 'status', 'lock_token', 'total_minor' ),
			Tables::ORDER_ITEMS       => array( 'po_id', 'qty_ordered', 'qty_received', 'unit_cost_minor' ),
			Tables::RECEIPTS          => array( 'po_id', 'idempotency_key', 'reverses_receipt_id', 'stock_applied' ),
			Tables::RECEIPT_ITEMS     => array( 'receipt_id', 'po_item_id', 'qty', 'actual_unit_cost_minor', 'stock_before', 'stock_after' ),
			Tables::COST_HISTORY      => array( 'supplier_id', 'product_id', 'old_cost_minor', 'new_cost_minor' ),
			Tables::LOGS              => array( 'object_type', 'object_id', 'action', 'old_value', 'new_value' ),
		);

		foreach ( $expected as $table => $columns ) {
			$name = Tables::name( $table );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion, table name from a constant.
			$found = $wpdb->get_col( "SHOW COLUMNS FROM {$name}" );

			foreach ( $columns as $column ) {
				$this->assertContains( $column, $found, "{$name} is missing {$column}" );
			}
		}
	}

	/**
	 * Receiving the same goods twice has to be impossible at this level, not
	 * only in the interface.
	 *
	 * @return void
	 */
	public function test_the_idempotency_key_is_unique(): void {
		global $wpdb;

		( new Migrator() )->migrate();

		$receipts = Tables::name( Tables::RECEIPTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion, table name from a constant.
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$receipts}", ARRAY_A );

		$unique = array();

		foreach ( $indexes as $index ) {
			if ( '0' === (string) $index['Non_unique'] ) {
				$unique[] = $index['Column_name'];
			}
		}

		$this->assertContains( 'idempotency_key', $unique );
	}

	/**
	 * Two purchase orders cannot carry the same number.
	 *
	 * @return void
	 */
	public function test_the_purchase_order_number_is_unique(): void {
		global $wpdb;

		( new Migrator() )->migrate();

		$orders = Tables::name( Tables::PURCHASE_ORDERS );
		$row    = array(
			'po_number'   => 'PO-0001',
			'supplier_id' => 1,
			'currency'    => 'EUR',
			'order_date'  => '2026-08-12',
			'created_at'  => '2026-08-12 00:00:00',
			'updated_at'  => '2026-08-12 00:00:00',
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture.
		$this->assertSame( 1, $wpdb->insert( $orders, $row ) );

		$wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture: this one must fail.
		$second = $wpdb->insert( $orders, $row );

		$wpdb->suppress_errors( false );

		$this->assertFalse( $second, 'A duplicate purchase order number must be refused by the database.' );
	}
}
