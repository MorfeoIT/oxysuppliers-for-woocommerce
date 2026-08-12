<?php
/**
 * Schema creation and versioned upgrades.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Persistence;

/**
 * Creates the plugin's tables and moves them between schema versions.
 *
 * Migrations are idempotent: each run checks the current state before acting,
 * so an upgrade interrupted halfway can simply be run again. Ordinary upgrades
 * never drop a column or delete a row.
 *
 * See docs/02_MODELLO_DATI.md.
 */
final class Migrator {

	/**
	 * Schema version this build expects.
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Option holding the installed schema version.
	 */
	public const VERSION_OPTION = 'oxysuppliers_db_version';

	/**
	 * Bring the database up to SCHEMA_VERSION.
	 *
	 * @return void
	 */
	public function migrate(): void {
		if ( ! $this->needs_migration() ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( $this->schema() as $statement ) {
			dbDelta( $statement );
		}

		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Whether the installed schema is older than this build expects.
	 *
	 * @return bool
	 */
	public function needs_migration(): bool {
		return (int) get_option( self::VERSION_OPTION, 0 ) < self::SCHEMA_VERSION;
	}

	/**
	 * The CREATE TABLE statements for the current schema version.
	 *
	 * Written in the shape dbDelta() insists on: one field per line, two spaces
	 * before PRIMARY KEY, lowercase types, and KEY names always given.
	 *
	 * Money is always a signed bigint of minor units next to its own currency
	 * column: an amount without its currency is not an amount, and the place
	 * that finds out is the total.
	 *
	 * @return list<string>
	 */
	private function schema(): array {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();

		$suppliers         = Tables::name( Tables::SUPPLIERS );
		$supplier_products = Tables::name( Tables::SUPPLIER_PRODUCTS );
		$purchase_orders   = Tables::name( Tables::PURCHASE_ORDERS );
		$order_items       = Tables::name( Tables::ORDER_ITEMS );
		$receipts          = Tables::name( Tables::RECEIPTS );
		$receipt_items     = Tables::name( Tables::RECEIPT_ITEMS );
		$cost_history      = Tables::name( Tables::COST_HISTORY );
		$logs              = Tables::name( Tables::LOGS );

		return array(
			"CREATE TABLE {$suppliers} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				company_name varchar(191) NOT NULL,
				trade_name varchar(191) NOT NULL DEFAULT '',
				vat_number varchar(32) NOT NULL DEFAULT '',
				tax_code varchar(32) NOT NULL DEFAULT '',
				address varchar(191) NOT NULL DEFAULT '',
				postcode varchar(20) NOT NULL DEFAULT '',
				city varchar(100) NOT NULL DEFAULT '',
				state varchar(100) NOT NULL DEFAULT '',
				country char(2) NOT NULL DEFAULT '',
				order_email varchar(191) NOT NULL DEFAULT '',
				billing_email varchar(191) NOT NULL DEFAULT '',
				phone varchar(40) NOT NULL DEFAULT '',
				contact_name varchar(191) NOT NULL DEFAULT '',
				website varchar(191) NOT NULL DEFAULT '',
				payment_terms varchar(191) NOT NULL DEFAULT '',
				currency char(3) NOT NULL DEFAULT '',
				lead_time_days smallint unsigned NOT NULL DEFAULT 0,
				min_order_value_minor bigint NOT NULL DEFAULT 0,
				notes text DEFAULT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				created_by bigint unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY idx_status (status),
				KEY idx_vat (vat_number)
			) {$collate};",

			// One supplier has one price list line per article. variation_id is 0
			// and never NULL for simple products: NULL in a unique index does not
			// prevent duplicates, which is the one thing this index is for.
			"CREATE TABLE {$supplier_products} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				supplier_id bigint unsigned NOT NULL,
				product_id bigint unsigned NOT NULL,
				variation_id bigint unsigned NOT NULL DEFAULT 0,
				supplier_sku varchar(100) NOT NULL DEFAULT '',
				supplier_description varchar(191) NOT NULL DEFAULT '',
				currency char(3) NOT NULL,
				unit_cost_minor bigint NOT NULL DEFAULT 0,
				min_order_qty int unsigned NOT NULL DEFAULT 0,
				order_multiple int unsigned NOT NULL DEFAULT 1,
				pack_qty int unsigned NOT NULL DEFAULT 1,
				lead_time_days smallint unsigned NOT NULL DEFAULT 0,
				last_cost_minor bigint DEFAULT NULL,
				last_cost_at datetime DEFAULT NULL,
				is_preferred tinyint(1) NOT NULL DEFAULT 0,
				notes text DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uniq_supplier_item (supplier_id,product_id,variation_id),
				KEY idx_item_preferred (product_id,variation_id,is_preferred),
				KEY idx_supplier_sku (supplier_sku)
			) {$collate};",

			// po_number is unique in the database and not only in the generator:
			// two users saving in the same second must get two numbers, and the
			// loser must fail and retry rather than overwrite.
			"CREATE TABLE {$purchase_orders} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				po_number varchar(32) NOT NULL,
				supplier_id bigint unsigned NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'draft',
				currency char(3) NOT NULL,
				order_date date NOT NULL,
				expected_date date DEFAULT NULL,
				supplier_reference varchar(100) NOT NULL DEFAULT '',
				delivery_address text DEFAULT NULL,
				payment_terms varchar(191) NOT NULL DEFAULT '',
				internal_notes text DEFAULT NULL,
				supplier_notes text DEFAULT NULL,
				subtotal_minor bigint NOT NULL DEFAULT 0,
				tax_minor bigint NOT NULL DEFAULT 0,
				total_minor bigint NOT NULL DEFAULT 0,
				sent_at datetime DEFAULT NULL,
				sent_by bigint unsigned NOT NULL DEFAULT 0,
				lock_token char(32) DEFAULT NULL,
				lock_acquired_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				created_by bigint unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY uniq_po_number (po_number),
				KEY idx_supplier_status (supplier_id,status),
				KEY idx_status_expected (status,expected_date),
				KEY idx_order_date (order_date)
			) {$collate};",

			// qty_received is signed because a reversal pulls it back down. The
			// outstanding quantity is not stored at all: it is ordered minus
			// received, and a third number that can disagree with the other two
			// is only one more way to be wrong.
			"CREATE TABLE {$order_items} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				po_id bigint unsigned NOT NULL,
				product_id bigint unsigned NOT NULL DEFAULT 0,
				variation_id bigint unsigned NOT NULL DEFAULT 0,
				sku varchar(100) NOT NULL DEFAULT '',
				supplier_sku varchar(100) NOT NULL DEFAULT '',
				description varchar(191) NOT NULL DEFAULT '',
				qty_ordered int unsigned NOT NULL DEFAULT 0,
				qty_received int NOT NULL DEFAULT 0,
				unit_cost_minor bigint NOT NULL DEFAULT 0,
				discount_bp int NOT NULL DEFAULT 0,
				tax_rate_bp int NOT NULL DEFAULT 0,
				line_total_minor bigint NOT NULL DEFAULT 0,
				sort_order int unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY idx_po (po_id,sort_order),
				KEY idx_item (product_id,variation_id)
			) {$collate};",

			// idempotency_key is the protection against receiving twice, and it
			// lives here rather than in the interface because the interface cannot
			// see a reload, a back button, a retried POST or a second warehouse
			// phone.
			"CREATE TABLE {$receipts} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				po_id bigint unsigned NOT NULL,
				idempotency_key varchar(64) NOT NULL,
				reverses_receipt_id bigint unsigned DEFAULT NULL,
				reference varchar(100) NOT NULL DEFAULT '',
				notes text DEFAULT NULL,
				stock_applied tinyint(1) NOT NULL DEFAULT 0,
				received_at datetime NOT NULL,
				received_by bigint unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uniq_idempotency (idempotency_key),
				KEY idx_po (po_id,received_at),
				KEY idx_reverses (reverses_receipt_id)
			) {$collate};",

			"CREATE TABLE {$receipt_items} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				receipt_id bigint unsigned NOT NULL,
				po_item_id bigint unsigned NOT NULL,
				product_id bigint unsigned NOT NULL DEFAULT 0,
				variation_id bigint unsigned NOT NULL DEFAULT 0,
				qty int NOT NULL DEFAULT 0,
				actual_unit_cost_minor bigint DEFAULT NULL,
				currency char(3) NOT NULL,
				stock_before decimal(14,4) DEFAULT NULL,
				stock_after decimal(14,4) DEFAULT NULL,
				stock_skipped_reason varchar(40) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY idx_receipt (receipt_id),
				KEY idx_po_item (po_item_id)
			) {$collate};",

			// Written by the free plugin because recording a fact costs nothing
			// and the audit needs it; read by the pro plugin, which is where the
			// reports built on it live.
			"CREATE TABLE {$cost_history} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				supplier_id bigint unsigned NOT NULL DEFAULT 0,
				product_id bigint unsigned NOT NULL,
				variation_id bigint unsigned NOT NULL DEFAULT 0,
				currency char(3) NOT NULL,
				old_cost_minor bigint DEFAULT NULL,
				new_cost_minor bigint NOT NULL,
				source varchar(32) NOT NULL DEFAULT 'manual',
				po_id bigint unsigned DEFAULT NULL,
				receipt_id bigint unsigned DEFAULT NULL,
				changed_at datetime NOT NULL,
				changed_by bigint unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY idx_item_date (product_id,variation_id,changed_at),
				KEY idx_supplier_date (supplier_id,changed_at)
			) {$collate};",

			"CREATE TABLE {$logs} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				object_type varchar(32) NOT NULL,
				object_id varchar(64) NOT NULL DEFAULT '',
				action varchar(32) NOT NULL,
				old_value longtext DEFAULT NULL,
				new_value longtext DEFAULT NULL,
				message text DEFAULT NULL,
				user_id bigint unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY idx_object (object_type,object_id),
				KEY idx_date (created_at)
			) {$collate};",
		);
	}
}
