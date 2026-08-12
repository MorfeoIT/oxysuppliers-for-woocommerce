<?php
/**
 * What a tab has to be able to do.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Admin;

/**
 * One tab of the Purchasing page.
 *
 * Small on purpose: the menu needs to know what a screen is called, who may
 * open it, and how to draw it. Everything else is the screen's own business.
 */
interface Screen {

	/**
	 * Slug used in the address.
	 *
	 * @return string
	 */
	public function slug(): string;

	/**
	 * Name shown on the tab.
	 *
	 * @return string
	 */
	public function title(): string;

	/**
	 * Capability required to open it.
	 *
	 * @return string
	 */
	public function capability(): string;

	/**
	 * Hook up whatever the screen needs outside rendering, such as form
	 * handlers.
	 *
	 * @return void
	 */
	public function register(): void;

	/**
	 * Draw the screen.
	 *
	 * @return void
	 */
	public function render(): void;
}
