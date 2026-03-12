<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filter available shipping methods based on cart tags.
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

		// Get cart tags for validation
		$products_tags = $this->get_cart_tags( $package );

		if ( ! $this->is_method_available_for_products( $method_rule, $products_tags ) ) {
			return [
				'res' => false,
				'hide_reason' => 'tag_mismatch'
			];
		}

		return [ 'res' => true, 'hide_reason' => false ];
	}

	/**
	 * Get tags from all products in the cart.
	 */
	private function get_cart_tags( array $package ): array {
		$products_tags = [];
		foreach ( $package['contents'] as $item ) {
			$product = $item['data']; // WC_Product object from package
			$product_tags = $product->get_tag_ids();
			if ( empty( $product_tags ) ) {
				$products_tags[] = [];
			} else {
				$products_tags[] = $product_tags;
			}
		}
		return $products_tags;
	}

	/**
	 * Check if a method is available for the given products.
	 */
	private function is_method_available_for_products( array $rule, array $products_tags ): bool {
		if ( 'asap' === $rule['type'] ) {
			$allowed_tags = $rule['tags'] ?? [];

			// Add tags from priority days
			if ( ! empty( $rule['priority_days'] ) ) {
				foreach ( $rule['priority_days'] as $p_day ) {
					if ( ! empty( $p_day['tags'] ) ) {
						$allowed_tags = array_merge( $allowed_tags, $p_day['tags'] );
					}
				}
				$allowed_tags = array_unique( $allowed_tags );
			}

			return $this->all_products_match_tags( $allowed_tags, $products_tags );
		} elseif ( 'by_date' === $rule['type'] ) {
			$dates = $rule['dates'] ?? [];
			foreach ( $dates as $date_info ) {
				if ( ! $this->is_date_visible( $date_info ) ) {
					continue;
				}
				$date_tags = $date_info['tags'] ?? [];
				if ( $this->all_products_match_tags( $date_tags, $products_tags ) ) {
					return true; // At least one date works for all products.
				}
			}
		}
		return false;
	}

	/**
	 * Check if all products match at least one of the allowed tags.
	 */
	private function all_products_match_tags( array $allowed_tags, array $products_tags ): bool {
		if ( empty( $allowed_tags ) ) {
			return false;
		}
		foreach ( $products_tags as $product_tag_ids ) {
			if ( empty( $product_tag_ids ) ) {
				return false;
			}
			// "it needs just ONE of its tags to be included in a shipping method"
			$matches = array_intersect( $product_tag_ids, $allowed_tags );
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

		$now = current_datetime();
		$now_str = $now->format( 'Y-m-d' );

		// Date is hidden as soon as it's reached.
		if ( $now_str >= $reservation_date ) {
			return false;
		}

		// Optional early return: hide when Show Until date/time is reached.
		if ( ! empty( $show_until ) ) {
			if ( strpos( $show_until, ' ' ) !== false ) {
				$show_until_dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', substr( $show_until, 0, 16 ), $now->getTimezone() );
				if ( false !== $show_until_dt && $now >= $show_until_dt ) {
					return false;
				}
			} else {
				if ( $now_str >= $show_until ) {
					return false;
				}
			}
		}

		return true;
	}
}
