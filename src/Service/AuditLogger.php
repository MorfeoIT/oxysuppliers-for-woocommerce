<?php
/**
 * The audit trail.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Service;

use Oxysoft\OxySuppliers\Persistence\Tables;

/*
 * Table names cannot be bound as SQL placeholders, so the statement below
 * interpolates one. That value always comes from the Tables constants and can
 * never come from a request; every other value is bound.
 */
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Writes down who changed what.
 *
 * Every stock movement and every commercially interesting change goes through
 * here. The log is append-only and the interface offers no way to edit or
 * delete a line: a record that can be rewritten answers a different question
 * from the one it was kept for.
 *
 * See docs/04_SICUREZZA.md.
 */
final class AuditLogger {

	public const OBJECT_SUPPLIER = 'supplier';
	public const OBJECT_ORDER    = 'purchase_order';
	public const OBJECT_RECEIPT  = 'receipt';
	public const OBJECT_STOCK    = 'stock';

	public const ACTION_CREATED    = 'created';
	public const ACTION_UPDATED    = 'updated';
	public const ACTION_DELETED    = 'deleted';
	public const ACTION_STATUS     = 'status_changed';
	public const ACTION_STOCK_MOVE = 'stock_changed';

	/**
	 * Record one change.
	 *
	 * Failing to write the log must not take down the operation being logged,
	 * but it must not pass unnoticed either: the row is best effort, the return
	 * value tells the caller whether it landed.
	 *
	 * @param string                   $object_type One of the OBJECT_ constants.
	 * @param string                   $object_id   Identifier of the thing that changed.
	 * @param string                   $action      One of the ACTION_ constants.
	 * @param array<string,mixed>|null $before      State before, if any.
	 * @param array<string,mixed>|null $after       State after, if any.
	 * @param string                   $message     Human-readable note.
	 * @return bool Whether the line was written.
	 */
	public function log(
		string $object_type,
		string $object_id,
		string $action,
		?array $before = null,
		?array $after = null,
		string $message = ''
	): bool {
		global $wpdb;

		$table = Tables::name( Tables::LOGS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, no WordPress API for it.
		$written = $wpdb->insert(
			$table,
			array(
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'action'      => $action,
				'old_value'   => null === $before ? null : (string) wp_json_encode( $before ),
				'new_value'   => null === $after ? null : (string) wp_json_encode( $after ),
				'message'     => '' === $message ? null : $message,
				'user_id'     => get_current_user_id(),
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return false !== $written;
	}
}
