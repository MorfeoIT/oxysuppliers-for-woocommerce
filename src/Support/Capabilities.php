<?php
/**
 * The plugin's custom capabilities.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Support;

/**
 * Who may do what.
 *
 * Purchase prices are commercially sensitive and receiving goods moves stock:
 * neither follows from being able to process sales orders. So the capabilities
 * are separate from `manage_woocommerce`, and no screen ever settles for a role
 * check.
 *
 * See docs/04_SICUREZZA.md.
 */
final class Capabilities {

	/**
	 * Raised whenever the capability set changes.
	 *
	 * Granting only on activation is not enough: an update that adds a
	 * capability never runs the activation hook again, so the new screen would
	 * be invisible to everyone until the plugin was manually reactivated.
	 */
	public const VERSION = 1;

	/**
	 * Option holding the granted version.
	 */
	public const VERSION_OPTION = 'oxysuppliers_capabilities_version';

	public const MANAGE_SUPPLIERS = 'oxysuppliers_manage_suppliers';
	public const VIEW_ORDERS      = 'oxysuppliers_view_purchase_orders';
	public const CREATE_ORDERS    = 'oxysuppliers_create_purchase_orders';
	public const SEND_ORDERS      = 'oxysuppliers_send_purchase_orders';
	public const RECEIVE_ORDERS   = 'oxysuppliers_receive_purchase_orders';
	public const MANAGE_SETTINGS  = 'oxysuppliers_manage_settings';
	public const VIEW_REPORTS     = 'oxysuppliers_view_reports';

	/**
	 * Every capability the plugin defines.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::MANAGE_SUPPLIERS,
			self::VIEW_ORDERS,
			self::CREATE_ORDERS,
			self::SEND_ORDERS,
			self::RECEIVE_ORDERS,
			self::MANAGE_SETTINGS,
			self::VIEW_REPORTS,
		);
	}

	/**
	 * What a shop manager gets by default.
	 *
	 * Everything except the settings: a shop manager runs the buying, an
	 * administrator decides whether receiving goods is allowed to move stock.
	 *
	 * @return list<string>
	 */
	public static function shop_manager_defaults(): array {
		return array(
			self::MANAGE_SUPPLIERS,
			self::VIEW_ORDERS,
			self::CREATE_ORDERS,
			self::SEND_ORDERS,
			self::RECEIVE_ORDERS,
			self::VIEW_REPORTS,
		);
	}

	/**
	 * Grant the capabilities if this build's set has not been granted yet.
	 *
	 * Idempotent and cheap: one autoloaded option read, and nothing else on
	 * every subsequent request.
	 *
	 * @return bool Whether anything was granted.
	 */
	public static function ensure_granted(): bool {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::VERSION ) {
			return false;
		}

		self::grant();
		update_option( self::VERSION_OPTION, self::VERSION, true );

		return true;
	}

	/**
	 * Grant the capabilities.
	 *
	 * @return void
	 */
	public static function grant(): void {
		$administrator = get_role( 'administrator' );

		if ( null !== $administrator ) {
			foreach ( self::all() as $capability ) {
				$administrator->add_cap( $capability );
			}
		}

		$shop_manager = get_role( 'shop_manager' );

		if ( null !== $shop_manager ) {
			foreach ( self::shop_manager_defaults() as $capability ) {
				$shop_manager->add_cap( $capability );
			}
		}
	}

	/**
	 * Remove the capabilities from every role.
	 *
	 * Called on uninstall. Deactivation leaves them alone: a plugin that is
	 * briefly disabled should not silently reshuffle who can do what.
	 *
	 * @return void
	 */
	public static function revoke(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::all() as $capability ) {
				$role->remove_cap( $capability );
			}
		}
	}
}
