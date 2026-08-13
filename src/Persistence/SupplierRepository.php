<?php
/**
 * Storage for suppliers.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Persistence;

/*
 * Table names cannot be bound as SQL placeholders, so every statement below
 * interpolates one. That value always comes from the Tables constants and can
 * never come from a request; every other value is bound through prepare().
 */
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use Oxysoft\OxySuppliers\Domain\Money;
use Oxysoft\OxySuppliers\Domain\Supplier;
use Oxysoft\OxySuppliers\Domain\SupplierStatus;

/**
 * Reads and writes the supplier table.
 *
 * Suppliers are never deleted while anything refers to them: a supplier we have
 * ordered from is part of the history of those orders, and a purchase order
 * whose supplier has vanished is a document that no longer says who it was for.
 */
final class SupplierRepository {

	/**
	 * Columns a list may be sorted by.
	 *
	 * An allowlist because an ORDER BY column cannot be bound as a placeholder,
	 * so the only safe interpolation is one whose value cannot come from a
	 * request.
	 *
	 * @var array<string,string>
	 */
	private const SORTABLE = array(
		'company_name'   => 'company_name',
		'city'           => 'city',
		'country'        => 'country',
		'lead_time_days' => 'lead_time_days',
		'created_at'     => 'created_at',
	);

	/**
	 * Store a new supplier and return its id.
	 *
	 * @param Supplier $supplier The supplier to store.
	 * @return int New row id, or 0 when the insert failed.
	 */
	public function insert( Supplier $supplier ): int {
		global $wpdb;

		$now  = current_time( 'mysql', true );
		$data = $this->to_columns( $supplier );

		$data['created_at'] = $now;
		$data['updated_at'] = $now;
		$data['created_by'] = get_current_user_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, no WordPress API for it.
		$written = $wpdb->insert( Tables::name( Tables::SUPPLIERS ), $data );

		return false === $written ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Overwrite an existing supplier.
	 *
	 * @param Supplier $supplier Supplier carrying the id to update.
	 * @return bool Whether the row was written.
	 */
	public function update( Supplier $supplier ): bool {
		global $wpdb;

		if ( $supplier->id <= 0 ) {
			return false;
		}

		$data               = $this->to_columns( $supplier );
		$data['updated_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, no WordPress API for it.
		$written = $wpdb->update(
			Tables::name( Tables::SUPPLIERS ),
			$data,
			array( 'id' => $supplier->id ),
			null,
			array( '%d' )
		);

		return false !== $written;
	}

	/**
	 * One supplier, or nothing.
	 *
	 * @param int $id Row id.
	 * @return Supplier|null
	 */
	public function find( int $id ): ?Supplier {
		global $wpdb;

		$table = Tables::name( Tables::SUPPLIERS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, table name from a constant, all values bound through prepare().
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * A page of suppliers.
	 *
	 * @param array{search?:string,status?:string,orderby?:string,order?:string,per_page?:int,page?:int} $args Query arguments.
	 * @return list<Supplier>
	 */
	public function paginate( array $args = array() ): array {
		global $wpdb;

		$table    = Tables::name( Tables::SUPPLIERS );
		$where    = $this->where( $args );
		$order_by = self::SORTABLE[ $args['orderby'] ?? '' ] ?? 'company_name';
		$order    = 'desc' === strtolower( (string) ( $args['order'] ?? 'asc' ) ) ? 'DESC' : 'ASC';
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

		$sql = "SELECT * FROM {$table} {$where['sql']} ORDER BY {$order_by} {$order} LIMIT %d OFFSET %d";

		$parameters   = $where['values'];
		$parameters[] = $per_page;
		$parameters[] = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; table, sort column and direction come from constants and allowlists, every value is bound.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $parameters ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_values( array_map( array( $this, 'hydrate' ), $rows ) );
	}

	/**
	 * How many suppliers match.
	 *
	 * @param array{search?:string,status?:string} $args Query arguments.
	 * @return int
	 */
	public function count( array $args = array() ): int {
		global $wpdb;

		$table = Tables::name( Tables::SUPPLIERS );
		$where = $this->where( $args );
		$sql   = "SELECT COUNT(*) FROM {$table} {$where['sql']}";

		if ( array() === $where['values'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; the whole statement is built from a constant and holds no value at all.
			return (int) $wpdb->get_var( $sql );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, table name from a constant, all values bound through prepare().
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $where['values'] ) );
	}

	/**
	 * How many purchase orders name this supplier.
	 *
	 * @param int $id Supplier id.
	 * @return int
	 */
	public function purchase_order_count( int $id ): int {
		global $wpdb;

		$table = Tables::name( Tables::PURCHASE_ORDERS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, table name from a constant, all values bound through prepare().
		$total = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE supplier_id = %d", $id )
		);

		return (int) $total;
	}

	/**
	 * Whether this supplier may be removed rather than switched off.
	 *
	 * @param int $id Supplier id.
	 * @return bool
	 */
	public function is_deletable( int $id ): bool {
		return 0 === $this->purchase_order_count( $id );
	}

	/**
	 * Remove a supplier that nothing refers to.
	 *
	 * Refuses rather than cascades. The caller is expected to offer switching
	 * the supplier off instead, which is what the status column is for.
	 *
	 * @param int $id Supplier id.
	 * @return bool Whether the row was removed.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		if ( $id <= 0 || ! $this->is_deletable( $id ) ) {
			return false;
		}

		// Price list lines are not history: they describe an offer, not a
		// document, and there is nothing left to read them once the supplier is
		// gone.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, no WordPress API for it.
		$wpdb->delete( Tables::name( Tables::SUPPLIER_PRODUCTS ), array( 'supplier_id' => $id ), array( '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, no WordPress API for it.
		$removed = $wpdb->delete( Tables::name( Tables::SUPPLIERS ), array( 'id' => $id ), array( '%d' ) );

		return false !== $removed && $removed > 0;
	}

	/**
	 * The WHERE clause and its bound values.
	 *
	 * @param array{search?:string,status?:string} $args Query arguments.
	 * @return array{sql:string,values:list<mixed>}
	 */
	private function where( array $args ): array {
		$clauses = array();
		$values  = array();

		$search = trim( (string) ( $args['search'] ?? '' ) );

		if ( '' !== $search ) {
			global $wpdb;

			$like = '%' . $wpdb->esc_like( $search ) . '%';

			$clauses[] = '(company_name LIKE %s OR trade_name LIKE %s OR vat_number LIKE %s OR city LIKE %s)';
			$values[]  = $like;
			$values[]  = $like;
			$values[]  = $like;
			$values[]  = $like;
		}

		$status = (string) ( $args['status'] ?? '' );

		if ( null !== SupplierStatus::tryFrom( $status ) ) {
			$clauses[] = 'status = %s';
			$values[]  = $status;
		}

		return array(
			'sql'    => array() === $clauses ? '' : 'WHERE ' . implode( ' AND ', $clauses ),
			'values' => $values,
		);
	}

	/**
	 * Turn a supplier into the columns that hold it.
	 *
	 * @param Supplier $supplier The supplier.
	 * @return array<string,mixed>
	 */
	private function to_columns( Supplier $supplier ): array {
		return array(
			'company_name'          => $supplier->company_name,
			'trade_name'            => $supplier->trade_name,
			'vat_number'            => $supplier->vat_number,
			'tax_code'              => $supplier->tax_code,
			'address'               => $supplier->address,
			'postcode'              => $supplier->postcode,
			'city'                  => $supplier->city,
			'state'                 => $supplier->state,
			'country'               => $supplier->country,
			'order_email'           => $supplier->order_email,
			'billing_email'         => $supplier->billing_email,
			'phone'                 => $supplier->phone,
			'contact_name'          => $supplier->contact_name,
			'website'               => $supplier->website,
			'payment_terms'         => $supplier->payment_terms,
			'currency'              => $supplier->currency(),
			'lead_time_days'        => $supplier->lead_time_days,
			'min_order_value_minor' => $supplier->min_order_value->minor,
			'notes'                 => $supplier->notes,
			'status'                => $supplier->status->value,
		);
	}

	/**
	 * Turn a row into a supplier.
	 *
	 * @param array<string,mixed> $row Database row.
	 * @return Supplier
	 */
	private function hydrate( array $row ): Supplier {
		$currency = (string) ( $row['currency'] ?? '' );
		$currency = '' === $currency ? 'EUR' : $currency;

		return new Supplier(
			(int) $row['id'],
			(string) $row['company_name'],
			(string) $row['trade_name'],
			(string) $row['vat_number'],
			(string) $row['tax_code'],
			(string) $row['address'],
			(string) $row['postcode'],
			(string) $row['city'],
			(string) $row['state'],
			(string) $row['country'],
			(string) $row['order_email'],
			(string) $row['billing_email'],
			(string) $row['phone'],
			(string) $row['contact_name'],
			(string) $row['website'],
			(string) $row['payment_terms'],
			(int) $row['lead_time_days'],
			Money::from_minor( (int) $row['min_order_value_minor'], $currency ),
			(string) ( $row['notes'] ?? '' ),
			SupplierStatus::from_storage( (string) $row['status'] )
		);
	}
}
