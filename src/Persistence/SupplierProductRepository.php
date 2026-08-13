<?php
/**
 * Storage for supplier price lists.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Persistence;

/*
 * Table names cannot be bound as SQL placeholders, so every statement below
 * interpolates one. That value always comes from the Tables constants and can
 * never come from a request; every other value is bound through prepare().
 *
 * preferred_for_items() builds its placeholder list in a loop, one pair per
 * article, which the sniff that counts placeholders in the literal string
 * cannot see.
 */
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

use Oxysoft\OxySuppliers\Domain\Money;
use Oxysoft\OxySuppliers\Domain\OrderTerms;
use Oxysoft\OxySuppliers\Domain\SupplierProduct;

/**
 * Reads and writes what each supplier charges for each article.
 *
 * One supplier has one line per article, which the unique index enforces. That
 * matters more than it looks: two lines for the same pair would mean two
 * different costs for the same purchase, and nothing to say which is right.
 */
final class SupplierProductRepository {

	/**
	 * Store a line, replacing the one already there for that supplier and
	 * article.
	 *
	 * Saving the same pair twice is an edit, not a second line, whether the
	 * caller knew the row id or not.
	 *
	 * @param SupplierProduct $line The line to store.
	 * @return int Row id, or 0 when the write failed.
	 */
	public function save( SupplierProduct $line ): int {
		global $wpdb;

		$existing = $line->id > 0
			? $line->id
			: $this->id_of( $line->supplier_id, $line->product_id, $line->variation_id );

		$data = array(
			'supplier_id'          => $line->supplier_id,
			'product_id'           => $line->product_id,
			'variation_id'         => $line->variation_id,
			'supplier_sku'         => $line->supplier_sku,
			'supplier_description' => $line->supplier_description,
			'currency'             => $line->unit_cost->currency,
			'unit_cost_minor'      => $line->unit_cost->minor,
			'min_order_qty'        => $line->terms->minimum,
			'order_multiple'       => max( 1, $line->terms->multiple ),
			'pack_qty'             => max( 1, $line->terms->pack ),
			'lead_time_days'       => $line->lead_time_days,
			'is_preferred'         => $line->is_preferred ? 1 : 0,
			'notes'                => $line->notes,
			'updated_at'           => current_time( 'mysql', true ),
		);

		if ( $existing > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, no WordPress API for it.
			$written = $wpdb->update(
				Tables::name( Tables::SUPPLIER_PRODUCTS ),
				$data,
				array( 'id' => $existing ),
				null,
				array( '%d' )
			);

			return false === $written ? 0 : $existing;
		}

		$data['created_at'] = $data['updated_at'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, no WordPress API for it.
		$written = $wpdb->insert( Tables::name( Tables::SUPPLIER_PRODUCTS ), $data );

		return false === $written ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * One line, or nothing.
	 *
	 * @param int $id Row id.
	 * @return SupplierProduct|null
	 */
	public function find( int $id ): ?SupplierProduct {
		global $wpdb;

		$table = Tables::name( Tables::SUPPLIER_PRODUCTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, table name from a constant, all values bound through prepare().
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Every supplier who sells this article.
	 *
	 * Preferred first, then cheapest. The order is a suggestion and not a
	 * decision: the cheapest supplier is not always the right one, which is why
	 * the preferred flag exists at all.
	 *
	 * @param int $product_id   Parent product.
	 * @param int $variation_id Variation, 0 for a simple product.
	 * @return list<SupplierProduct>
	 */
	public function for_item( int $product_id, int $variation_id = 0 ): array {
		global $wpdb;

		$table = Tables::name( Tables::SUPPLIER_PRODUCTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, table name from a constant, all values bound through prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE product_id = %d AND variation_id = %d
				 ORDER BY is_preferred DESC, unit_cost_minor ASC, id ASC",
				$product_id,
				$variation_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_values( array_map( array( $this, 'hydrate' ), $rows ) );
	}

	/**
	 * The supplier to buy from, for a whole page of articles at once.
	 *
	 * One query for the page rather than one per row: this is what keeps the
	 * reordering screen the same cost at twenty rows and at two hundred.
	 *
	 * @param list<array{0:int,1:int}> $items Pairs of product id and variation id.
	 * @return array<string,SupplierProduct> Keyed by "product_id:variation_id".
	 */
	public function preferred_for_items( array $items ): array {
		global $wpdb;

		if ( array() === $items ) {
			return array();
		}

		$table      = Tables::name( Tables::SUPPLIER_PRODUCTS );
		$conditions = array();
		$parameters = array();

		foreach ( $items as $item ) {
			$conditions[] = '(product_id = %d AND variation_id = %d)';
			$parameters[] = (int) $item[0];
			$parameters[] = (int) $item[1];
		}

		$where = implode( ' OR ', $conditions );

		// Ordered the same way as for_item(), so the row that comes out first
		// per article is the same one either way round.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, table name from a constant, every value bound through prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where} ORDER BY is_preferred DESC, unit_cost_minor ASC, id ASC",
				$parameters
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$preferred = array();

		foreach ( $rows as $row ) {
			$key = (int) $row['product_id'] . ':' . (int) $row['variation_id'];

			// First one wins, and the order above decides which that is.
			if ( ! isset( $preferred[ $key ] ) ) {
				$preferred[ $key ] = $this->hydrate( $row );
			}
		}

		return $preferred;
	}

	/**
	 * Everything one supplier sells.
	 *
	 * @param int $supplier_id Supplier id.
	 * @param int $limit       How many at most.
	 * @return list<SupplierProduct>
	 */
	public function for_supplier( int $supplier_id, int $limit = 200 ): array {
		global $wpdb;

		$table = Tables::name( Tables::SUPPLIER_PRODUCTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table from a constant, every value bound.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE supplier_id = %d ORDER BY supplier_sku ASC, id ASC LIMIT %d",
				$supplier_id,
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_values( array_map( array( $this, 'hydrate' ), $rows ) );
	}

	/**
	 * The supplier to buy this article from by default.
	 *
	 * Falls back to the cheapest when nobody has been marked preferred, because
	 * a shop with one supplier should not have to tick a box to say so.
	 *
	 * @param int $product_id   Parent product.
	 * @param int $variation_id Variation, 0 for a simple product.
	 * @return SupplierProduct|null
	 */
	public function preferred_for( int $product_id, int $variation_id = 0 ): ?SupplierProduct {
		$lines = $this->for_item( $product_id, $variation_id );

		return $lines[0] ?? null;
	}

	/**
	 * Make one line the preferred one for its article.
	 *
	 * Clearing the others first is what makes "preferred" mean something: two
	 * preferred suppliers for one article is the same as none.
	 *
	 * @param int $id Row id.
	 * @return bool Whether anything changed.
	 */
	public function set_preferred( int $id ): bool {
		global $wpdb;

		$line = $this->find( $id );

		if ( null === $line ) {
			return false;
		}

		$table = Tables::name( Tables::SUPPLIER_PRODUCTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, table name from a constant, all values bound through prepare().
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET is_preferred = 0 WHERE product_id = %d AND variation_id = %d",
				$line->product_id,
				$line->variation_id
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, no WordPress API for it.
		$written = $wpdb->update(
			$table,
			array( 'is_preferred' => 1 ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		return false !== $written;
	}

	/**
	 * Take the preferred flag off every line for an article.
	 *
	 * @param int $product_id   Parent product.
	 * @param int $variation_id Variation, 0 for a simple product.
	 * @return void
	 */
	public function clear_preferred( int $product_id, int $variation_id = 0 ): void {
		global $wpdb;

		$table = Tables::name( Tables::SUPPLIER_PRODUCTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, table name from a constant, all values bound through prepare().
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET is_preferred = 0 WHERE product_id = %d AND variation_id = %d",
				$product_id,
				$variation_id
			)
		);
	}

	/**
	 * Remove a line.
	 *
	 * A price list line describes an offer, not a document: there is nothing
	 * left to read it once it is gone, so this one really does delete.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		if ( $id <= 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, no WordPress API for it.
		$removed = $wpdb->delete( Tables::name( Tables::SUPPLIER_PRODUCTS ), array( 'id' => $id ), array( '%d' ) );

		return false !== $removed && $removed > 0;
	}

	/**
	 * How many articles a supplier has a price for.
	 *
	 * @param int $supplier_id Supplier id.
	 * @return int
	 */
	public function count_for_supplier( int $supplier_id ): int {
		global $wpdb;

		$table = Tables::name( Tables::SUPPLIER_PRODUCTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, table name from a constant, all values bound through prepare().
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE supplier_id = %d", $supplier_id )
		);
	}

	/**
	 * The id of the line for a supplier and article, if there is one.
	 *
	 * @param int $supplier_id  Supplier.
	 * @param int $product_id   Parent product.
	 * @param int $variation_id Variation, 0 for a simple product.
	 * @return int 0 when there is none.
	 */
	private function id_of( int $supplier_id, int $product_id, int $variation_id ): int {
		global $wpdb;

		$table = Tables::name( Tables::SUPPLIER_PRODUCTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, table name from a constant, all values bound through prepare().
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE supplier_id = %d AND product_id = %d AND variation_id = %d",
				$supplier_id,
				$product_id,
				$variation_id
			)
		);
	}

	/**
	 * Turn a row into a price list line.
	 *
	 * @param array<string,mixed> $row Database row.
	 * @return SupplierProduct
	 */
	private function hydrate( array $row ): SupplierProduct {
		$currency = (string) ( $row['currency'] ?? '' );
		$currency = '' === $currency ? 'EUR' : $currency;

		return new SupplierProduct(
			(int) $row['id'],
			(int) $row['supplier_id'],
			(int) $row['product_id'],
			(int) $row['variation_id'],
			(string) $row['supplier_sku'],
			(string) $row['supplier_description'],
			Money::from_minor( (int) $row['unit_cost_minor'], $currency ),
			new OrderTerms(
				(int) $row['min_order_qty'],
				(int) $row['order_multiple'],
				(int) $row['pack_qty']
			),
			(int) $row['lead_time_days'],
			null === $row['last_cost_minor'] ? null : Money::from_minor( (int) $row['last_cost_minor'], $currency ),
			null === $row['last_cost_at'] ? null : (string) $row['last_cost_at'],
			1 === (int) $row['is_preferred'],
			(string) ( $row['notes'] ?? '' )
		);
	}
}
