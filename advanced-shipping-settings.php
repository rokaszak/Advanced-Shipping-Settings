<?php
/**
 * Plugin Name: Advanced Shipping Settings
 * Description: Conditionally select which product categories are available in which shipping method (ASAP or BY DATE).
 * Version: 1.2.7
 * Author: Rokas Zakarauskas
 * Text Domain: advanced-shipping-settings
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declare HPOS compatibility.
 */
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * Initialize the plugin after WooCommerce is loaded.
 */
add_action( 'plugins_loaded', 'ass_initialize_plugin', 20 );

function ass_initialize_plugin() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function() {
			?>
			<div class="error">
				<p><?php esc_html_e( 'Advanced Shipping Settings requires WooCommerce to be installed and active.', 'advanced-shipping-settings' ); ?></p>
			</div>
			<?php
		} );
		return;
	}

	// Load the core plugin class.
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-plugin-core.php';
	\ASS\Plugin_Core::instance();
}

