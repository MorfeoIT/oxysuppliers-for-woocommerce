<?php
/**
 * What each article has cost, and when.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Persistence;

/*
 * Table names come from the Tables constants; every value is bound.
 */
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use Oxysoft\OxySuppliers\Domain\Money;

/**
 * The record of what was really paid.
 *
 * Written by the free plugin because recording a fact costs nothing and the
 * audit needs it; the reports built on top of it are what the pro plugin adds.
 *
 * **Nothing here is ever rewritten.** A cost that turns out to be wrong is
 * followed by another row saying what it is now — which is the same rule as the
 * receipts, and for the same reason: a plugin that edits history in place cannot
 * be asked what it knew last month.
 */
final class CostHistoryRepository {

	/**
	 * Write down what an article cost.
	 *
	 * Keys: supplier_id, product_id, variation_id, old_cost (Money|null),
	 * new_cost (Money|null), source, po_id, receipt_id.
	 *
	 * **A null new_cost is a real entry**, not a missing one: it says the thing
	 * we thought we knew has been taken back. Undoing the first delivery of an
	 * article leaves exactly that, and writing the ordered cost instead would
	 * invent a fact nobody ever paid.
	 *
	 * @param array<string,mixed> $entry What happened.
	 * @return int Row id, or 0 when it could not be written.
	 */
	public function record( array $entry ): int {
		global $wpdb;

		$new = $entry['new_cost'];
		$old = $entry['old_cost'];

		// Currency still has to be recorded when the amount is not: it says
		// which currency stopped being known.
		$currency = null !== $new ? $new->currency : ( null !== $old ? $old->currency : (string) get_option( 'woocommerce_currency', 'EUR' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, no WordPress API for it.
		$written = $wpdb->insert(
			Tables::name( Tables::COST_HISTORY ),
			array(
				'supplier_id'    => $entry['supplier_id'],
				'product_id'     => $entry['product_id'],
				'variation_id'   => $entry['variation_id'],
				'currency'       => $currency,
				'old_cost_minor' => null === $old ? null : $old->minor,
				'new_cost_minor' => null === $new ? null : $new->minor,
				'source'         => $entry['source'],
				'po_id'          => $entry['po_id'],
				'receipt_id'     => $entry['receipt_id'],
				'changed_at'     => current_time( 'mysql', true ),
				'changed_by'     => get_current_user_id(),
			)
		);

		return false === $written ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * What an article cost, as at a date.
	 *
	 * The most recent entry on or before that day. Returns **null** when nothing
	 * is known, and never zero: a cost of nothing is an answer, not knowing is
	 * not, and confusing the two overstates a profit.
	 *
	 * @param int    $product_id   Parent product.
	 * @param int    $variation_id Variation, 0 for a simple product.
	 * @param string $on           Date, "Y-m-d". Empty for the latest.
	 * @return Money|null
	 */
	public function cost_on( int $product_id, int $variation_id, string $on = '' ): ?Money {
		global $wpdb;

		$table = Tables::name( Tables::COST_HISTORY );

		if ( '' === $on ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table from a constant, values bound.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT new_cost_minor, currency FROM {$table}
					 WHERE product_id = %d AND variation_id = %d
					 ORDER BY changed_at DESC, id DESC LIMIT 1",
					$product_id,
					$variation_id
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table from a constant, values bound.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT new_cost_minor, currency FROM {$table}
					 WHERE product_id = %d AND variation_id = %d AND changed_at <= %s
					 ORDER BY changed_at DESC, id DESC LIMIT 1",
					$product_id,
					$variation_id,
					$on . ' 23:59:59'
				),
				ARRAY_A
			);
		}

		// Two different nulls, one answer: no entry at all, and an entry saying
		// what we knew has been taken back. Both mean we do not know.
		if ( ! is_array( $row ) || null === $row['new_cost_minor'] ) {
			return null;
		}

		return Money::from_minor( (int) $row['new_cost_minor'], (string) $row['currency'] );
	}

	/**
	 * What an article cost before a given delivery was recorded.
	 *
	 * This is what undoing that delivery has to put back, and it is already
	 * written down: the entry the delivery wrote says what it replaced. Asking
	 * "what does it cost now" instead would answer with the very figure being
	 * taken away, which is how a reversal ends up changing nothing.
	 *
	 * **Null is a real answer**: nothing was known before that delivery.
	 *
	 * @param int $receipt_id   The delivery being undone.
	 * @param int $product_id   Parent product.
	 * @param int $variation_id Variation, 0 for a simple product.
	 * @return Money|null
	 */
	public function cost_replaced_by( int $receipt_id, int $product_id, int $variation_id = 0 ): ?Money {
		global $wpdb;

		$table = Tables::name( Tables::COST_HISTORY );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table from a constant, values bound.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT old_cost_minor, currency FROM {$table}
				 WHERE receipt_id = %d AND product_id = %d AND variation_id = %d
				 ORDER BY id ASC LIMIT 1",
				$receipt_id,
				$product_id,
				$variation_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || null === $row['old_cost_minor'] ) {
			return null;
		}

		return Money::from_minor( (int) $row['old_cost_minor'], (string) $row['currency'] );
	}

	/**
	 * The whole series for one article, most recent first.
	 *
	 * @param int $product_id   Parent product.
	 * @param int $variation_id Variation, 0 for a simple product.
	 * @param int $limit        How many at most.
	 * @return list<array<string,mixed>>
	 */
	public function history( int $product_id, int $variation_id = 0, int $limit = 50 ): array {
		global $wpdb;

		$table = Tables::name( Tables::COST_HISTORY );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table from a constant, values bound.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE product_id = %d AND variation_id = %d
				 ORDER BY changed_at DESC, id DESC LIMIT %d",
				$product_id,
				$variation_id,
				max( 1, min( 200, $limit ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * How many entries there are for an article.
	 *
	 * @param int $product_id   Parent product.
	 * @param int $variation_id Variation, 0 for a simple product.
	 * @return int
	 */
	public function count_for( int $product_id, int $variation_id = 0 ): int {
		global $wpdb;

		$table = Tables::name( Tables::COST_HISTORY );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table from a constant, values bound.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND variation_id = %d",
				$product_id,
				$variation_id
			)
		);
	}
}
