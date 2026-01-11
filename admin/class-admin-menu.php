<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register admin menu and submenu pages.
 */
class Admin_Menu {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu_pages' ] );
	}

	/**
	 * Register the menu and submenus.
	 */
	public function register_menu_pages(): void {
		add_menu_page(
			__( 'Advanced Shipping Settings', 'advanced-shipping-settings' ),
			__( 'Shipping Settings', 'advanced-shipping-settings' ),
			'manage_woocommerce',
			'advanced-shipping-settings',
			[ Shipping_Rules_Page::instance(), 'render' ],
			'dashicons-calendar-alt',
			56
		);

		// First submenu replaces the parent menu link.
		add_submenu_page(
			'advanced-shipping-settings',
			__( 'Shipping Rules', 'advanced-shipping-settings' ),
			__( 'Shipping Rules', 'advanced-shipping-settings' ),
			'manage_woocommerce',
			'advanced-shipping-settings',
			[ Shipping_Rules_Page::instance(), 'render' ]
		);

		add_submenu_page(
			'advanced-shipping-settings',
			__( 'Plugin Settings', 'advanced-shipping-settings' ),
			__( 'Plugin Settings', 'advanced-shipping-settings' ),
			'manage_woocommerce',
			'advanced-shipping-settings-config',
			[ Plugin_Settings_Page::instance(), 'render' ]
		);

		add_submenu_page(
			'advanced-shipping-settings',
			__( 'Pickup Locations', 'advanced-shipping-settings' ),
			__( 'Pickup Locations', 'advanced-shipping-settings' ),
			'manage_woocommerce',
			'advanced-shipping-settings-pickup',
			[ Pickup_Locations_Page::instance(), 'render' ]
		);
	}
}

