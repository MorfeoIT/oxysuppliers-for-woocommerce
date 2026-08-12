<?php
/**
 * Who gets what, inside a real WordPress.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Tests\Integration;

use Oxysoft\OxySuppliers\Support\Capabilities;
use WP_UnitTestCase;

/**
 * Capabilities are only real once roles have them, which is not something a
 * unit test can see.
 */
final class CapabilitiesTest extends WP_UnitTestCase {

	/**
	 * Start from a site that has never granted them.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Capabilities::revoke();
		delete_option( Capabilities::VERSION_OPTION );
	}

	/**
	 * Put the roles back for whatever runs next.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		Capabilities::revoke();

		parent::tear_down();
	}

	/**
	 * An administrator gets everything.
	 *
	 * @return void
	 */
	public function test_an_administrator_gets_every_capability(): void {
		Capabilities::ensure_granted();

		$administrator = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );

		foreach ( Capabilities::all() as $capability ) {
			$this->assertTrue( $administrator->has_cap( $capability ), "Administrator is missing {$capability}" );
		}
	}

	/**
	 * A shop manager runs the buying but does not change the settings.
	 *
	 * @return void
	 */
	public function test_a_shop_manager_gets_the_buying_capabilities_but_not_the_settings(): void {
		if ( null === get_role( 'shop_manager' ) ) {
			add_role( 'shop_manager', 'Shop manager', array( 'read' => true ) );
		}

		Capabilities::ensure_granted();

		$manager = self::factory()->user->create_and_get( array( 'role' => 'shop_manager' ) );

		$this->assertTrue( $manager->has_cap( Capabilities::MANAGE_SUPPLIERS ) );
		$this->assertTrue( $manager->has_cap( Capabilities::RECEIVE_ORDERS ) );
		$this->assertFalse( $manager->has_cap( Capabilities::MANAGE_SETTINGS ) );
	}

	/**
	 * Everybody else gets nothing at all.
	 *
	 * @return void
	 */
	public function test_an_editor_gets_nothing(): void {
		Capabilities::ensure_granted();

		$editor = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );

		foreach ( Capabilities::all() as $capability ) {
			$this->assertFalse( $editor->has_cap( $capability ), "An editor should not have {$capability}" );
		}
	}

	/**
	 * Granting is cheap and happens once.
	 *
	 * @return void
	 */
	public function test_granting_is_idempotent(): void {
		$this->assertTrue( Capabilities::ensure_granted() );
		$this->assertFalse( Capabilities::ensure_granted() );
	}

	/**
	 * A new capability in a later version reaches the roles without anybody
	 * reactivating the plugin.
	 *
	 * @return void
	 */
	public function test_a_version_bump_grants_again(): void {
		Capabilities::ensure_granted();

		// What an update looks like from here: the build's version is ahead of
		// what was granted.
		update_option( Capabilities::VERSION_OPTION, Capabilities::VERSION - 1 );

		$this->assertTrue( Capabilities::ensure_granted() );
	}

	/**
	 * Removing them takes them off every role, including custom ones.
	 *
	 * @return void
	 */
	public function test_revoking_clears_every_role(): void {
		Capabilities::ensure_granted();
		Capabilities::revoke();

		$administrator = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );

		foreach ( Capabilities::all() as $capability ) {
			$this->assertFalse( $administrator->has_cap( $capability ) );
		}
	}
}
