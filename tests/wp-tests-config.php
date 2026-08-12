<?php
/**
 * Configuration for the WordPress test library.
 *
 * Everything comes from the environment so that the same file works in CI and
 * on a machine. Point this at a throwaway database and never at a site: the
 * test library empties and recreates every table on every run.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/wordpress/' );

define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ?: 'wordpress_test' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) ?: '' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ?: '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableNotSnakeCase, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Required name, set by WordPress itself.

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'OxySuppliers test bench' );

define( 'WP_PHP_BINARY', 'php' );
define( 'WP_DEBUG', true );
define( 'WP_DEFAULT_THEME', 'default' );
