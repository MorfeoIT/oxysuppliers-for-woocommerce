<?php
/**
 * The admin menu and its tabs.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Admin;

use Oxysoft\OxySuppliers\Support\Capabilities;

use function Oxysoft\OxySuppliers\plugin_url;

use const Oxysoft\OxySuppliers\VERSION;

/**
 * One entry under WooCommerce, with tabs underneath it.
 *
 * The specification asks for WooCommerce → Purchasing → six pages. The admin
 * menu has only two levels, so the second level is tabs on one page: six
 * entries under WooCommerce would push everything else off the screen, and
 * WooCommerce settles the same question the same way.
 */
final class Menu {

	/**
	 * Page slug, and the prefix of every screen this plugin owns.
	 */
	public const SLUG = 'oxysuppliers';

	/**
	 * The tabs, in the order they are shown.
	 *
	 * Each sprint adds one. A tab appears only for a user who holds its
	 * capability, so this array is also the answer to "what can I see".
	 *
	 * @var array<string,Screen>
	 */
	private array $tabs = array();

	/**
	 * Add a tab.
	 *
	 * @param Screen $screen The screen to add.
	 * @return void
	 */
	public function add_tab( Screen $screen ): void {
		$this->tabs[ $screen->slug() ] = $screen;
	}

	/**
	 * Register the menu.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		foreach ( $this->tabs as $screen ) {
			$screen->register();
		}
	}

	/**
	 * Add the page under WooCommerce.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		$capability = $this->menu_capability();

		if ( null === $capability ) {
			return;
		}

		add_submenu_page(
			'woocommerce',
			__( 'Purchasing', 'oxysuppliers-for-woocommerce' ),
			__( 'Purchasing', 'oxysuppliers-for-woocommerce' ),
			$capability,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Load the stylesheet on this plugin's screens and nowhere else.
	 *
	 * @param string $hook Current admin screen.
	 * @return void
	 */
	public function enqueue( $hook ): void {
		if ( ! is_string( $hook ) || false === strpos( $hook, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'oxysuppliers-admin',
			plugin_url() . 'assets/css/admin.css',
			array(),
			VERSION
		);
	}

	/**
	 * Draw the page: the tab bar, then whichever tab is open.
	 *
	 * @return void
	 */
	public function render(): void {
		$visible = $this->visible_tabs();

		if ( array() === $visible ) {
			wp_die( esc_html__( 'You are not allowed to see this page.', 'oxysuppliers-for-woocommerce' ), 403 );
		}

		// A tab name only ever selects one of our own screens, and one the
		// current user is allowed to see; nothing from the request reaches a
		// query or the output.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation between screens.
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$current   = isset( $visible[ $requested ] ) ? $requested : (string) array_key_first( $visible );

		echo '<div class="wrap oxysuppliers-wrap">';

		if ( count( $visible ) > 1 ) {
			echo '<nav class="nav-tab-wrapper woo-nav-tab-wrapper">';

			foreach ( $visible as $slug => $screen ) {
				printf(
					'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
					esc_url( self::url( $slug ) ),
					$slug === $current ? ' nav-tab-active' : '',
					esc_html( $screen->title() )
				);
			}

			echo '</nav>';
		}

		$visible[ $current ]->render();

		echo '</div>';
	}

	/**
	 * Address of a tab.
	 *
	 * @param string              $tab       Tab slug.
	 * @param array<string,mixed> $arguments Extra query arguments.
	 * @return string
	 */
	public static function url( string $tab, array $arguments = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::SLUG,
					'tab'  => $tab,
				),
				$arguments
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The tabs this user may see.
	 *
	 * @return array<string,Screen>
	 */
	private function visible_tabs(): array {
		return array_filter(
			$this->tabs,
			static fn ( Screen $screen ): bool => current_user_can( $screen->capability() )
		);
	}

	/**
	 * The capability the menu entry is registered with.
	 *
	 * The first tab this user can open, so that someone who may manage
	 * suppliers but not see purchase orders still finds the menu. Null when
	 * they may see nothing, and then there is no menu at all.
	 *
	 * @return string|null
	 */
	private function menu_capability(): ?string {
		foreach ( $this->tabs as $screen ) {
			if ( current_user_can( $screen->capability() ) ) {
				return $screen->capability();
			}
		}

		return null;
	}

	/**
	 * The capability that gates the whole page, for callers outside the menu.
	 *
	 * @return string
	 */
	public static function fallback_capability(): string {
		return Capabilities::VIEW_ORDERS;
	}
}
