<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Display available shipping methods and dates on product pages.
 */
class Shortcode_Handler {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Render the [advanced_shipping_info] shortcode.
	 */
	public function render_shortcode( array $atts ): string {
		global $product;

		if ( ! $product ) {
			return '';
		}

		$product_cats = $product->get_category_ids();
		if ( empty( $product_cats ) ) {
			return '';
		}

		$rules = Settings_Manager::instance()->get_shipping_rules();
		if ( empty( $rules ) ) {
			return '';
		}

		$matching_methods = [];
		foreach ( $rules as $method_id => $rule ) {
			if ( $this->product_matches_rule( $product_cats, $rule ) ) {
				$matching_methods[ $method_id ] = $rule;
			}
		}

		if ( empty( $matching_methods ) ) {
			return '';
		}

		ob_start();
		$this->render_ui( $matching_methods, $product_cats );
		return ob_get_clean();
	}

	/**
	 * Check if a product matches a shipping method rule.
	 */
	private function product_matches_rule( array $product_cats, array $rule ): bool {
		if ( 'asap' === $rule['type'] ) {
			$allowed = $rule['categories'] ?? [];
			return ! empty( array_intersect( $product_cats, $allowed ) );
		} elseif ( 'by_date' === $rule['type'] ) {
			$dates = $rule['dates'] ?? [];
			foreach ( $dates as $date_info ) {
				if ( ! Shipping_Filter::instance()->is_date_visible( $date_info ) ) {
					continue;
				}
				$allowed = $date_info['categories'] ?? [];
				if ( ! empty( array_intersect( $product_cats, $allowed ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Render the UI for matching methods.
	 */
	private function render_ui( array $methods, array $product_cats ): void {
		echo '<div class="ass-shipping-info">';
		
		foreach ( $methods as $method_id => $rule ) {
			$method_name = $this->get_method_name( $method_id );
			
			echo '<div class="ass-method">';
			echo '<div class="ass-method-name"><strong>' . esc_html( $method_name ) . '</strong></div>';

			if ( 'asap' === $rule['type'] ) {
				$this->render_asap_ui( $rule );
			} else {
				$this->render_by_date_ui( $rule, $product_cats );
			}
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Render ASAP info.
	 */
	private function render_asap_ui( array $rule ): void {
		$sending_days  = $rule['sending_days'] ?? [];
		$max_ship_days = $rule['max_ship_days'] ?? 0;
		$holidays      = Settings_Manager::instance()->get_holiday_dates();
		$asap_date     = Date_Calculator::instance()->calculate_asap_date( $sending_days, $max_ship_days, $holidays );

		if ( ! $asap_date ) {
			return;
		}

		$prefix = Settings_Manager::instance()->get_translation( 'asap_prefix', 'Pristatymas ne vėliau kaip' );
		$formatted_date = Checkout_Handler::instance()->format_asap_date( $asap_date );

		echo '<div class="ass-asap-date">' . esc_html( $prefix ) . ' ' . esc_html( $formatted_date ) . '</div>';
	}

	/**
	 * Render BY DATE info.
	 */
	private function render_by_date_ui( array $rule, array $product_cats ): void {
		$dates = $rule['dates'] ?? [];
		$available_dates = [];

		foreach ( $dates as $date_info ) {
			if ( ! Shipping_Filter::instance()->is_date_visible( $date_info ) ) {
				continue;
			}
			$allowed = $date_info['categories'] ?? [];
			if ( ! empty( array_intersect( $product_cats, $allowed ) ) ) {
				$available_dates[] = $date_info;
			}
		}

		if ( empty( $available_dates ) ) {
			return;
		}

		$label = Settings_Manager::instance()->get_translation( 'shortcode_rezervuoti', 'Rezervuoti galite:' );
		echo '<div class="ass-method-dates">';
		echo '<div class="ass-date-label">' . esc_html( $label ) . '</div>';
		foreach ( $available_dates as $date_info ) {
			echo '<div class="ass-date">' . esc_html( $date_info['label'] ) . '</div>';
		}
		echo '</div>';
	}

	/**
	 * Helper to get shipping method name.
	 */
	private function get_method_name( string $method_id_instance ): string {
		$parts = explode( ':', $method_id_instance );
		$instance_id = $parts[1] ?? '';

		if ( $instance_id ) {
			$method = \WC_Shipping_Zones::get_shipping_method( $instance_id );
			if ( $method ) {
				return $method->get_title();
			}
		}

		return $method_id_instance;
	}
}

