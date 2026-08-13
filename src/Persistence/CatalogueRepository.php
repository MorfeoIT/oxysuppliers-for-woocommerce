<?php
/**
 * Reading the catalogue for the reordering screen.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Persistence;

/*
 * Table names cannot be bound as SQL placeholders, so every statement below
 * interpolates them. They all come from $wpdb or from the Tables constants and
 * can never come from a request; every other value is bound through prepare().
 *
 * The placeholder lists are built in a loop, one pair per article on the page,
 * so the sniff that counts placeholders in the literal string cannot see them.
 * It is counting the right thing and looking in the wrong place.
 */
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

use Oxysoft\OxySuppliers\Domain\PurchaseOrderStatus;
use Oxysoft\OxySuppliers\Domain\RequirementContext;
use Oxysoft\OxySuppliers\Support\Settings;

/**
 * Gathers what the reordering screen needs, in bulk.
 *
 * The rule this class exists to keep: **the number of queries does not grow
 * with the number of rows.** A page of twenty and a page of two hundred cost
 * the same handful of queries, because everything is fetched for the whole page
 * at once and handed to the engine as plain data.
 *
 * Stock and sales are read from WooCommerce's own lookup tables, which are
 * indexed for exactly this and are what WooCommerce Analytics itself reads.
 * Orders are never scanned.
 *
 * Every string literal in the SQL below is written with single quotes. Double
 * quotes would work on most servers and turn into identifiers on one with
 * ANSI_QUOTES set, which is the sort of failure that only ever happens on
 * somebody else's hosting.
 */
final class CatalogueRepository {

	/**
	 * Columns a list may be sorted by.
	 *
	 * An allowlist, because an ORDER BY cannot be bound as a placeholder. The
	 * default is how far under its own threshold an article has fallen, which
	 * is the order somebody scanning this screen actually wants.
	 *
	 * @var array<string,string>
	 */
	private const SORTABLE = array(
		'shortfall' => "( CAST( l.stock_quantity AS SIGNED ) - CAST( COALESCE( NULLIF( lsa.meta_value, '' ), 0 ) AS SIGNED ) )",
		'name'      => 'p.post_title',
		'sku'       => 'l.sku',
		'stock'     => 'CAST( l.stock_quantity AS SIGNED )',
	);

	/**
	 * A page of articles, with everything the engine needs to judge them.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return list<RequirementContext>
	 */
	public function paginate( array $args = array() ): array {
		global $wpdb;

		$per_page = max( 1, min( 500, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$order_by = self::SORTABLE[ $args['orderby'] ?? '' ] ?? self::SORTABLE['shortfall'];
		$order    = 'desc' === strtolower( (string) ( $args['order'] ?? 'asc' ) ) ? 'DESC' : 'ASC';

		$where = $this->where( $args );

		$sql = $this->select_clause()
			. ' ' . $where['sql']
			. " ORDER BY {$order_by} {$order}, l.product_id ASC"
			. ' LIMIT %d OFFSET %d';

		// prepare() fills placeholders in the order it meets them, and the
		// first one is in the SELECT list, not the WHERE. The order of this
		// array is not a matter of taste.
		$parameters = array_merge(
			array( $this->default_reorder_point() ),
			$where['values'],
			array( $per_page, ( $page - 1 ) * $per_page )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Lookup tables; sort column from an allowlist, every value bound.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $parameters ), ARRAY_A );

		if ( ! is_array( $rows ) || array() === $rows ) {
			return array();
		}

		return $this->to_contexts( array_values( $rows ) );
	}

	/**
	 * How many articles match.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return int
	 */
	public function count( array $args = array() ): int {
		global $wpdb;

		$where = $this->where( $args );
		$sql   = 'SELECT COUNT(*) ' . $this->from_clause() . ' ' . $where['sql'];

		if ( array() === $where['values'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Lookup tables, statement built from constants, no values at all.
			return (int) $wpdb->get_var( $sql );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Lookup tables, every value bound through prepare().
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $where['values'] ) );
	}

	/**
	 * Whether the sales figures can be trusted.
	 *
	 * WooCommerce fills its product lookup table **asynchronously**, through
	 * Action Scheduler. On a shop where that has never run — a fresh install, an
	 * old shop whose historical data was never imported, one where Analytics is
	 * switched off — the table is empty while the orders are all there.
	 *
	 * A reordering screen that shows "sold 0" in that situation is telling
	 * somebody not to buy anything, with a straight face. So it is asked, and
	 * said out loud.
	 *
	 * @return bool
	 */
	public function sales_data_is_stale(): bool {
		global $wpdb;

		$lookup = $wpdb->prefix . 'wc_order_product_lookup';

		if ( ! $this->table_exists( $lookup ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Lookup table, name from $wpdb.
		$in_lookup = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$lookup} LIMIT 1" );

		if ( $in_lookup > 0 ) {
			return false;
		}

		// The lookup is empty, which only matters if the shop has actually sold
		// something.
		return $this->has_any_sales();
	}

	/**
	 * Whether this shop has any sales at all.
	 *
	 * @return bool
	 */
	private function has_any_sales(): bool {
		global $wpdb;

		$hpos = $wpdb->prefix . 'wc_orders';

		if ( $this->table_exists( $hpos ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Order table, name from $wpdb.
			$found = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$hpos} WHERE status IN ( 'wc-completed', 'wc-processing' ) LIMIT 1"
			);

			return $found > 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Core table, name from $wpdb.
		$legacy = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_status IN ( 'wc-completed', 'wc-processing' ) LIMIT 1"
		);

		return $legacy > 0;
	}

	/**
	 * Turn rows into contexts, filling in sales, reservations, goods on order
	 * and suppliers — one query each, for the whole page.
	 *
	 * @param list<array<string,mixed>> $rows Rows from the catalogue query.
	 * @return list<RequirementContext>
	 */
	private function to_contexts( array $rows ): array {
		$items = array();

		foreach ( $rows as $row ) {
			$items[] = array( (int) $row['prod_id'], (int) $row['var_id'] );
		}

		$sales     = $this->sales_for( $items );
		$reserved  = $this->reserved_for( $items );
		$incoming  = $this->incoming_for( $items );
		$suppliers = ( new SupplierProductRepository() )->preferred_for_items( $items );

		$multiplier = $this->target_multiplier();
		$contexts   = array();

		foreach ( $rows as $row ) {
			$product_id   = (int) $row['prod_id'];
			$variation_id = (int) $row['var_id'];
			$key          = $product_id . ':' . $variation_id;

			$reorder_point = (int) $row['low_stock'];
			$sold          = $sales[ $key ] ?? array( 0, 0, 0 );

			$contexts[] = new RequirementContext(
				$product_id,
				$variation_id,
				(string) $row['sku'],
				(string) $row['post_title'],
				null === $row['stock_quantity'] ? null : (int) $row['stock_quantity'],
				$reserved[ $key ] ?? 0,
				$incoming[ $key ] ?? 0,
				$reorder_point,
				$reorder_point * $multiplier,
				$sold[0],
				$sold[1],
				$sold[2],
				$suppliers[ $key ] ?? null
			);
		}

		return $contexts;
	}

	/**
	 * Units sold in the last 7, 30 and 90 days, for a whole page.
	 *
	 * One query with three conditional sums rather than three queries, and
	 * certainly not one per row.
	 *
	 * @param list<array{0:int,1:int}> $items Product and variation ids.
	 * @return array<string,array{0:int,1:int,2:int}>
	 */
	private function sales_for( array $items ): array {
		global $wpdb;

		$lookup = $wpdb->prefix . 'wc_order_product_lookup';

		if ( array() === $items || ! $this->table_exists( $lookup ) ) {
			return array();
		}

		$conditions = array();
		$parameters = array();

		foreach ( $items as $item ) {
			$conditions[] = '( product_id = %d AND variation_id = %d )';
			$parameters[] = $item[0];
			$parameters[] = $item[1];
		}

		$where = implode( ' OR ', $conditions );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Lookup table, name from $wpdb; the placeholder list is built from the page's own items and every value is bound.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, variation_id,
					SUM( CASE WHEN date_created >= DATE_SUB( NOW(), INTERVAL 7 DAY ) THEN product_qty ELSE 0 END ) AS sold_7,
					SUM( CASE WHEN date_created >= DATE_SUB( NOW(), INTERVAL 30 DAY ) THEN product_qty ELSE 0 END ) AS sold_30,
					SUM( product_qty ) AS sold_90
				 FROM {$lookup}
				 WHERE ( {$where} ) AND date_created >= DATE_SUB( NOW(), INTERVAL 90 DAY )
				 GROUP BY product_id, variation_id",
				$parameters
			),
			ARRAY_A
		);

		$sales = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$sales[ (int) $row['product_id'] . ':' . (int) $row['variation_id'] ] = array(
				(int) $row['sold_7'],
				(int) $row['sold_30'],
				(int) $row['sold_90'],
			);
		}

		return $sales;
	}

	/**
	 * Stock held for orders being paid for, for a whole page.
	 *
	 * @param list<array{0:int,1:int}> $items Product and variation ids.
	 * @return array<string,int>
	 */
	private function reserved_for( array $items ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wc_reserved_stock';

		if ( array() === $items || ! $this->table_exists( $table ) ) {
			return array();
		}

		// The reservation table knows an article by one id, which is the
		// variation's when there is one.
		$ids          = array();
		$placeholders = array();

		foreach ( $items as $item ) {
			$ids[]          = 0 !== $item[1] ? $item[1] : $item[0];
			$placeholders[] = '%d';
		}

		$list = implode( ',', $placeholders );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Lookup table, name from $wpdb; the placeholder list is built from the page's own items and every value is bound.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, SUM( stock_quantity ) AS held
				 FROM {$table}
				 WHERE product_id IN ( {$list} ) AND `expires` > NOW()
				 GROUP BY product_id",
				$ids
			),
			ARRAY_A
		);

		$held = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$held[ (int) $row['product_id'] ] = (int) $row['held'];
		}

		$reserved = array();

		foreach ( $items as $item ) {
			$id = 0 !== $item[1] ? $item[1] : $item[0];

			if ( isset( $held[ $id ] ) ) {
				$reserved[ $item[0] . ':' . $item[1] ] = $held[ $id ];
			}
		}

		return $reserved;
	}

	/**
	 * Units ordered from suppliers and not yet received, for a whole page.
	 *
	 * Returns nothing until there are purchase orders to read, which is the
	 * next sprint. It is written now so the screen does not have to be rebuilt
	 * then, and so the free suggestion never proposes reordering something that
	 * is already in a van.
	 *
	 * @param list<array{0:int,1:int}> $items Product and variation ids.
	 * @return array<string,int>
	 */
	private function incoming_for( array $items ): array {
		global $wpdb;

		if ( array() === $items ) {
			return array();
		}

		$orders = Tables::name( Tables::PURCHASE_ORDERS );
		$lines  = Tables::name( Tables::ORDER_ITEMS );

		$conditions = array();
		$parameters = array();

		foreach ( $items as $item ) {
			$conditions[] = '( i.product_id = %d AND i.variation_id = %d )';
			$parameters[] = $item[0];
			$parameters[] = $item[1];
		}

		$where    = implode( ' OR ', $conditions );
		$expected = PurchaseOrderStatus::expected_values();
		$statuses = implode( ',', array_fill( 0, count( $expected ), '%s' ) );
		$values   = array_merge( $parameters, $expected );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom tables from constants; the placeholder list is built from the page's own items and every value is bound.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.product_id, i.variation_id, SUM( i.qty_ordered - i.qty_received ) AS due
				 FROM {$lines} i
				 INNER JOIN {$orders} o ON o.id = i.po_id
				 WHERE ( {$where} ) AND o.status IN ( {$statuses} ) AND i.qty_ordered > i.qty_received
				 GROUP BY i.product_id, i.variation_id",
				$values
			),
			ARRAY_A
		);

		$incoming = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$incoming[ (int) $row['product_id'] . ':' . (int) $row['variation_id'] ] = (int) $row['due'];
		}

		return $incoming;
	}

	/**
	 * How full to fill an article back up, as a multiple of its reorder point.
	 *
	 * WooCommerce knows when to warn you and not how much to buy, so this is
	 * the one number the plugin has to supply. A filter rather than only a
	 * setting, because a shop with a real replenishment policy will want to
	 * work it out per article.
	 *
	 * @return int
	 */
	private function target_multiplier(): int {
		/**
		 * Filters how full an article is filled back up.
		 *
		 * @since 0.1.0
		 *
		 * @param int $multiplier Multiple of the reorder point. At least 1.
		 */
		$multiplier = (int) apply_filters(
			'oxysuppliers_target_multiplier',
			(int) Settings::get( 'requirement_target_multiplier' )
		);

		return max( 1, $multiplier );
	}

	/**
	 * The columns the screen reads.
	 *
	 * @return string
	 */
	private function select_clause(): string {
		return "SELECT l.product_id, l.sku, l.stock_quantity, l.stock_status, p.post_title,
				CASE WHEN p.post_type = 'product_variation' THEN p.post_parent ELSE l.product_id END AS prod_id,
				CASE WHEN p.post_type = 'product_variation' THEN l.product_id ELSE 0 END AS var_id,
				CAST( COALESCE( NULLIF( lsa.meta_value, '' ), %d ) AS SIGNED ) AS low_stock "
			. $this->from_clause();
	}

	/**
	 * The tables the screen reads from.
	 *
	 * @return string
	 */
	private function from_clause(): string {
		global $wpdb;

		$lookup = $wpdb->prefix . 'wc_product_meta_lookup';

		return "FROM {$lookup} l
			INNER JOIN {$wpdb->posts} p ON p.ID = l.product_id
			LEFT JOIN {$wpdb->postmeta} lsa ON lsa.post_id = l.product_id AND lsa.meta_key = '_low_stock_amount'";
	}

	/**
	 * The WHERE clause and its bound values.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return array{sql:string,values:list<mixed>}
	 */
	private function where( array $args ): array {
		global $wpdb;

		$clauses = array(
			"p.post_status IN ( 'publish', 'private' )",
			"p.post_type IN ( 'product', 'product_variation' )",

			// This one line leaves out two things at once, and both are right:
			// articles WooCommerce is not counting, which have no shortage to
			// report, and the parents of variable products, which are not what
			// gets bought.
			'l.stock_quantity IS NOT NULL',
		);

		$values = array();

		$search = trim( (string) ( $args['search'] ?? '' ) );

		if ( '' !== $search ) {
			$like      = '%' . $wpdb->esc_like( $search ) . '%';
			$clauses[] = '( l.sku LIKE %s OR p.post_title LIKE %s )';
			$values[]  = $like;
			$values[]  = $like;
		}

		// A named set of articles, which is how the reordering screen turns
		// what somebody ticked into an order. The pairs come from the page they
		// were looking at, and they are re-read here rather than trusted: the
		// quantities have to be worked out from the stock as it is now, not
		// from numbers that were on a screen a minute ago.
		$articles = (array) ( $args['articles'] ?? array() );

		if ( array() !== $articles ) {
			$pairs = array();

			foreach ( $articles as $article ) {
				$pairs[]  = "( CASE WHEN p.post_type = 'product_variation' THEN p.post_parent ELSE l.product_id END = %d
					AND CASE WHEN p.post_type = 'product_variation' THEN l.product_id ELSE 0 END = %d )";
				$values[] = (int) $article[0];
				$values[] = (int) $article[1];
			}

			$clauses[] = '( ' . implode( ' OR ', $pairs ) . ' )';
		}

		if ( ! empty( $args['below_reorder_point'] ) ) {
			$clauses[] = "CAST( l.stock_quantity AS SIGNED ) <= CAST( COALESCE( NULLIF( lsa.meta_value, '' ), %d ) AS SIGNED )";
			$values[]  = $this->default_reorder_point();
		}

		if ( ! empty( $args['out_of_stock'] ) ) {
			$clauses[] = 'CAST( l.stock_quantity AS SIGNED ) <= 0';
		}

		$supplier_products = Tables::name( Tables::SUPPLIER_PRODUCTS );
		$article           = "sp.product_id = CASE WHEN p.post_type = 'product_variation' THEN p.post_parent ELSE l.product_id END
			AND sp.variation_id = CASE WHEN p.post_type = 'product_variation' THEN l.product_id ELSE 0 END";

		// The two filters that show what is missing rather than hiding it.
		if ( ! empty( $args['without_supplier'] ) ) {
			$clauses[] = "NOT EXISTS ( SELECT 1 FROM {$supplier_products} sp WHERE {$article} )";
		}

		if ( ! empty( $args['without_cost'] ) ) {
			$clauses[] = "NOT EXISTS ( SELECT 1 FROM {$supplier_products} sp WHERE {$article} AND sp.unit_cost_minor > 0 )";
		}

		$supplier_id = (int) ( $args['supplier_id'] ?? 0 );

		if ( $supplier_id > 0 ) {
			$clauses[] = "EXISTS ( SELECT 1 FROM {$supplier_products} sp WHERE {$article} AND sp.supplier_id = %d )";
			$values[]  = $supplier_id;
		}

		$category_id = (int) ( $args['category_id'] ?? 0 );

		if ( $category_id > 0 ) {
			$clauses[] = "EXISTS (
				SELECT 1 FROM {$wpdb->term_relationships} tr
				WHERE tr.object_id = CASE WHEN p.post_type = 'product_variation' THEN p.post_parent ELSE l.product_id END
				  AND tr.term_taxonomy_id = %d
			)";
			$values[]  = $category_id;
		}

		return array(
			'sql'    => 'WHERE ' . implode( ' AND ', $clauses ),
			'values' => $values,
		);
	}

	/**
	 * The shop's own low stock threshold, used when an article has none.
	 *
	 * @return int
	 */
	private function default_reorder_point(): int {
		return max( 0, (int) get_option( 'woocommerce_notify_low_stock_amount', 2 ) );
	}

	/**
	 * Whether a table is there.
	 *
	 * @param string $name Fully qualified table name.
	 * @return bool
	 */
	private function table_exists( string $name ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Existence check, value bound.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );
	}
}
