<?php
/**
 * Plugin Name:       OxySuppliers – Suppliers & Purchase Orders for WooCommerce
 * Plugin URI:        https://oxywp.com/plugins/oxysuppliers-for-woocommerce/
 * Description:       Know what to reorder, from which supplier and how much. Suppliers, purchase orders, goods receipts and stock updates, inside WooCommerce.
 * Version:           0.1.0
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

const VERSION     = '0.1.0';
const PLUGIN_FILE = __FILE__;
const MIN_PHP     = '8.1';
const MIN_WC      = '9.0';

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
