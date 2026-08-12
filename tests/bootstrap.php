<?php
/**
 * Test bootstrap for both suites.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

/*
 * WP_PHPUNIT__TESTS_CONFIG is the switch, and not WP_PHPUNIT__DIR: the
 * wp-phpunit package sets that one from a Composer autoload file, so it is
 * always present and using it would drag WordPress into the unit suite, which
 * has to run without a database.
 */
$oxysuppliers_tests_config = getenv( 'WP_PHPUNIT__TESTS_CONFIG' );

if ( false === $oxysuppliers_tests_config || '' === $oxysuppliers_tests_config ) {
	return;
}

$oxysuppliers_wp_tests = getenv( 'WP_PHPUNIT__DIR' );

if ( false === $oxysuppliers_wp_tests || '' === $oxysuppliers_wp_tests ) {
	$oxysuppliers_wp_tests = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

require_once $oxysuppliers_wp_tests . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/oxysuppliers-for-woocommerce.php';
	}
);

require $oxysuppliers_wp_tests . '/includes/bootstrap.php';
