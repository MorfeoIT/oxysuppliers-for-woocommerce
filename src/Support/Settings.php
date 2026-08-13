<?php
/**
 * The plugin's settings.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Support;

/**
 * One option holding every setting, with the defaults in one place.
 *
 * A single autoloaded option rather than one per setting: they are read
 * together on the screens that need them, and a plugin that adds twenty
 * autoloaded options to a site is a plugin that made every page slower.
 */
final class Settings {

	/**
	 * Option name.
	 */
	public const OPTION = 'oxysuppliers_settings';

	/**
	 * The defaults, which are also the list of settings that exist.
	 *
	 * @var array<string,mixed>
	 */
	private const DEFAULTS = array(
		// Receiving goods moves stock unless an administrator says otherwise.
		// This is the behaviour people expect; the log records every movement
		// either way.
		'update_stock_on_receipt'       => true,

		// Purchase order numbering.
		'po_number_prefix'              => 'PO-',

		// How full to fill an article back up, as a multiple of the low stock
		// threshold WooCommerce already knows. Filling exactly to the threshold
		// would put the shop straight back on it, so the default buys a second
		// threshold's worth of breathing room.
		'requirement_target_multiplier' => 2,

		// Uninstalling leaves the data alone. Purchase orders are documents:
		// nobody expects them to disappear because a plugin was removed.
		'delete_data_on_uninstall'      => false,
	);

	/**
	 * Every setting, defaults included.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::DEFAULTS, array_intersect_key( $stored, self::DEFAULTS ) );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$settings = self::all();

		return $settings[ $key ] ?? null;
	}

	/**
	 * Write some settings, ignoring anything that is not a setting.
	 *
	 * @param array<string,mixed> $values Values to write.
	 * @return void
	 */
	public static function update( array $values ): void {
		$settings = array_merge( self::all(), array_intersect_key( $values, self::DEFAULTS ) );

		update_option( self::OPTION, $settings, true );
	}
}
