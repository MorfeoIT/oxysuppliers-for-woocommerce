<?php
// What removing the plugin does to the data, asked both ways.
//
//   wp eval-file verify-uninstall.php
//
// The default is that nothing goes: a purchase order is a document, and nobody
// expects a document to disappear because a plugin was deleted. That default is
// worth proving rather than believing, because the failure is silent and final
// — you find out you were wrong when somebody has already lost a year of
// orders.
//
// This runs uninstall.php exactly as WordPress runs it, which is the only way
// to test the guard at the top of it. The destructive half is done on a copy of
// the tables' contents, and everything is put back at the end.

use Oxysoft\OxySuppliers\Persistence\Migrator;
use Oxysoft\OxySuppliers\Persistence\Tables;
use Oxysoft\OxySuppliers\Support\Capabilities;
use Oxysoft\OxySuppliers\Support\Settings;

$GLOBALS['oxs_passed'] = 0;
$GLOBALS['oxs_failed'] = 0;

function oxs_check( $label, $condition, $detail = '' ) {
	if ( $condition ) {
		++$GLOBALS['oxs_passed'];
		echo "  ok   {$label}\n";

		return;
	}

	++$GLOBALS['oxs_failed'];
	echo "  FAIL {$label}" . ( '' === $detail ? '' : " ({$detail})" ) . "\n";
}

/**
 * The administrator role, read again from the database.
 *
 * WordPress builds the roles once per process and keeps them; uninstall.php
 * runs in another one. Asking get_role() here without this answers with what
 * was true when this script started, which is exactly the question not being
 * asked.
 */
function oxs_admin_role() {
	global $wp_roles;

	wp_cache_delete( 'user_roles', 'options' );

	$wp_roles = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Rebuilding the roles is the point.

	wp_roles();

	return get_role( 'administrator' );
}

/**
 * How many of our tables the database still has.
 */
function oxs_tables_present() {
	global $wpdb;

	$present = 0;

	foreach ( Tables::all() as $table ) {
		$name = Tables::name( $table );

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) === $name ) {
			++$present;
		}
	}

	return $present;
}

/**
 * Run uninstall.php the way WordPress does.
 *
 * In a separate process, because the constant can only be defined once and the
 * file may only be included once.
 */
function oxs_run_uninstall() {
	$plugin = dirname( __DIR__ );
	$root   = ABSPATH;

	$code = '<?php define( "WP_UNINSTALL_PLUGIN", "oxysuppliers-for-woocommerce/oxysuppliers-for-woocommerce.php" );'
		. ' define( "WP_USE_THEMES", false );'
		. ' require "' . $root . 'wp-load.php";'
		. ' require "' . $plugin . '/uninstall.php";';

	$file = tempnam( sys_get_temp_dir(), 'oxs' ) . '.php';
	file_put_contents( $file, $code );

	exec( escapeshellcmd( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $output );

	wp_delete_file( $file );

	return implode( "\n", $output );
}

global $wpdb;

$total = count( Tables::all() );

echo "== prima: il plugin e' installato per intero ==\n";

( new Migrator() )->migrate();
Capabilities::grant();

oxs_check( 'ci sono tutte le tabelle', $total === oxs_tables_present(), oxs_tables_present() . " su {$total}" );
oxs_check( 'e la versione dello schema e\' scritta', 0 < (int) get_option( Migrator::VERSION_OPTION ) );

$admin_role = oxs_admin_role();

oxs_check( 'l\'amministratore ha i nostri permessi', $admin_role->has_cap( Capabilities::MANAGE_SUPPLIERS ) );

// Qualcosa da perdere: se le tabelle fossero vuote, non perderle non
// significherebbe niente.
$wpdb->insert(
	Tables::name( Tables::SUPPLIERS ),
	array(
		'company_name' => 'Fornitore Da Non Perdere',
		'currency'     => 'EUR',
		'status'       => 'active',
		'created_at'   => current_time( 'mysql', true ),
		'updated_at'   => current_time( 'mysql', true ),
	)
);

$kept_id = (int) $wpdb->insert_id;

oxs_check( 'e c\'e' . "'" . ' un fornitore dentro', 0 < $kept_id );

echo "\n== con l'impostazione com'e' di serie, non si perde niente ==\n";

oxs_check( 'di serie la cancellazione e\' spenta', true !== Settings::get( 'delete_data_on_uninstall' ) );

// Le impostazioni si salvano per davvero: un'opzione mai scritta non si perde
// nemmeno volendo, e non perderla non dimostrerebbe niente.
Settings::update( array( 'delete_data_on_uninstall' => false ) );

oxs_check( 'e ci sono impostazioni salvate da perdere', false !== get_option( Settings::OPTION ) );

$noise = oxs_run_uninstall();

oxs_check( 'la disinstallazione non stampa errori', '' === trim( $noise ), $noise );
oxs_check( 'LE TABELLE CI SONO ANCORA', $total === oxs_tables_present(), oxs_tables_present() . " su {$total}" );

$still = $wpdb->get_var( $wpdb->prepare( 'SELECT company_name FROM ' . Tables::name( Tables::SUPPLIERS ) . ' WHERE id = %d', $kept_id ) );

oxs_check( 'e il fornitore e\' ancora al suo posto', 'Fornitore Da Non Perdere' === $still, (string) $still );
oxs_check( 'anche le impostazioni restano', false !== get_option( Settings::OPTION ) );

echo "\n== ma se qualcuno lo chiede davvero, si perde tutto ==\n";

Settings::update( array( 'delete_data_on_uninstall' => true ) );

oxs_check( 'l\'impostazione e\' accesa', true === Settings::get( 'delete_data_on_uninstall' ) );

$noise = oxs_run_uninstall();

oxs_check( 'la disinstallazione non stampa errori', '' === trim( $noise ), $noise );
oxs_check( 'LE TABELLE NON CI SONO PIU\'', 0 === oxs_tables_present(), oxs_tables_present() . ' rimaste' );

wp_cache_flush();

oxs_check( 'le impostazioni sono sparite', false === get_option( Settings::OPTION ) );
oxs_check( 'e anche la versione dello schema', false === get_option( Migrator::VERSION_OPTION ) );

$admin_role = oxs_admin_role();

oxs_check( 'i permessi sono stati tolti', ! $admin_role->has_cap( Capabilities::MANAGE_SUPPLIERS ) );

echo "\n== e il banco torna come prima ==\n";

( new Migrator() )->migrate();
Capabilities::grant();

oxs_check( 'tabelle ricostruite', $total === oxs_tables_present(), oxs_tables_present() . " su {$total}" );

$admin_role = oxs_admin_role();

oxs_check( 'permessi rimessi', $admin_role->has_cap( Capabilities::MANAGE_SUPPLIERS ) );

echo "\n== ===============================\n";
printf( "== superati: %d   falliti: %d\n", $GLOBALS['oxs_passed'], $GLOBALS['oxs_failed'] );
