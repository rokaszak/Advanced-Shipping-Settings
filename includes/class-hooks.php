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

	private $plugin_removed_all_rates = [];

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
		add_action( 'woocommerce_shipping_init', [ $this, 'init_pickup_shipping_methods' ] );

		// Add pickup location logos to checkout shipping labels
		add_filter( 'woocommerce_cart_shipping_method_full_label', [ $this, 'add_pickup_location_logo' ], 10, 2 );

		// Checkout validation to prevent stale/cached shipping selections
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'checkout_validation' ], PHP_INT_MAX, 2 );

		// JS for dynamic checkout updates when relevant fields change
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_scripts' ] );

		// Invalidate shipping cache when needed
		add_action( 'init', [ $this, 'maybe_invalidate_stored_shipping_rates' ] );

		// Custom messages for when plugin removes all shipping methods
		add_filter( 'woocommerce_cart_no_shipping_available_html', [ $this, 'custom_cart_no_shipping_message' ], 10, 2 );
		add_filter( 'woocommerce_no_shipping_available_html', [ $this, 'custom_checkout_no_shipping_message' ], 10, 1 );

		// Hide checkout button on cart page when plugin removed all shipping methods
		// Hook early to remove the default action before it fires
		add_action( 'template_redirect', [ $this, 'maybe_hide_checkout_button' ], 5 );
	}

	/**
	 * Filter available shipping methods based on cart categories and rules.
	 * Uses PHP_INT_MAX priority to run after all other filters.
	 */
	public function filter_shipping_methods( array $rates, array $package ): array {
		$original_count = count( $rates );
		$shipping_filter = Shipping_Filter::instance();
		$filtered_rates = $shipping_filter->filter_shipping_methods( $rates, $package );
		
		$package_key = md5( serialize( $package ) );
		$this->plugin_removed_all_rates[ $package_key ] = ( $original_count > 0 && empty( $filtered_rates ) );
		
		return $filtered_rates;
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
			$methods[ $location['method_id'] ] = 'ASS\Pickup_Shipping_Method_' . $location['method_id'];
		}

		return $methods;
	}

	/**
	 * Initialize pickup shipping method classes.
	 */
	public function init_pickup_shipping_methods(): void {
		$pickup_locations = Settings_Manager::instance()->get_pickup_locations();

		foreach ( $pickup_locations as $location ) {
			$method_id = $location['method_id'];
			$name      = $location['name'];
			$class_name = 'Pickup_Shipping_Method_' . $method_id;

			if ( ! class_exists( 'ASS\\' . $class_name ) ) {
				$eval_code = "namespace ASS; class $class_name extends Pickup_Shipping_Method { 
					public function __construct(\$instance_id = 0) { 
						parent::__construct(\$instance_id, '$method_id', '$name'); 
					} 
				}";
				eval( $eval_code );
			}
		}
	}

	/**
	 * Add pickup location logos to checkout shipping method labels.
	 */
	public function add_pickup_location_logo( string $label, $method ): string {
		$method_id = $method->get_method_id();
		$pickup_locations = Settings_Manager::instance()->get_pickup_locations();
		
		// Check if it's a pickup location
		$image_id = '';
		foreach ( $pickup_locations as $location ) {
			if ( $location['method_id'] === $method_id ) {
				$image_id = $location['image_id'] ?? '';
				break;
			}
		}

		if ( $image_id ) {
			$image_url = wp_get_attachment_image_url( $image_id, 'thumbnail' );
			if ( $image_url ) {
				$icon = '<img src="' . esc_url( $image_url ) . '" class="ass-pickup-checkout-logo" style="height: 30px; width: auto; margin-right: 10px; vertical-align: middle;" alt="">';
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


	private function did_plugin_remove_all_rates(): bool {
		// Check if any package had all rates removed by plugin
		foreach ( $this->plugin_removed_all_rates as $removed ) {
			if ( $removed ) {
				return true;
			}
		}
		return false;
	}

	public function custom_cart_no_shipping_message( string $html, string $formatted_destination ): string {
		// Only show custom message if plugin removed all rates
		if ( ! $this->did_plugin_remove_all_rates() ) {
			return $html;
		}

		$message = Settings_Manager::instance()->get_cart_no_shipping_message();
		return '<p class="ass-no-shipping-message">' . esc_html( $message ) . '</p>';
	}

	public function custom_checkout_no_shipping_message( string $html ): string {
		// Only show custom message if plugin removed all rates
		if ( ! $this->did_plugin_remove_all_rates() ) {
			return $html;
		}

		$message = Settings_Manager::instance()->get_checkout_no_shipping_message();
		return '<p class="ass-no-shipping-message">' . esc_html( $message ) . '</p>';
	}

	/**
	 * Hide checkout button on cart page when plugin removed all shipping methods.
	 * Called on template_redirect to ensure shipping rates have been calculated.
	 */
	public function maybe_hide_checkout_button(): void {
		// Only run on cart page
		if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return;
		}

		// Force calculation of shipping rates if not already done
		if ( WC()->cart->needs_shipping() && WC()->cart->show_shipping() ) {
			WC()->cart->calculate_shipping();
		}

		// Check if plugin removed all rates and remove checkout button
		if ( $this->did_plugin_remove_all_rates() ) {
			// Remove the default proceed to checkout button
			remove_action( 'woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 20 );
		}
	}
}
