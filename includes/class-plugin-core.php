<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core initialization class (Singleton).
 */
class Plugin_Core {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->define_constants();
		$this->includes();
		$this->init_hooks();
	}

	private function define_constants(): void {
		if ( ! defined( 'ASS_VERSION' ) ) {
			define( 'ASS_VERSION', '1.0.0' );
		}
		if ( ! defined( 'ASS_PATH' ) ) {
			define( 'ASS_PATH', plugin_dir_path( dirname( __FILE__ ) ) );
		}
		if ( ! defined( 'ASS_URL' ) ) {
			define( 'ASS_URL', plugin_dir_url( dirname( __FILE__ ) ) );
		}
	}

	private function includes(): void {
		require_once ASS_PATH . 'includes/helper-functions.php';
		require_once ASS_PATH . 'includes/class-hooks.php';
		require_once ASS_PATH . 'includes/class-settings-manager.php';
		require_once ASS_PATH . 'includes/class-date-calculator.php';
		require_once ASS_PATH . 'includes/class-shipping-filter.php';
		require_once ASS_PATH . 'includes/class-checkout-handler.php';
		require_once ASS_PATH . 'includes/class-order-meta-handler.php';
		require_once ASS_PATH . 'includes/class-shortcode-handler.php';

		if ( is_admin() ) {
			require_once ASS_PATH . 'admin/class-admin-menu.php';
			require_once ASS_PATH . 'admin/class-shipping-rules-page.php';
			require_once ASS_PATH . 'admin/class-plugin-settings-page.php';
		}
	}

	private function init_hooks(): void {
		add_action( 'init', [ $this, 'register_shortcodes' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
		
		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		}

		// Initialize components
		Hooks::instance(); // Handles shipping filtering and checkout validation
		Settings_Manager::instance();
		Date_Calculator::instance();
		Checkout_Handler::instance();
		Order_Meta_Handler::instance();
		Shortcode_Handler::instance();

		if ( is_admin() ) {
			Admin_Menu::instance();
			Shipping_Rules_Page::instance();
			Plugin_Settings_Page::instance();
		}
	}

	public function register_shortcodes(): void {
		add_shortcode( 'advanced_shipping_info', [ Shortcode_Handler::instance(), 'render_shortcode' ] );
	}

	public function enqueue_frontend_assets(): void {
		wp_enqueue_style( 'ass-frontend-styles', ASS_URL . 'assets/css/frontend-styles.css', [], ASS_VERSION );
		wp_enqueue_script( 'ass-checkout-date-selector', ASS_URL . 'assets/js/checkout-date-selector.js', [ 'jquery', 'wc-checkout' ], ASS_VERSION, true );
		wp_enqueue_script( 'ass-checkout-update', ASS_URL . 'assets/js/checkout-update.js', [ 'jquery', 'wc-checkout' ], ASS_VERSION, true );
	}

	public function enqueue_admin_assets(): void {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		
		// Enqueue admin styles on both plugin pages
		if ( 'advanced-shipping-settings' === $page || 'advanced-shipping-settings-config' === $page ) {
			wp_enqueue_style( 'ass-admin-styles', ASS_URL . 'admin/css/admin-styles.css', [ 'woocommerce_admin_styles' ], ASS_VERSION );
		}
		
		// Enqueue sortable and JS on rules page
		if ( 'advanced-shipping-settings' === $page ) {
			wp_enqueue_script( 'sortable', 'https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js', [], '1.15.0', true );
			wp_enqueue_script( 'ass-shipping-rules-admin', ASS_URL . 'admin/js/shipping-rules-admin.js', [ 'sortable', 'jquery' ], ASS_VERSION, true );
		}
		
		// Enqueue JS on settings page for holiday repeater
		if ( 'advanced-shipping-settings-config' === $page ) {
			wp_enqueue_script( 'ass-settings-admin', ASS_URL . 'admin/js/shipping-rules-admin.js', [ 'jquery' ], ASS_VERSION, true );
		}
	}
}

