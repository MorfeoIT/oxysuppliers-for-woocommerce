<?php
/**
 * Constants PHPStan needs to see.
 *
 * The plugin's own constants are declared inside its main file, which the
 * analyser reads; these are WordPress's, which it does not.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

// The real thing, installed by Composer for the integration suite, so that the
// upgrade.php dbDelta() lives in can actually be found during analysis.
define( 'ABSPATH', dirname( __DIR__ ) . '/wordpress/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'WP_UNINSTALL_PLUGIN', true );
