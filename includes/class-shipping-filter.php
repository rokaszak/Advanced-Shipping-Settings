<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filter available shipping methods based on cart categories.
 * Modeled after WPFactory's shipping filtering approach.
 */
class Shipping_Filter {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Hook is now handled by the Hooks class
	}

	/**
	 * Filter shipping methods based on rules using WPFactory pattern.
	 */
	public function filter_shipping_methods( array $rates, array $package ): array {
		$rules = Settings_Manager::instance()->get_shipping_rules();
		if ( empty( $rules ) ) {
			return $rates;
		}

		foreach ( $rates as $rate_key => $rate ) {
			$validation = $this->validate_shipping_method( $rate, $package );

			if ( ! $validation['res'] ) {
				unset( $rates[ $rate_key ] );
			}
		}

		return $rates;
	}

	/**
	 * Validate if a shipping method should be available for the current package.
	 * Returns array with 'res' (boolean) and 'hide_reason' (string).
	 */
	public function validate_shipping_method( $rate, array $package ): array {
		$rules = Settings_Manager::instance()->get_shipping_rules();

		// Get method_id from rate object (WPFactory pattern)
		$method_id = apply_filters( 'ass_shipping_method_id', $rate->method_id, $rate );
		$method_rule = $rules[ $method_id ] ?? null;

		if ( ! $method_rule ) {
			// No rules for this method type = allow it
			return [ 'res' => true, 'hide_reason' => false ];
		}

		// Get cart categories for validation
		$products_categories = $this->get_cart_categories( $package );

		if ( ! $this->is_method_available_for_products( $method_rule, $products_categories ) ) {
			return [
				'res' => false,
				'hide_reason' => 'category_mismatch'
			];
		}

		return [ 'res' => true, 'hide_reason' => false ];
	}

	/**
	 * Get categories from all products in the cart.
	 */
	private function get_cart_categories( array $package ): array {
		$products_categories = [];
		foreach ( $package['contents'] as $item ) {
			$product = $item['data']; // WC_Product object from package
			$product_cats = $product->get_category_ids(); // WooCommerce method
			if ( empty( $product_cats ) ) {
				$products_categories[] = [];
			} else {
				$products_categories[] = $product_cats;
			}
		}
		return $products_categories;
	}

	/**
	 * Check if a method is available for the given products.
	 */
	private function is_method_available_for_products( array $rule, array $products_categories ): bool {
		if ( 'asap' === $rule['type'] ) {
			$allowed_categories = $rule['categories'] ?? [];
			
			// Add categories from priority days
			if ( ! empty( $rule['priority_days'] ) ) {
				foreach ( $rule['priority_days'] as $p_day ) {
					if ( ! empty( $p_day['categories'] ) ) {
						$allowed_categories = array_merge( $allowed_categories, $p_day['categories'] );
					}
				}
				$allowed_categories = array_unique( $allowed_categories );
			}
			
			return $this->all_products_match_categories( $allowed_categories, $products_categories );
		} elseif ( 'by_date' === $rule['type'] ) {
			$dates = $rule['dates'] ?? [];
			foreach ( $dates as $date_info ) {
				if ( ! $this->is_date_visible( $date_info ) ) {
					continue;
				}
				$date_categories = $date_info['categories'] ?? [];
				if ( $this->all_products_match_categories( $date_categories, $products_categories ) ) {
					return true; // At least one date works for all products.
				}
			}
		}
		return false;
	}

	/**
	 * Check if all products match at least one of the allowed categories.
	 */
	private function all_products_match_categories( array $allowed_categories, array $products_categories ): bool {
		if ( empty( $allowed_categories ) ) {
			return false;
		}
		foreach ( $products_categories as $product_cats ) {
			if ( empty( $product_cats ) ) {
				return false;
			}
			// "it needs just ONE of its categories to be included in a shipping method"
			$matches = array_intersect( $product_cats, $allowed_categories );
			if ( empty( $matches ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Check if a date is visible according to the rules.
	 */
	public function is_date_visible( array $date_info ): bool {
		$reservation_date = $date_info['date'] ?? '';
		$show_until       = $date_info['show_until'] ?? '';
		
		if ( empty( $reservation_date ) ) {
			return false;
		}

		$now_str = current_datetime()->format( 'Y-m-d' );
		
		// Date is hidden as soon as it's reached.
		if ( $now_str >= $reservation_date ) {
			return false;
		}

		// Optional early return.
		if ( ! empty( $show_until ) && $now_str >= $show_until ) {
			return false;
		}

		return true;
	}
}

