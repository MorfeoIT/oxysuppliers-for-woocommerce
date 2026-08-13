<?php
/**
 * Storage for purchase orders.
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
 * Placeholder lists for the status filters are built in a loop, which the sniff
 * that counts placeholders in the literal string cannot see.
 */
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

use Oxysoft\OxySuppliers\Domain\Money;
use Oxysoft\OxySuppliers\Domain\PurchaseOrder;
use Oxysoft\OxySuppliers\Domain\PurchaseOrderLine;
use Oxysoft\OxySuppliers\Domain\PurchaseOrderStatus;
use Oxysoft\OxySuppliers\Service\PurchaseOrderNumbers;

/**
 * Reads and writes purchase orders and their lines.
 *
 * Two things worth knowing before changing anything here.
 *
 * The number is not reserved before the insert: it is proposed, and the unique
 * index decides. A failed insert is a lost race, not an error, so it is tried
 * again with a fresh number. That is why creating an order is a small loop and
 * not a single statement.
 *
 * The stored totals are a cache of the lines and are rewritten from them on
 * every save. Nothing reads them to make a decision.
 */
final class PurchaseOrderRepository {

	/**
	 * How many times to try for a free number before giving up.
	 *
	 * Each attempt loses only to somebody who saved in the same instant, so
	 * five is generous. Giving up is better than looping: something else is
	 * wrong by then.
	 */
	private const NUMBER_ATTEMPTS = 5;

	/**
	 * Columns a list may be sorted by.
	 *
	 * @var array<string,string>
	 */
	private const SORTABLE = array(
		'order_date' => 'order_date',
		'number'     => 'po_number',
		'total'      => 'total_minor',
		'expected'   => 'expected_date',
	);

	/**
	 * Take the numbering service.
	 *
	 * @param PurchaseOrderNumbers $numbers Proposes the next number.
	 */
	public function __construct( private readonly PurchaseOrderNumbers $numbers ) {
	}

	/**
	 * Store a new order and its lines.
	 *
	 * @param PurchaseOrder $order The order to store.
	 * @return PurchaseOrder|null The order with its id and number, or null when it could not be stored.
	 */
	public function create( PurchaseOrder $order ): ?PurchaseOrder {
		global $wpdb;

		$table = Tables::name( Tables::PURCHASE_ORDERS );
		$now   = current_time( 'mysql', true );

		for ( $attempt = 0; $attempt < self::NUMBER_ATTEMPTS; $attempt++ ) {
			$number = '' !== $order->number && 0 === $attempt ? $order->number : $this->numbers->next();

			$data = $this->header_columns( $order->with_number( $number ) );

			$data['created_at'] = $now;
			$data['updated_at'] = $now;
			$data['created_by'] = get_current_user_id();

			// A duplicate number is expected here, so the error is ours to
			// handle rather than the site's to display.
			$wpdb->suppress_errors( true );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, no WordPress API for it.
			$written = $wpdb->insert( $table, $data );

			$wpdb->suppress_errors( false );

			if ( false === $written ) {
				// Lost the race for that number. Ask for another one.
				continue;
			}

			$id = (int) $wpdb->insert_id;

			$this->write_lines( $id, $order->lines );

			$stored = $this->find( $id );

			if ( null !== $stored ) {
				$this->refresh_totals( $stored );
			}

			return $this->find( $id );
		}

		return null;
	}

	/**
	 * Overwrite an order's header and lines.
	 *
	 * The number is never rewritten: it is on a document that may already have
	 * been sent.
	 *
	 * @param PurchaseOrder $order The order, carrying its id.
	 * @return bool
	 */
	public function update( PurchaseOrder $order ): bool {
		global $wpdb;

		if ( $order->id <= 0 ) {
			return false;
		}

		$data = $this->header_columns( $order );

		unset( $data['po_number'] );

		$data['updated_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, no WordPress API for it.
		$written = $wpdb->update(
			Tables::name( Tables::PURCHASE_ORDERS ),
			$data,
			array( 'id' => $order->id ),
			null,
			array( '%d' )
		);

		if ( false === $written ) {
			return false;
		}

		$this->write_lines( $order->id, $order->lines );
		$this->refresh_totals( $order );

		return true;
	}

	/**
	 * Move an order to a new state.
	 *
	 * The transition is checked by the domain, which throws when it is not
	 * allowed. This only writes the answer down.
	 *
	 * @param PurchaseOrder       $order  The order.
	 * @param PurchaseOrderStatus $status Where it is going.
	 * @return bool
	 */
	public function set_status( PurchaseOrder $order, PurchaseOrderStatus $status ): bool {
		global $wpdb;

		// Throws if the move is not allowed, which is the point.
		$order->with_status( $status );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, no WordPress API for it.
		$written = $wpdb->update(
			Tables::name( Tables::PURCHASE_ORDERS ),
			array(
				'status'     => $status->value,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $order->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $written;
	}

	/**
	 * Write down that an order has gone out.
	 *
	 * @param PurchaseOrder $order The order.
	 * @return bool
	 */
	public function mark_sent( PurchaseOrder $order ): bool {
		global $wpdb;

		$sent = $order->sent_at( current_time( 'mysql', true ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, no WordPress API for it.
		$written = $wpdb->update(
			Tables::name( Tables::PURCHASE_ORDERS ),
			array(
				'status'     => $sent->status->value,
				'sent_at'    => $sent->sent_at,
				'sent_by'    => get_current_user_id(),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $order->id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		return false !== $written;
	}

	/**
	 * One order, with its lines.
	 *
	 * @param int $id Row id.
	 * @return PurchaseOrder|null
	 */
	public function find( int $id ): ?PurchaseOrder {
		global $wpdb;

		$table = Tables::name( Tables::PURCHASE_ORDERS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table from a constant, value bound.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return $this->hydrate( $row, $this->lines_of( $id ) );
	}

	/**
	 * One order by its number.
	 *
	 * @param string $number The number on the document.
	 * @return PurchaseOrder|null
	 */
	public function find_by_number( string $number ): ?PurchaseOrder {
		global $wpdb;

		$table = Tables::name( Tables::PURCHASE_ORDERS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table from a constant, value bound.
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE po_number = %s", $number ) );

		return $id > 0 ? $this->find( $id ) : null;
	}

	/**
	 * A page of orders, without their lines.
	 *
	 * The list does not need the lines, and fetching them would be one query
	 * per row.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return list<PurchaseOrder>
	 */
	public function paginate( array $args = array() ): array {
		global $wpdb;

		$table    = Tables::name( Tables::PURCHASE_ORDERS );
		$where    = $this->where( $args );
		$order_by = self::SORTABLE[ $args['orderby'] ?? '' ] ?? 'id';
		$order    = 'asc' === strtolower( (string) ( $args['order'] ?? 'desc' ) ) ? 'ASC' : 'DESC';
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

		$sql = "SELECT * FROM {$table} {$where['sql']} ORDER BY {$order_by} {$order}, id DESC LIMIT %d OFFSET %d";

		$parameters   = $where['values'];
		$parameters[] = $per_page;
		$parameters[] = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; sort column from an allowlist, every value bound.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $parameters ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$orders = array();

		foreach ( $rows as $row ) {
			$orders[] = $this->hydrate( $row, array() );
		}

		return $orders;
	}

	/**
	 * What is on its way for one article, and when it should arrive.
	 *
	 * For the product screen (§15): "Stock: 4 | On the way: 30 | ETA: 18/08".
	 * Only orders that are still expected count — a draft is a thought, and a
	 * cancelled order is not coming.
	 *
	 * @param int $product_id   Parent product.
	 * @param int $variation_id Variation, 0 for a simple product.
	 * @return array{quantity:int,eta:string|null}
	 */
	public function incoming_for_item( int $product_id, int $variation_id = 0 ): array {
		global $wpdb;

		$orders   = Tables::name( Tables::PURCHASE_ORDERS );
		$lines    = Tables::name( Tables::ORDER_ITEMS );
		$expected = PurchaseOrderStatus::expected_values();
		$statuses = implode( ',', array_fill( 0, count( $expected ), '%s' ) );

		$values = array_merge( array( $product_id, $variation_id ), $expected );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom tables from constants, every value bound.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM( i.qty_ordered - i.qty_received ) AS due, MIN( o.expected_date ) AS eta
				 FROM {$lines} i
				 INNER JOIN {$orders} o ON o.id = i.po_id
				 WHERE i.product_id = %d AND i.variation_id = %d
				   AND o.status IN ( {$statuses} )
				   AND i.qty_ordered > i.qty_received",
				$values
			),
			ARRAY_A
		);

		return array(
			'quantity' => null === $row ? 0 : (int) $row['due'],
			'eta'      => null === $row || null === $row['eta'] ? null : (string) $row['eta'],
		);
	}

	/**
	 * The stored totals for a page of orders.
	 *
	 * The list does not load lines, so an order there cannot add itself up.
	 * One query for the page rather than one per row, which is the same rule
	 * the reordering screen lives by.
	 *
	 * @param list<int> $ids Order ids.
	 * @return array<int,int> Total in minor units, keyed by order id.
	 */
	public function totals_for( array $ids ): array {
		global $wpdb;

		if ( array() === $ids ) {
			return array();
		}

		$table        = Tables::name( Tables::PURCHASE_ORDERS );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table from a constant, every value bound.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, total_minor FROM {$table} WHERE id IN ( {$placeholders} )", $ids ),
			ARRAY_A
		);

		$totals = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$totals[ (int) $row['id'] ] = (int) $row['total_minor'];
		}

		return $totals;
	}

	/**
	 * How many orders match.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return int
	 */
	public function count( array $args = array() ): int {
		global $wpdb;

		$table = Tables::name( Tables::PURCHASE_ORDERS );
		$where = $this->where( $args );
		$sql   = "SELECT COUNT(*) FROM {$table} {$where['sql']}";

		if ( array() === $where['values'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, statement built from a constant.
			return (int) $wpdb->get_var( $sql );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, every value bound.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $where['values'] ) );
	}

	/**
	 * The lines of an order.
	 *
	 * @param int $po_id Order id.
	 * @return list<PurchaseOrderLine>
	 */
	public function lines_of( int $po_id ): array {
		global $wpdb;

		$table = Tables::name( Tables::ORDER_ITEMS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table from a constant, value bound.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE po_id = %d ORDER BY sort_order ASC, id ASC", $po_id ),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$currency = $this->currency_of( $po_id );
		$lines    = array();

		foreach ( $rows as $row ) {
			$lines[] = new PurchaseOrderLine(
				(int) $row['id'],
				(int) $row['product_id'],
				(int) $row['variation_id'],
				(string) $row['sku'],
				(string) $row['supplier_sku'],
				(string) $row['description'],
				(int) $row['qty_ordered'],
				(int) $row['qty_received'],
				Money::from_minor( (int) $row['unit_cost_minor'], $currency ),
				(int) $row['discount_bp'],
				(int) $row['tax_rate_bp'],
				(int) $row['sort_order']
			);
		}

		return $lines;
	}

	/**
	 * Record how much of a line has arrived.
	 *
	 * @param int $line_id  Line id.
	 * @param int $received Total received so far.
	 * @return bool
	 */
	public function set_line_received( int $line_id, int $received ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, no WordPress API for it.
		$written = $wpdb->update(
			Tables::name( Tables::ORDER_ITEMS ),
			array( 'qty_received' => max( 0, $received ) ),
			array( 'id' => $line_id ),
			array( '%d' ),
			array( '%d' )
		);

		return false !== $written;
	}

	/**
	 * Replace an order's lines with these.
	 *
	 * @param int                     $po_id Order id.
	 * @param list<PurchaseOrderLine> $lines The lines.
	 * @return void
	 */
	private function write_lines( int $po_id, array $lines ): void {
		global $wpdb;

		$table = Tables::name( Tables::ORDER_ITEMS );

		$keep = array();

		foreach ( $lines as $position => $line ) {
			$data = array(
				'po_id'            => $po_id,
				'product_id'       => $line->product_id,
				'variation_id'     => $line->variation_id,
				'sku'              => $line->sku,
				'supplier_sku'     => $line->supplier_sku,
				'description'      => $line->description,
				'qty_ordered'      => $line->qty_ordered,
				'qty_received'     => $line->qty_received,
				'unit_cost_minor'  => $line->unit_cost->minor,
				'discount_bp'      => $line->discount_bp,
				'tax_rate_bp'      => $line->tax_rate_bp,
				'line_total_minor' => $line->net_total()->minor,
				'sort_order'       => $position,
			);

			if ( $line->id > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, no WordPress API for it.
				$wpdb->update( $table, $data, array( 'id' => $line->id ), null, array( '%d' ) );

				$keep[] = $line->id;

				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, no WordPress API for it.
			$wpdb->insert( $table, $data );

			$keep[] = (int) $wpdb->insert_id;
		}

		$this->remove_lines_except( $po_id, $keep );
	}

	/**
	 * Delete the lines of an order that are no longer on it.
	 *
	 * @param int       $po_id Order id.
	 * @param list<int> $keep  Line ids to keep.
	 * @return void
	 */
	private function remove_lines_except( int $po_id, array $keep ): void {
		global $wpdb;

		$table = Tables::name( Tables::ORDER_ITEMS );

		if ( array() === $keep ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, no WordPress API for it.
			$wpdb->delete( $table, array( 'po_id' => $po_id ), array( '%d' ) );

			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $keep ), '%d' ) );
		$values       = array_merge( array( $po_id ), $keep );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table from a constant, every value bound.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE po_id = %d AND id NOT IN ( {$placeholders} )",
				$values
			)
		);
	}

	/**
	 * Rewrite the stored totals from the lines.
	 *
	 * @param PurchaseOrder $order The order.
	 * @return void
	 */
	private function refresh_totals( PurchaseOrder $order ): void {
		global $wpdb;

		$lines = $this->lines_of( $order->id );
		$fresh = $order->with_lines( $lines );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, no WordPress API for it.
		$wpdb->update(
			Tables::name( Tables::PURCHASE_ORDERS ),
			array(
				'subtotal_minor' => $fresh->subtotal()->minor,
				'tax_minor'      => $fresh->tax()->minor,
				'total_minor'    => $fresh->total()->minor,
			),
			array( 'id' => $order->id ),
			array( '%d', '%d', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * The currency an order is written in.
	 *
	 * @param int $po_id Order id.
	 * @return string
	 */
	private function currency_of( int $po_id ): string {
		global $wpdb;

		$table = Tables::name( Tables::PURCHASE_ORDERS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table from a constant, value bound.
		$currency = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT currency FROM {$table} WHERE id = %d", $po_id )
		);

		return '' === $currency ? 'EUR' : $currency;
	}

	/**
	 * The WHERE clause and its bound values.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return array{sql:string,values:list<mixed>}
	 */
	private function where( array $args ): array {
		global $wpdb;

		$clauses = array();
		$values  = array();

		$supplier_id = (int) ( $args['supplier_id'] ?? 0 );

		if ( $supplier_id > 0 ) {
			$clauses[] = 'supplier_id = %d';
			$values[]  = $supplier_id;
		}

		$statuses = array();

		foreach ( (array) ( $args['status'] ?? array() ) as $candidate ) {
			$status = PurchaseOrderStatus::tryFrom( (string) $candidate );

			if ( null !== $status ) {
				$statuses[] = $status->value;
			}
		}

		if ( array() !== $statuses ) {
			$clauses[] = 'status IN ( ' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ' )';
			$values    = array_merge( $values, $statuses );
		}

		$search = trim( (string) ( $args['search'] ?? '' ) );

		if ( '' !== $search ) {
			$like      = '%' . $wpdb->esc_like( $search ) . '%';
			$clauses[] = '( po_number LIKE %s OR supplier_reference LIKE %s )';
			$values[]  = $like;
			$values[]  = $like;
		}

		if ( ! empty( $args['overdue'] ) ) {
			$expected  = PurchaseOrderStatus::expected_values();
			$clauses[] = 'expected_date IS NOT NULL AND expected_date < CURDATE() AND status IN ( '
				. implode( ',', array_fill( 0, count( $expected ), '%s' ) ) . ' )';
			$values    = array_merge( $values, $expected );
		}

		return array(
			'sql'    => array() === $clauses ? '' : 'WHERE ' . implode( ' AND ', $clauses ),
			'values' => $values,
		);
	}

	/**
	 * Turn an order into the columns that hold it.
	 *
	 * @param PurchaseOrder $order The order.
	 * @return array<string,mixed>
	 */
	private function header_columns( PurchaseOrder $order ): array {
		return array(
			'po_number'          => $order->number,
			'supplier_id'        => $order->supplier_id,
			'status'             => $order->status->value,
			'currency'           => $order->currency,
			'order_date'         => $order->order_date,
			'expected_date'      => $order->expected_date,
			'supplier_reference' => $order->supplier_reference,
			'delivery_address'   => $order->delivery_address,
			'payment_terms'      => $order->payment_terms,
			'internal_notes'     => $order->internal_notes,
			'supplier_notes'     => $order->supplier_notes,
			'subtotal_minor'     => $order->subtotal()->minor,
			'tax_minor'          => $order->tax()->minor,
			'total_minor'        => $order->total()->minor,
		);
	}

	/**
	 * Turn a row into an order.
	 *
	 * @param array<string,mixed>     $row   Database row.
	 * @param list<PurchaseOrderLine> $lines Its lines.
	 * @return PurchaseOrder
	 */
	private function hydrate( array $row, array $lines ): PurchaseOrder {
		$currency = (string) ( $row['currency'] ?? '' );
		$currency = '' === $currency ? 'EUR' : $currency;

		return new PurchaseOrder(
			(int) $row['id'],
			(string) $row['po_number'],
			(int) $row['supplier_id'],
			PurchaseOrderStatus::from_storage( (string) $row['status'] ),
			$currency,
			(string) $row['order_date'],
			null === $row['expected_date'] ? null : (string) $row['expected_date'],
			(string) $row['supplier_reference'],
			(string) ( $row['delivery_address'] ?? '' ),
			(string) $row['payment_terms'],
			(string) ( $row['internal_notes'] ?? '' ),
			(string) ( $row['supplier_notes'] ?? '' ),
			$lines,
			null === $row['sent_at'] ? null : (string) $row['sent_at']
		);
	}
}
