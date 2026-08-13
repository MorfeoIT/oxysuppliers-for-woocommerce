<?php
/**
 * Giving purchase orders their numbers.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Service;

use Oxysoft\OxySuppliers\Persistence\Tables;
use Oxysoft\OxySuppliers\Support\Settings;

/*
 * The table name comes from a constant and never from a request; every value is
 * bound.
 */
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Works out what the next order should be called.
 *
 * Deliberately **not** a reservation. This proposes a number by looking at the
 * highest one already used this year; the unique index on the column is what
 * actually stops two orders sharing it. Two people saving in the same second
 * both propose the same number, one insert fails, and the caller asks again.
 *
 * The alternative — a counter in an option, read and written — loses one of the
 * two numbers under exactly the same race, and does it silently.
 */
final class PurchaseOrderNumbers {

	/**
	 * Propose the next number.
	 *
	 * @param string $year Four-digit year, or empty for the current one.
	 * @return string
	 */
	public function next( string $year = '' ): string {
		global $wpdb;

		$year   = '' === $year ? gmdate( 'Y' ) : $year;
		$prefix = (string) Settings::get( 'po_number_prefix' ) . $year . '-';
		$table  = Tables::name( Tables::PURCHASE_ORDERS );

		// The highest sequence already used for this prefix. Compared as a
		// number, not as text, or "PO-2026-10" would sort below "PO-2026-9".
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table from a constant, value bound.
		$highest = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX( CAST( SUBSTRING( po_number, %d ) AS UNSIGNED ) )
				 FROM {$table}
				 WHERE po_number LIKE %s",
				strlen( $prefix ) + 1,
				$wpdb->esc_like( $prefix ) . '%'
			)
		);

		$sequence = $highest + 1;

		/**
		 * Filters the number a new purchase order is given.
		 *
		 * Whatever comes back has to be unique: the database will refuse a
		 * duplicate, and the caller will simply ask for another one.
		 *
		 * @since 0.1.0
		 *
		 * @param string $number   The proposed number.
		 * @param string $prefix   The prefix in use.
		 * @param int    $sequence The sequence within the year.
		 */
		return (string) apply_filters(
			'oxysuppliers_purchase_order_number',
			$prefix . str_pad( (string) $sequence, 4, '0', STR_PAD_LEFT ),
			$prefix,
			$sequence
		);
	}
}
