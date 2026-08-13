<?php
/**
 * What happens when the plugin is removed.
 *
 * Nothing, unless an administrator has explicitly asked for the data to go.
 * A purchase order is a document: nobody expects one to disappear because a
 * plugin was deleted.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

use Oxysoft\OxySuppliers\Persistence\Migrator;
use Oxysoft\OxySuppliers\Persistence\Tables;
use Oxysoft\OxySuppliers\Support\Capabilities;
use Oxysoft\OxySuppliers\Support\Settings;

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The plugin itself is not loaded during uninstall, so the few classes this
// file needs are pulled in by hand.
require_once __DIR__ . '/src/Persistence/Tables.php';
require_once __DIR__ . '/src/Persistence/Migrator.php';
require_once __DIR__ . '/src/Support/Capabilities.php';
require_once __DIR__ . '/src/Support/Settings.php';

/**
 * Remove one site's data.
 *
 * @return void
 */
function oxysuppliers_uninstall_site(): void {
	global $wpdb;

	if ( true !== Settings::get( 'delete_data_on_uninstall' ) ) {
		return;
	}

	foreach ( Tables::all() as $table ) {
		$name = Tables::name( $table );

		// A table name built from a constant, in the one place where dropping a
		// table is the job.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table, name from a constant.
		$wpdb->query( "DROP TABLE IF EXISTS {$name}" );
	}

	Capabilities::revoke();

	delete_option( Settings::OPTION );
	delete_option( Capabilities::VERSION_OPTION );
	delete_option( Migrator::VERSION_OPTION );
}

if ( is_multisite() ) {
	$oxysuppliers_sites = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $oxysuppliers_sites as $oxysuppliers_site_id ) {
		switch_to_blog( (int) $oxysuppliers_site_id );
		oxysuppliers_uninstall_site();
		restore_current_blog();
	}
} else {
	oxysuppliers_uninstall_site();
}
