<?php
/**
 * Wiring.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers;

use Oxysoft\OxySuppliers\Admin\Menu;
use Oxysoft\OxySuppliers\Admin\SuppliersScreen;
use Oxysoft\OxySuppliers\Domain\SupplierValidator;
use Oxysoft\OxySuppliers\Persistence\Migrator;
use Oxysoft\OxySuppliers\Persistence\SupplierRepository;
use Oxysoft\OxySuppliers\Service\AuditLogger;
use Oxysoft\OxySuppliers\Support\Capabilities;

/**
 * Builds the object graph and registers the hooks.
 *
 * Deliberately plain: a container would add a dependency to solve a problem
 * this plugin does not have.
 */
final class Plugin {

	/**
	 * Start the plugin.
	 *
	 * @return void
	 */
	public function boot(): void {
		$migrator = new Migrator();

		// Not only on activation. A network activation never runs the activation
		// hook for the individual sites, and an update that adds a table has no
		// activation to hang off either.
		if ( $migrator->needs_migration() ) {
			$migrator->migrate();
		}

		Capabilities::ensure_granted();

		if ( is_admin() ) {
			$menu = new Menu();

			$menu->add_tab(
				new SuppliersScreen(
					new SupplierRepository(),
					new SupplierValidator(),
					new AuditLogger()
				)
			);

			$menu->register();
		}
	}

	/**
	 * Run on activation, before anything else exists.
	 *
	 * @return void
	 */
	public static function activate(): void {
		( new Migrator() )->migrate();

		Capabilities::ensure_granted();
	}
}
