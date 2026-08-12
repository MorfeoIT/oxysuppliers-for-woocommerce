<?php
/**
 * The plugin's table names.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Persistence;

/**
 * One place that knows what the tables are called.
 *
 * Table names cannot be bound as SQL placeholders, so every query interpolates
 * them. Routing them all through these constants means the interpolated value
 * can never come from a request.
 *
 * See docs/02_MODELLO_DATI.md.
 */
final class Tables {

	public const SUPPLIERS         = 'oxysuppliers_suppliers';
	public const SUPPLIER_PRODUCTS = 'oxysuppliers_supplier_products';
	public const PURCHASE_ORDERS   = 'oxysuppliers_purchase_orders';
	public const ORDER_ITEMS       = 'oxysuppliers_purchase_order_items';
	public const RECEIPTS          = 'oxysuppliers_receipts';
	public const RECEIPT_ITEMS     = 'oxysuppliers_receipt_items';
	public const COST_HISTORY      = 'oxysuppliers_cost_history';
	public const LOGS              = 'oxysuppliers_logs';

	/**
	 * Fully qualified table name for the current site.
	 *
	 * @param string $table One of this class's constants.
	 * @return string
	 */
	public static function name( string $table ): string {
		global $wpdb;

		return $wpdb->prefix . $table;
	}

	/**
	 * Every table the plugin owns.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::SUPPLIERS,
			self::SUPPLIER_PRODUCTS,
			self::PURCHASE_ORDERS,
			self::ORDER_ITEMS,
			self::RECEIPTS,
			self::RECEIPT_ITEMS,
			self::COST_HISTORY,
			self::LOGS,
		);
	}
}
