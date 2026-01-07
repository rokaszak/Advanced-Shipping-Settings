<?php
namespace ASS;

use DateTime;
use WC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Display date selection UI at checkout.
 */
class Checkout_Handler {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_review_order_after_shipping', [ $this, 'display_date_selector' ] );
		add_action( 'woocommerce_checkout_process', [ $this, 'validate_date_selection' ] );
	}

	/**
	 * Display the date selector or ASAP label.
	 */
	public function display_date_selector(): void {
		$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
		if ( empty( $chosen_methods ) ) {
			return;
		}

		$chosen_method = $chosen_methods[0];
		$rules         = Settings_Manager::instance()->get_shipping_rules();

		// Extract method_id from chosen method (WPFactory pattern)
		$method_id = $this->get_method_id_from_rate_key( $chosen_method );
		$rule      = $rules[ $method_id ] ?? null;

		if ( ! $rule ) {
			return;
		}

		echo '<div class="ass-checkout-shipping-info">';

		if ( 'asap' === $rule['type'] ) {
			$this->render_asap_info( $rule );
		} elseif ( 'by_date' === $rule['type'] ) {
			$this->render_date_selector( $rule );
		}

		echo '</div>';
	}

	/**
	 * Render ASAP info label.
	 */
	private function render_asap_info( array $rule ): void {
		$sending_days  = $rule['sending_days'] ?? [];
		$max_ship_days = $rule['max_ship_days'] ?? 0;
		$holidays      = Settings_Manager::instance()->get_holiday_dates();
		
		$asap_date = Date_Calculator::instance()->calculate_asap_date( $sending_days, $max_ship_days, $holidays );
		
		if ( ! $asap_date ) {
			return;
		}

		$prefix = Settings_Manager::instance()->get_translation( 'asap_prefix', 'Pristatymas ne vėliau kaip' );
		$formatted_date = $this->format_asap_date( $asap_date );

		echo '<p class="ass-asap-date-info">' . esc_html( $prefix ) . ' <strong>' . esc_html( $formatted_date ) . '</strong></p>';
		// We'll use this hidden field to pass the calculated date to the order creation.
		echo '<input type="hidden" name="ass_calculated_asap_date" value="' . esc_attr( $asap_date ) . '">';
	}

	/**
	 * Render reservation date selector.
	 */
	private function render_date_selector( array $rule ): void {
		$available_dates = $this->get_available_dates_for_cart( $rule );
		if ( empty( $available_dates ) ) {
			echo '<p class="ass-no-dates-error">' . esc_html__( 'No available dates for selected items.', 'advanced-shipping-settings' ) . '</p>';
			return;
		}

		$prompt = Settings_Manager::instance()->get_translation( 'reservation_prompt', 'Select a reservation date:' );

		echo '<p class="ass-reservation-prompt"><strong>' . esc_html( $prompt ) . '</strong></p>';
		echo '<div class="ass-date-options">';

		foreach ( $available_dates as $date_info ) {
			$date  = $date_info['date'];
			$label = $date_info['label'];

			echo '<label class="ass-date-option">';
			echo '<input type="radio" name="reservation_date" value="' . esc_attr( $date ) . '" required> ';
			echo esc_html( $label );
			echo '</label><br>';
		}

		echo '</div>';
	}

	/**
	 * Get available dates for current cart content.
	 */
	public function get_available_dates_for_cart( array $rule ): array {
		$dates = $rule['dates'] ?? [];
		$available_dates = [];

		$products_categories = [];
		$packages = WC()->shipping()->get_packages();
		foreach ( $packages as $package ) {
			foreach ( $package['contents'] as $item ) {
				$product = $item['data']; // WC_Product object from package
				$products_categories[] = $product->get_category_ids();
			}
		}

		foreach ( $dates as $date_info ) {
			if ( ! Shipping_Filter::instance()->is_date_visible( $date_info ) ) {
				continue;
			}

			$date_categories = $date_info['categories'] ?? [];
			$match_all = true;
			foreach ( $products_categories as $product_cats ) {
				if ( empty( $product_cats ) || empty( array_intersect( $product_cats, $date_categories ) ) ) {
					$match_all = false;
					break;
				}
			}

			if ( $match_all ) {
				$available_dates[] = $date_info;
			}
		}

		return $available_dates;
	}

	/**
	 * Extract method_id from WooCommerce rate key.
	 * Rate keys are in format "method_id:instance_id" (e.g., "flat_rate:5").
	 */
	private function get_method_id_from_rate_key( string $rate_key ): string {
		$parts = explode( ':', $rate_key, 2 );
		return $parts[0];
	}

	/**
	 * Validate date selection during checkout.
	 */
	public function validate_date_selection(): void {
		$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
		if ( empty( $chosen_methods ) ) {
			return;
		}

		$chosen_method = $chosen_methods[0];
		$rules         = Settings_Manager::instance()->get_shipping_rules();

		// Extract method_id from chosen method
		$method_id = $this->get_method_id_from_rate_key( $chosen_method );
		$rule      = $rules[ $method_id ] ?? null;

		if ( ! $rule || 'by_date' !== $rule['type'] ) {
			return;
		}

		if ( empty( $_POST['reservation_date'] ) ) {
			$error_msg = Settings_Manager::instance()->get_translation( 'error_date_required', 'Prašome pasirinkti rezervacijos datą.' );
			wc_add_notice( $error_msg, 'error' );
		} else {
			$selected_date = sanitize_text_field( wp_unslash( $_POST['reservation_date'] ) );
			$available_dates = $this->get_available_dates_for_cart( $rule );
			$is_valid = false;
			foreach ( $available_dates as $date_info ) {
				if ( $date_info['date'] === $selected_date ) {
					$is_valid = true;
					break;
				}
			}
			if ( ! $is_valid ) {
				wc_add_notice( __( 'Invalid reservation date selected.', 'advanced-shipping-settings' ), 'error' );
			}
		}
	}

	/**
	 * Format ASAP date for Lithuanian display.
	 */
	public function format_asap_date( string $date_str ): string {
		$date = DateTime::createFromFormat( 'Y-m-d', $date_str );
		if ( ! $date ) {
			return $date_str;
		}

		$days = [
			1 => 'pirmadienis',
			2 => 'antradienis',
			3 => 'trečiadienis',
			4 => 'ketvirtadienis',
			5 => 'penktadienis',
			6 => 'šeštadienis',
			7 => 'sekmadienis',
		];

		$day_name = $days[ (int) $date->format( 'N' ) ];
		return $day_name . ', ' . $date_str;
	}
}

