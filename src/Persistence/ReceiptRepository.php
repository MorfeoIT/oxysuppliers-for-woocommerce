<?php
/**
 * Storage for goods receipts.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Persistence;

/*
 * Table names cannot be bound as SQL placeholders, so every statement below
 * interpolates one, always from the Tables constants. Every value is bound.
 */
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

use Oxysoft\OxySuppliers\Domain\Money;
use Oxysoft\OxySuppliers\Domain\Receipt;
use Oxysoft\OxySuppliers\Domain\ReceiptLine;

/**
 * Reads and writes receipts, and holds the lock that stops two of them landing
 * at once.
 *
 * The interesting method here is `claim()`. It is the only place in the plugin
 * that writes a row **in order to find out whether it may carry on** — the
 * unique index on the idempotency key is the thing that decides, not any check
 * this code could make first. Asking "has this already been received?" and then
 * receiving it is two statements with a gap in the middle, and a warehouse with
 * two phones will find that gap.
 */
final class ReceiptRepository {

	/**
	 * How long a lock is believed, in seconds.
	 *
	 * Long enough for a slow receipt, short enough that a request which died
	 * mid-write does not block the order until somebody notices.
	 */
	private const LOCK_SECONDS = 60;

	/**
	 * Try to claim an idempotency key.
	 *
	 * Writes the receipt's header row. Returns the new id, or 0 when the key
	 * has already been used — which is not an error: it means this exact
	 * delivery has already been recorded, and the caller should say so rather
	 * than record it twice.
	 *
	 * @param Receipt $receipt The receipt to claim.
	 * @return int New row id, or 0 when the key was taken.
	 */
	public function claim( Receipt $receipt ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// The duplicate is expected here: it is how the question is answered.
		$wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, no WordPress API for it.
		$written = $wpdb->insert(
			Tables::name( Tables::RECEIPTS ),
			array(
				'po_id'               => $receipt->po_id,
				'idempotency_key'     => $receipt->idempotency_key,
				'reverses_receipt_id' => $receipt->reverses_id,
				'reference'           => $receipt->reference,
				'notes'               => '' === $receipt->notes ? null : $receipt->notes,
				'stock_applied'       => 0,
				'received_at'         => $now,
				'received_by'         => get_current_user_id(),
				'created_at'          => $now,
			)
		);

		$wpdb->suppress_errors( false );

		return false === $written ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Write one line of a receipt.
	 *
	 * @param int         $receipt_id The receipt.
	 * @param ReceiptLine $line       What arrived.
	 * @param float|null  $before     Stock before, or null when it was not touched.
	 * @param float|null  $after      Stock after, or null when it was not touched.
	 * @param string      $skipped    Why stock was not touched, when it was not.
	 * @return int
	 */
	public function add_line( int $receipt_id, ReceiptLine $line, ?float $before = null, ?float $after = null, string $skipped = '' ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, no WordPress API for it.
		$written = $wpdb->insert(
			Tables::name( Tables::RECEIPT_ITEMS ),
			array(
				'receipt_id'             => $receipt_id,
				'po_item_id'             => $line->po_line_id,
				'product_id'             => $line->product_id,
				'variation_id'           => $line->variation_id,
				'qty'                    => $line->quantity,
				'actual_unit_cost_minor' => null === $line->actual_cost ? null : $line->actual_cost->minor,
				'currency'               => null === $line->actual_cost ? 'EUR' : $line->actual_cost->currency,
				'stock_before'           => $before,
				'stock_after'            => $after,
				'stock_skipped_reason'   => $skipped,
			)
		);

		return false === $written ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Write down what the stock was before and after a line moved it.
	 *
	 * Done after the movement rather than with the line, because the movement
	 * happens outside the transaction the line was written in.
	 *
	 * @param int        $line_id The receipt line.
	 * @param float|null $before  Stock before, or null when it was not touched.
	 * @param float|null $after   Stock after, or null when it was not touched.
	 * @param string     $skipped Why it was not touched, when it was not.
	 * @return void
	 */
	public function set_line_stock( int $line_id, ?float $before, ?float $after, string $skipped ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, no WordPress API for it.
		$wpdb->update(
			Tables::name( Tables::RECEIPT_ITEMS ),
			array(
				'stock_before'         => $before,
				'stock_after'          => $after,
				'stock_skipped_reason' => $skipped,
			),
			array( 'id' => $line_id ),
			null,
			array( '%d' )
		);
	}

	/**
	 * Record that stock was moved for a receipt.
	 *
	 * @param int $receipt_id The receipt.
	 * @return void
	 */
	public function mark_stock_applied( int $receipt_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, no WordPress API for it.
		$wpdb->update(
			Tables::name( Tables::RECEIPTS ),
			array( 'stock_applied' => 1 ),
			array( 'id' => $receipt_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Remove a receipt that never got off the ground.
	 *
	 * Only ever used when the work after claiming the key could not be done, so
	 * that the key is free for a real attempt. A receipt with lines is never
	 * deleted.
	 *
	 * @param int $receipt_id The receipt.
	 * @return void
	 */
	public function discard( int $receipt_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, no WordPress API for it.
		$wpdb->delete( Tables::name( Tables::RECEIPT_ITEMS ), array( 'receipt_id' => $receipt_id ), array( '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, no WordPress API for it.
		$wpdb->delete( Tables::name( Tables::RECEIPTS ), array( 'id' => $receipt_id ), array( '%d' ) );
	}

	/**
	 * One receipt, with its lines.
	 *
	 * @param int $id Row id.
	 * @return Receipt|null
	 */
	public function find( int $id ): ?Receipt {
		global $wpdb;

		$table = Tables::name( Tables::RECEIPTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table from a constant, value bound.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * The receipt that already used a key, if there is one.
	 *
	 * @param string $key Idempotency key.
	 * @return Receipt|null
	 */
	public function find_by_key( string $key ): ?Receipt {
		global $wpdb;

		$table = Tables::name( Tables::RECEIPTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table from a constant, value bound.
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE idempotency_key = %s", $key ) );

		return $id > 0 ? $this->find( $id ) : null;
	}

	/**
	 * Every receipt against an order, oldest first.
	 *
	 * @param int $po_id Order id.
	 * @return list<Receipt>
	 */
	public function for_order( int $po_id ): array {
		global $wpdb;

		$table = Tables::name( Tables::RECEIPTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table from a constant, value bound.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE po_id = %d ORDER BY id ASC", $po_id ),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_values( array_map( array( $this, 'hydrate' ), $rows ) );
	}

	/**
	 * How much has really been received against each line of an order.
	 *
	 * **This is the truth**, summed from the receipts themselves. The
	 * `qty_received` column on the order line is a copy kept for speed, and a
	 * copy is something that can drift; this is what the copy is checked
	 * against, and what every decision is made from.
	 *
	 * @param int $po_id Order id.
	 * @return array<int,int> Quantity received, keyed by order line id.
	 */
	public function received_by_line( int $po_id ): array {
		global $wpdb;

		$receipts = Tables::name( Tables::RECEIPTS );
		$lines    = Tables::name( Tables::RECEIPT_ITEMS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom tables from constants, value bound.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.po_item_id, SUM( l.qty ) AS received
				 FROM {$lines} l
				 INNER JOIN {$receipts} r ON r.id = l.receipt_id
				 WHERE r.po_id = %d
				 GROUP BY l.po_item_id",
				$po_id
			),
			ARRAY_A
		);

		$totals = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$totals[ (int) $row['po_item_id'] ] = (int) $row['received'];
		}

		return $totals;
	}

	/**
	 * Take the lock on an order.
	 *
	 * A row that says who is writing, rather than `GET_LOCK()` — which behaves
	 * differently on managed databases — or a transient, which may sit in an
	 * object cache that two PHP processes do not share.
	 *
	 * A lock older than a minute is treated as abandoned: a request that died
	 * mid-write should not block the order until somebody notices.
	 *
	 * @param int    $po_id Order id.
	 * @param string $token Who is asking.
	 * @return bool Whether the lock was taken.
	 */
	public function lock( int $po_id, string $token ): bool {
		global $wpdb;

		$token = self::token( $token );
		$table = Tables::name( Tables::PURCHASE_ORDERS );
		$now   = current_time( 'mysql', true );
		$stale = gmdate( 'Y-m-d H:i:s', strtotime( $now ) - self::LOCK_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table from a constant, every value bound.
		$taken = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET lock_token = %s, lock_acquired_at = %s
				 WHERE id = %d AND ( lock_token IS NULL OR lock_acquired_at < %s )",
				$token,
				$now,
				$po_id,
				$stale
			)
		);

		return 1 === $taken;
	}

	/**
	 * Give the lock back.
	 *
	 * Only the holder can, so a request that has already lost its lock to the
	 * stale timeout cannot take somebody else's away.
	 *
	 * @param int    $po_id Order id.
	 * @param string $token Who is giving it back.
	 * @return void
	 */
	public function unlock( int $po_id, string $token ): void {
		global $wpdb;

		$token = self::token( $token );
		$table = Tables::name( Tables::PURCHASE_ORDERS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table from a constant, every value bound.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET lock_token = NULL, lock_acquired_at = NULL WHERE id = %d AND lock_token = %s",
				$po_id,
				$token
			)
		);
	}

	/**
	 * A lock token that fits the column it is stored in.
	 *
	 * `lock_token` is `char(32)`, and a UUID is thirty-six characters. Outside
	 * strict mode MySQL takes the first thirty-two and says nothing — after
	 * which the lock can be taken and **never released**, because the value
	 * being matched on the way out is not the value that was stored. Every
	 * receipt after the first would then be told the order was busy.
	 *
	 * Hashing here rather than widening the column: the token is opaque, and a
	 * schema change to hold four more characters is a migration nobody should
	 * have to run.
	 *
	 * @param string $token Whatever the caller had.
	 * @return string Exactly 32 characters.
	 */
	private static function token( string $token ): string {
		return md5( $token ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_md5 -- Not a security hash: a 32-character identifier for a 32-character column.
	}

	/**
	 * The lines of a receipt.
	 *
	 * @param int $receipt_id The receipt.
	 * @return list<ReceiptLine>
	 */
	private function lines_of( int $receipt_id ): array {
		global $wpdb;

		$table = Tables::name( Tables::RECEIPT_ITEMS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table from a constant, value bound.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE receipt_id = %d ORDER BY id ASC", $receipt_id ),
			ARRAY_A
		);

		$lines = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$lines[] = new ReceiptLine(
				(int) $row['id'],
				(int) $row['po_item_id'],
				(int) $row['product_id'],
				(int) $row['variation_id'],
				(int) $row['qty'],
				null === $row['actual_unit_cost_minor']
					? null
					: Money::from_minor( (int) $row['actual_unit_cost_minor'], (string) $row['currency'] )
			);
		}

		return $lines;
	}

	/**
	 * Turn a row into a receipt.
	 *
	 * @param array<string,mixed> $row Database row.
	 * @return Receipt
	 */
	private function hydrate( array $row ): Receipt {
		return new Receipt(
			(int) $row['id'],
			(int) $row['po_id'],
			(string) $row['idempotency_key'],
			$this->lines_of( (int) $row['id'] ),
			null === $row['reverses_receipt_id'] ? null : (int) $row['reverses_receipt_id'],
			(string) $row['reference'],
			(string) ( $row['notes'] ?? '' ),
			1 === (int) $row['stock_applied'],
			(string) $row['received_at'],
			(int) $row['received_by']
		);
	}
}
