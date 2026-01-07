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

		// Checkout validation to prevent stale/cached shipping selections
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'checkout_validation' ], PHP_INT_MAX, 2 );

		// JS for dynamic checkout updates when relevant fields change
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_scripts' ] );

		// Invalidate shipping cache when needed
		add_action( 'init', [ $this, 'maybe_invalidate_stored_shipping_rates' ] );

		// Notices for hidden shipping methods
		$notice_hooks = apply_filters( 'ass_shipping_notice_hooks', [
			'woocommerce_before_cart',
			'woocommerce_before_checkout_form',
		] );
		foreach ( $notice_hooks as $notice_hook ) {
			add_action( $notice_hook, [ $this, 'notices' ], 9 );
		}
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
	 * Display notices about hidden shipping methods.
	 */
	public function notices(): void {
		$session_data = WC()->session->get( 'ass_shipping_data', [] );

		if ( ! empty( $session_data['unset'] ) ) {
			foreach ( $session_data['unset'] as $rate_key => $rate_data ) {
				if ( empty( $rate_data['rate'] ) || empty( $rate_data['hide_reason'] ) ) {
					continue;
				}

				$message = apply_filters( 'ass_hidden_shipping_notice',
					__( '%shipping_method% is not available.', 'advanced-shipping-settings' ),
					$rate_data['rate'], $rate_data['hide_reason']
				);
				$message = str_replace( '%shipping_method%', $rate_data['rate']->get_label(), $message );

				wc_add_notice( $message, 'notice' );
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
	 * Invalidate stored shipping rates when needed.
	 * Currently no dynamic conditions that would require this, but keeping for future use.
	 */
	public function maybe_invalidate_stored_shipping_rates(): void {
		// For now, no conditions that require cache invalidation
		// But keeping this method for consistency with WPFactory pattern
		// Could be used if we add time-based or other dynamic conditions
	}
}
