<?php
/**
 * Plugin Name:       OxySuppliers – Suppliers & Purchase Orders for WooCommerce
 * Plugin URI:        https://oxywp.com/plugins/oxysuppliers-for-woocommerce/
 * Description:       Know what to reorder, from which supplier and how much. Suppliers, purchase orders, goods receipts and stock updates, inside WooCommerce.
 * Version:           0.1.2
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Oxysoft
 * Author URI:        https://oxysoft.it/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       oxysuppliers-for-woocommerce
 * Domain Path:       /languages
 * WC requires at least: 9.0
 * WC tested up to:   11.0
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

const VERSION     = '0.1.2';
const PLUGIN_FILE = __FILE__;
const MIN_PHP     = '8.1';
const MIN_WC      = '9.0';

/**
 * The contract other plugins may build on.
 *
 * Separate from VERSION, and it moves for different reasons: this one only
 * changes when what a plugin standing on us can rely on changes. Read as a
 * caret constraint — the minor goes up when something is added, the major when
 * something already published stops meaning what it meant.
 *
 * What 1.0 promised:
 *
 * - `Engine\RequirementStrategy`, and the filter `oxysuppliers_requirement_strategy`
 *   that chooses which one is used;
 * - `Domain\RequirementContext`, the facts handed to it;
 * - the action `oxysuppliers_cost_changed`, fired when what an article costs
 *   changes;
 * - `Persistence\CostHistoryRepository`, the record of what was really paid.
 *
 * 1.1 adds `oxysuppliers_after_suggested_quantity`, which fires under the
 * quantity on the reordering screen. It exists because the paid add-on went
 * looking for somewhere to show its working and there was nowhere: a suggested
 * quantity nobody can explain is a suggested quantity nobody follows.
 *
 * 1.2 adds `oxysuppliers_register_tabs`, fired while the Purchasing page is
 * collecting its tabs, and makes `Admin\Screen` part of the contract. Same
 * story: an add-on with a screen that belongs next to these ones had nowhere to
 * put it, and the alternative is a second menu entry somewhere else.
 *
 * 1.3 adds `Persistence\ReceiptRepository::lines_for_item()`: every delivery of
 * one article, with what it cost and when — including the reversals, with
 * negative quantities, because a delivery that was undone has to cancel out
 * rather than disappear. The paid add-on wanted to work out what an article has
 * really cost and the only way in was reading somebody else's tables directly,
 * which is the sort of thing that breaks quietly on the next migration.
 *
 * 1.4 adds `Persistence\PurchaseOrderRepository::delivery_performance()`: one row
 * per received order with the date the supplier promised and the dates the goods
 * actually turned up on. The dates were already being written down — the promise
 * when the order is placed, the arrival when it is received — and the only thing
 * missing was a way to read them together. Cancelled and never-sent orders are
 * left out on purpose: they say nothing about a supplier.
 *
 * All minors, not majors: nothing already published changed meaning.
 *
 * The paid add-on checks this before doing anything at all. A newer major here
 * means it must refuse to run, rather than find out halfway through a request
 * which of its assumptions has stopped holding.
 */
const API_VERSION = '1.4';

/**
 * Absolute path to the plugin directory, with a trailing slash.
 *
 * @return string
 */
function plugin_dir(): string {
	return plugin_dir_path( __FILE__ );
}

/**
 * Public URL of the plugin directory, with a trailing slash.
 *
 * @return string
 */
function plugin_url(): string {
	return plugin_dir_url( __FILE__ );
}

/**
 * Minimal PSR-4 autoloader.
 *
 * The plugin ships no third-party runtime dependency yet, so Composer's
 * autoloader is a development tool only and never has to be bundled.
 *
 * @param string $class_name Fully qualified class name.
 * @return void
 */
function autoload( string $class_name ): void {
	$prefix = __NAMESPACE__ . '\\';

	if ( 0 !== strpos( $class_name, $prefix ) ) {
		return;
	}

	$relative = substr( $class_name, strlen( $prefix ) );
	$path     = plugin_dir() . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

	if ( is_readable( $path ) ) {
		require_once $path;
	}
}
spl_autoload_register( __NAMESPACE__ . '\\autoload' );

/**
 * Declare compatibility with WooCommerce features.
 *
 * HPOS is a hard requirement of this plugin's design: sales orders are only ever
 * read through the CRUD API and the lookup tables, never assumed to live in
 * wp_posts. The plugin's own documents live in its own tables.
 *
 * @return void
 */
function declare_woocommerce_compatibility(): void {
	if ( ! class_exists( FeaturesUtil::class ) ) {
		return;
	}

	FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
}
add_action( 'before_woocommerce_init', __NAMESPACE__ . '\\declare_woocommerce_compatibility' );

/**
 * Whether the environment satisfies the plugin's requirements.
 *
 * @return bool
 */
function requirements_met(): bool {
	return version_compare( PHP_VERSION, MIN_PHP, '>=' )
		&& class_exists( 'WooCommerce' )
		&& defined( 'WC_VERSION' )
		&& version_compare( (string) constant( 'WC_VERSION' ), MIN_WC, '>=' );
}

/**
 * Explain, once, why the plugin is not running.
 *
 * @return void
 */
function requirements_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$message = class_exists( 'WooCommerce' )
		? __( 'OxySuppliers needs PHP 8.1 or later and WooCommerce 9.0 or later.', 'oxysuppliers-for-woocommerce' )
		: __( 'OxySuppliers needs WooCommerce to be installed and active.', 'oxysuppliers-for-woocommerce' );

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html( $message )
	);
}

/**
 * Boot the plugin once WooCommerce is loaded.
 *
 * @return void
 */
function bootstrap(): void {
	if ( ! requirements_met() ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\requirements_notice' );

		return;
	}

	( new Plugin() )->boot();

	/**
	 * Fires when the plugin has verified its environment and is about to start.
	 *
	 * @since 0.1.0
	 */
	do_action( 'oxysuppliers_init' );
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap', 20 );

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
