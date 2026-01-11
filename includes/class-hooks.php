<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles core WooCommerce hooks for shipping filtering and checkout validation.
 * Modeled after WPFactory's alg-wc-cs-hooks.php approach.
 */
class Hooks {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Core shipping method filtering - PHP_INT_MAX priority like WPFactory
		add_filter( 'woocommerce_package_rates', [ $this, 'filter_shipping_methods' ], PHP_INT_MAX, 2 );

		// Register custom pickup shipping methods
		add_filter( 'woocommerce_shipping_methods', [ $this, 'register_pickup_shipping_methods' ] );

		// Prepend images to shipping method labels
		add_filter( 'woocommerce_cart_shipping_method_full_label', [ $this, 'add_images_to_labels' ], 10, 2 );

		// Checkout validation to prevent stale/cached shipping selections
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'checkout_validation' ], PHP_INT_MAX, 2 );

		// JS for dynamic checkout updates when relevant fields change
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_scripts' ] );

		// Invalidate shipping cache when needed
		add_action( 'init', [ $this, 'maybe_invalidate_stored_shipping_rates' ] );
	}

	/**
	 * Filter available shipping methods based on cart categories and rules.
	 * Uses PHP_INT_MAX priority to run after all other filters.
	 */
	public function filter_shipping_methods( array $rates, array $package ): array {
		$shipping_filter = Shipping_Filter::instance();
		return $shipping_filter->filter_shipping_methods( $rates, $package );
	}

	/**
	 * Validate chosen shipping method during checkout to prevent stale selections.
	 */
	public function checkout_validation( array $data, $errors ): void {
		if ( ! isset( $data['shipping_method'] ) ) {
			return;
		}

		$shipping_methods = wc_clean( is_array( $data['shipping_method'] ) ? $data['shipping_method'] : [ $data['shipping_method'] ] );

		foreach ( WC()->shipping()->get_packages() as $i => $package ) {
			if ( ! isset( $shipping_methods[ $i ] ) ) {
				continue;
			}

			$shipping_method = $shipping_methods[ $i ];

			if ( empty( $package['rates'][ $shipping_method ] ) ) {
				continue;
			}

			$rate = $package['rates'][ $shipping_method ];

			// Re-validate the shipping method against current cart
			$shipping_filter = Shipping_Filter::instance();
			$validation = $shipping_filter->validate_shipping_method( $rate, $package );

			if ( ! $validation['res'] ) {
				$message = apply_filters( 'ass_checkout_validation_message',
					__( '%shipping_method% is not available for your current cart contents.', 'advanced-shipping-settings' ),
					$rate, $validation
				);
				$message = str_replace( '%shipping_method%', $rate->get_label(), $message );
				wc_add_notice( $message, 'error' );
			}
		}
	}

	/**
	 * Enqueue checkout update JavaScript.
	 */
	public function enqueue_checkout_scripts(): void {
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			// For now, no specific selectors needed as WooCommerce handles most updates
			// But we can add this later if we need dynamic category-based updates
			$selectors = apply_filters( 'ass_checkout_update_selectors', [] );

			if ( ! empty( $selectors ) ) {
				wp_enqueue_script(
					'ass-checkout-update',
					plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/checkout-update.js',
					[ 'jquery', 'wc-checkout' ],
					'1.0.0',
					true
				);

				wp_localize_script( 'ass-checkout-update', 'ass_checkout_update', [
					'selectors' => 'input[name="' . implode( '"], input[name="', $selectors ) . '"]',
				] );
			}
		}
	}

	/**
	 * Register custom pickup shipping methods.
	 */
	public function register_pickup_shipping_methods( array $methods ): array {
		$pickup_locations = Settings_Manager::instance()->get_pickup_locations();

		foreach ( $pickup_locations as $location ) {
			if ( empty( $location['method_id'] ) ) {
				continue;
			}

			$method_id = $location['method_id'];
			$name      = $location['name'];

			// We use a dynamic class name to avoid conflicts if needed, 
			// but we can also just use the same class with different IDs if WC allows.
			// WC_Shipping_Method is designed to be subclassed per method ID.
			
			$methods[ $method_id ] = new Pickup_Shipping_Method( 0, $method_id, $name );
		}

		return $methods;
	}

	/**
	 * Add custom images to shipping method labels.
	 */
	public function add_images_to_labels( string $label, $method ): string {
		$method_id = $method->get_method_id();
		$settings_manager = Settings_Manager::instance();
		
		$image_id = '';
		
		// 1. Check if it's a pickup location
		$pickup_locations = $settings_manager->get_pickup_locations();
		foreach ( $pickup_locations as $location ) {
			if ( $location['method_id'] === $method_id ) {
				$image_id = $location['image_id'] ?? '';
				break;
			}
		}
		
		// 2. Check if it's a normal method with a custom image
		if ( ! $image_id ) {
			$method_images = $settings_manager->get_method_images();
			$image_id = $method_images[ $method_id ] ?? '';
			
			// Try with instance ID if not found by base ID
			if ( ! $image_id ) {
				$full_id = $method->get_id(); // includes instance ID
				$image_id = $method_images[ $full_id ] ?? '';
			}
		}

		// Apply custom name if exists
		$custom_names = $settings_manager->get_method_display_names();
		$full_id = $method->get_id();
		if ( ! empty( $custom_names[ $full_id ] ) ) {
			$label = $custom_names[ $full_id ];
		} elseif ( ! empty( $custom_names[ $method_id ] ) ) {
			$label = $custom_names[ $method_id ];
		}

		if ( $image_id ) {
			$image_url = wp_get_attachment_image_url( $image_id, 'thumbnail' );
			if ( $image_url ) {
				$icon = '<img src="' . esc_url( $image_url ) . '" class="ass-method-checkout-icon" style="width: 24px; height: 24px; margin-right: 8px; vertical-align: middle;" alt="">';
				return $icon . $label;
			}
		}

		return $label;
	}

	/**
	 * Invalidate stored shipping rates when needed.
	 * Currently no dynamic conditions that would require this, but keeping for future use.
	 */
	public function maybe_invalidate_stored_shipping_rates(): void {
		// For now, no conditions that require cache invalidation
		// But keeping this method for consistency with WPFactory pattern
		// Could be used if we add time-based or other dynamic conditions
	}
}
