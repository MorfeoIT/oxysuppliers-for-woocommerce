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

		// What goes to the supplier. The placeholders are replaced when the
		// message is prepared, and whoever sends it sees the result before
		// pressing anything.
		'email_subject'                 => '',
		'email_body'                    => '',
		'email_cc'                      => '',
		'email_bcc'                     => '',

		// Uninstalling leaves the data alone. Purchase orders are documents:
		// nobody expects them to disappear because a plugin was removed.
		'delete_data_on_uninstall'      => false,
	);

	/**
	 * The default subject line, when the shop has not written its own.
	 *
	 * A default rather than a stored value, so that a shop that has never
	 * touched the settings still gets it in its own language.
	 *
	 * @return string
	 */
	public static function default_email_subject(): string {
		$stored = trim( (string) self::get( 'email_subject' ) );

		/* translators: 1: purchase order number, 2: the shop's name. */
		return '' !== $stored ? $stored : __( 'Purchase order {number} from {company}', 'oxysuppliers-for-woocommerce' );
	}

	/**
	 * The default message, when the shop has not written its own.
	 *
	 * @return string
	 */
	public static function default_email_body(): string {
		$stored = trim( (string) self::get( 'email_body' ) );

		if ( '' !== $stored ) {
			return $stored;
		}

		return __(
			"Hello,\n\nplease find our purchase order {number} attached.\n\nWe would expect delivery by {expected}.\n\nThank you,\n{company}",
			'oxysuppliers-for-woocommerce'
		);
	}

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
