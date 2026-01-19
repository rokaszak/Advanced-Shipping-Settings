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
		add_filter( 'woocommerce_checkout_fields', [ $this, 'add_shipping_date_field' ] );
		add_filter( 'woocommerce_form_field_ass_custom', [ $this, 'render_custom_field' ], 10, 4 );
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate_date_validity' ], 10, 2 );
		add_filter( 'woocommerce_update_order_review_fragments', [ $this, 'update_checkout_fragments' ], 10, 1 );
		add_action( 'woocommerce_review_order_before_order_total', [ $this, 'display_order_review_content' ], 5 );
	}

	/**
	 * Get display location setting.
	 */
	private function get_display_location(): string {
		$settings = Settings_Manager::instance()->get_plugin_settings();
		$location = $settings['display_location'] ?? 'billing';
		$allowed_locations = [ 'billing', 'shipping', 'order_review' ];
		return in_array( $location, $allowed_locations, true ) ? $location : 'billing';
	}

	/**
	 * Add custom shipping date field to checkout fields.
	 */
	public function add_shipping_date_field( array $fields ): array {
		$display_location = $this->get_display_location();
		

		$section = ( 'order_review' === $display_location ) ? 'billing' : $display_location;
		
		// Check if we need to register deliver_by_date as a proper WooCommerce field
		$rule = $this->get_current_shipping_rule();
		$available_dates = [];
		$deliver_by_date_registered = false;
		
		if ( $rule && 'by_date' === $rule['type'] ) {
			$available_dates = $this->get_available_dates_for_cart( $rule );
			if ( ! empty( $available_dates ) ) {
				$options = [];
				foreach ( $available_dates as $date_info ) {
					$options[ $date_info['date'] ] = $date_info['label'];
				}
				
				$prompt = Settings_Manager::instance()->get_translation( 'reservation_prompt', 'Select a reservation date:' );
				$field_label = Settings_Manager::instance()->get_translation( 'reservation_date_label', 'Reservation date' );
				
				$field_config = [
					'type'     => 'radio',
					'label'    => $field_label,
					'required' => true,
					'priority' => 5,
					'class'    => [ 'form-row-wide', 'ass-shipping-date-field', 'validate-required' ],
					'options'  => $options,
				];
				
				// Hide the field visually when display_location is order_review (custom HTML is shown instead)
				if ( 'order_review' === $display_location ) {
					$field_config['class'][] = 'ass-hidden-field';
				}
				
				$fields[ $section ]['deliver_by_date'] = $field_config;
				$deliver_by_date_registered = true;
			}
		}
		
		// Only register ass_shipping_date for ASAP rules or when deliver_by_date is not registered
		// This avoids conflicts when deliver_by_date is already registered for by_date rules
		if ( ! $rule || 'asap' === $rule['type'] || ! $deliver_by_date_registered ) {
			$fields[ $section ]['ass_shipping_date'] = [
				'type'        => 'ass_custom',
				'label'       => '',
				'required'    => false,
				'priority'    => 5,
				'class'       => [ 'form-row-wide', 'ass-shipping-date-field' ],
			];
		}
		
		return $fields;
	}

	/**
	 * Get current shipping rule for the selected shipping method.
	 */
	private function get_current_shipping_rule(): ?array {
		$shipping_data = ass_get_current_shipping_rate_and_rule();
		return $shipping_data ? $shipping_data['rule'] : null;
	}

	/**
	 * Display content in order review section.
	 */
	public function display_order_review_content(): void {
		if ( wp_doing_ajax() ) {
			return;
		}

		$display_location = $this->get_display_location();
		if ( 'order_review' !== $display_location ) {
			return;
		}

		$content = $this->get_shipping_date_content();
		if ( empty( $content ) ) {
			return;
		}

		$allowed_html = wp_kses_allowed_html( 'post' );
		$allowed_html['input'] = [
			'type' => true,
			'name' => true,
			'value' => true,
			'required' => true,
			'class' => true,
			'id' => true,
		];
		$allowed_html['label'] = [
			'class' => true,
			'for' => true,
		];
		$allowed_html['div'] = [
			'id' => true,
			'class' => true,
		];
		$allowed_html['a'] = array_merge( $allowed_html['a'] ?? [], [
			'href' => true,
			'target' => true,
			'rel' => true,
			'class' => true,
		] );
		$allowed_html['hr'] = [
			'class' => true,
		];

		echo '<div id="ass-checkout-shipping-info-wrapper" class="ass-checkout-order-review-wrapper">' . wp_kses( $content, $allowed_html ) . '</div>';
	}

	/**
	 * Get the shipping date content HTML.
	 */
	private function get_shipping_date_content(): string {
		$shipping_data = ass_get_current_shipping_rate_and_rule();
		if ( ! $shipping_data ) {
			return '';
		}

		$rate = $shipping_data['rate'];
		$rule = $shipping_data['rule'];
		$package = $shipping_data['package'];

		if ( ! $rate || ! $package ) {
			return '';
		}

		$shipping_filter = Shipping_Filter::instance();
		$validation = $shipping_filter->validate_shipping_method( $rate, $package );

		if ( ! $validation['res'] ) {
			return '';
		}

		// Build field HTML
		$field_html = '<div class="ass-checkout-shipping-info bricks-woo-update-ajax">';

		if ( 'asap' === $rule['type'] ) {
			$field_html .= $this->render_asap_info_html( $rule );
		} elseif ( 'by_date' === $rule['type'] ) {
			$field_html .= $this->render_date_selector_html( $rule );
		}

		// Add disclaimer at the bottom if enabled
		$settings_manager = Settings_Manager::instance();
		if ( $settings_manager->should_show_delivery_disclaimer() ) {
			$disclaimer_text = $settings_manager->get_delivery_disclaimer_text();
			$disclaimer_url = $settings_manager->get_delivery_disclaimer_url();
			
			if ( ! empty( $disclaimer_text ) && ! empty( $disclaimer_url ) ) {
				$field_html .= '<div class="ass-delivery-disclaimer">';
				$field_html .= '<hr class="ass-disclaimer-divider">';
				$field_html .= '<a href="' . esc_url( $disclaimer_url ) . '" target="_blank" rel="noopener noreferrer" class="ass-disclaimer-link">' . esc_html( $disclaimer_text ) . '</a>';
				$field_html .= '</div>';
			}
		}

		$field_html .= '</div>';

		return $field_html;
	}

	/**
	 * Render custom field content for ass_custom field type.
	 */
	public function render_custom_field( $field, $key, $args, $value ) {
		if ( 'ass_shipping_date' !== $key ) {
			return $field;
		}

		$display_location = $this->get_display_location();
		
		// If display_location is order_review, don't render here (it's shown via display_order_review_content())
		if ( 'order_review' === $display_location ) {
			return '';
		}

		// If deliver_by_date is registered as a proper WooCommerce field, don't render custom field for by_date rules
		// (deliver_by_date is now always registered for by_date rules, so this custom field should be hidden)
		$rule = $this->get_current_shipping_rule();
		if ( $rule && 'by_date' === $rule['type'] ) {
			// For by_date rules, deliver_by_date is always registered, so hide this custom field
			// Custom HTML is shown via display_order_review_content() for order_review location
			return '';
		}

		$content = $this->get_shipping_date_content();
		if ( empty( $content ) ) {
			return '';
		}

		// Return the custom HTML wrapped in the unified wrapper div
		return '<div id="ass-checkout-shipping-info-wrapper" class="ass-checkout-order-review-wrapper">' . $content . '</div>';
	}

	/**
	 * Render ASAP info label as HTML string.
	 */
	private function render_asap_info_html( array $rule ): string {
		$holidays = Settings_Manager::instance()->get_holiday_dates();
		
		// Collect cart products categories
		$products_categories = [];
		$packages = WC()->shipping()->get_packages();
		foreach ( $packages as $package ) {
			foreach ( $package['contents'] as $item ) {
				$product = $item['data'];
				$products_categories[] = $product->get_category_ids();
			}
		}

		$dates = Date_Calculator::instance()->calculate_dates_with_priority( $rule, $holidays, $products_categories );
		
		if ( empty( $dates['deliver_by_date'] ) ) {
			return '';
		}

		$prefix = Settings_Manager::instance()->get_translation( 'asap_prefix', 'Delivery no later than' );
		$formatted_date = $this->format_asap_date( $dates['deliver_by_date'] );

		$html = '<p class="ass-asap-date-info">' . esc_html( $prefix ) . ' <strong>' . esc_html( $formatted_date ) . '</strong></p>';
		// No hidden field - dates are calculated server-side at order creation for security
		
		return $html;
	}

	/**
	 * Render reservation date selector as HTML string.
	 */
	private function render_date_selector_html( array $rule ): string {
		$available_dates = $this->get_available_dates_for_cart( $rule );
		if ( empty( $available_dates ) ) {
			return '<p class="ass-no-dates-error">' . esc_html__( 'No available dates for selected items.', 'advanced-shipping-settings' ) . '</p>';
		}

		$prompt = Settings_Manager::instance()->get_translation( 'reservation_prompt', 'Select a reservation date:' );

		$html = '<p class="ass-reservation-prompt"><strong>' . esc_html( $prompt ) . '</strong></p>';
		$html .= '<div class="ass-date-options">';

		foreach ( $available_dates as $date_info ) {
			$date  = $date_info['date'];
			$label = $date_info['label'];

			$html .= '<label class="ass-date-option">';
			$html .= '<input type="radio" name="deliver_by_date" value="' . esc_attr( $date ) . '" required> ';
			$html .= esc_html( $label );
			$html .= '</label><br>';
		}

		$html .= '</div>';
		
		return $html;
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
	 * Validate that selected date is still available (prevents manipulation/race conditions).
	 * Also validates that the field is not empty when required.
	 */
	public function validate_date_validity( array $data, \WP_Error $errors ): void {
		$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
		if ( empty( $chosen_methods ) ) {
			return;
		}

		$chosen_method = $chosen_methods[0];
		
		$packages = WC()->shipping()->get_packages();
		if ( empty( $packages ) ) {
			return;
		}

		$rate = null;
		$package = null;
		foreach ( $packages as $pkg ) {
			if ( ! empty( $pkg['rates'][ $chosen_method ] ) ) {
				$rate = $pkg['rates'][ $chosen_method ];
				$package = $pkg;
				break;
			}
		}

		if ( ! $rate || ! $package ) {
			return;
		}

		$method_id = apply_filters( 'ass_shipping_method_id', $rate->method_id, $rate );
		$rules = Settings_Manager::instance()->get_shipping_rules();
		$rule = $rules[ $method_id ] ?? null;

		if ( ! $rule || 'by_date' !== $rule['type'] ) {
			return;
		}

		$shipping_filter = Shipping_Filter::instance();
		$validation = $shipping_filter->validate_shipping_method( $rate, $package );

		if ( ! $validation['res'] ) {
			return;
		}

		// Get available dates to ensure field is required
		$available_dates = $this->get_available_dates_for_cart( $rule );
		if ( empty( $available_dates ) ) {
			return;
		}

		// Explicitly validate that the field is not empty when required
		$selected_date = isset( $_POST['deliver_by_date'] ) ? sanitize_text_field( wp_unslash( $_POST['deliver_by_date'] ) ) : '';
		
		if ( empty( $selected_date ) ) {
			$field_label = Settings_Manager::instance()->get_translation( 'reservation_date_label', 'Reservation date' );
			$error_suffix = Settings_Manager::instance()->get_translation( 'required_field_error_suffix', 'is a required field.' );
			
			// Format error message like WooCommerce: <strong>Field Label</strong> error suffix
			// Escape user-provided strings to prevent XSS, then add HTML tags
			$error_message = '<strong>' . esc_html( $field_label ) . '</strong> ' . esc_html( $error_suffix );
			
			$errors->add( 
				'deliver_by_date_required', 
				$error_message,
				array( 'id' => 'deliver_by_date' )
			);
			return;
		}

		// Validate that the selected date is still available
		$is_valid = false;
		foreach ( $available_dates as $date_info ) {
			if ( $date_info['date'] === $selected_date ) {
				$is_valid = true;
				break;
			}
		}
		
		if ( ! $is_valid ) {
			$errors->add( 
				'deliver_by_date_invalid', 
				__( 'Invalid reservation date selected.', 'advanced-shipping-settings' ),
				array( 'id' => 'deliver_by_date' )
			);
		}
	}

	/**
	 * Format ASAP date for display.
	 */
	public function format_asap_date( string $date_str ): string {
		$date = DateTime::createFromFormat( 'Y-m-d', $date_str );
		if ( ! $date ) {
			return $date_str;
		}

		$settings = Settings_Manager::instance();
		$days = [
			1 => $settings->get_translation( 'day_monday', 'Monday' ),
			2 => $settings->get_translation( 'day_tuesday', 'Tuesday' ),
			3 => $settings->get_translation( 'day_wednesday', 'Wednesday' ),
			4 => $settings->get_translation( 'day_thursday', 'Thursday' ),
			5 => $settings->get_translation( 'day_friday', 'Friday' ),
			6 => $settings->get_translation( 'day_saturday', 'Saturday' ),
			7 => $settings->get_translation( 'day_sunday', 'Sunday' ),
		];

		$day_name = $days[ (int) $date->format( 'N' ) ];
		return $day_name . ', ' . $date_str;
	}

	public function update_checkout_fragments( array $fragments ): array {
		$content = $this->get_shipping_date_content();
		
		$allowed_html = wp_kses_allowed_html( 'post' );
		$allowed_html['input'] = [
			'type' => true,
			'name' => true,
			'value' => true,
			'required' => true,
			'class' => true,
			'id' => true,
		];
		$allowed_html['label'] = [
			'class' => true,
			'for' => true,
		];
		$allowed_html['div'] = [
			'id' => true,
			'class' => true,
		];
		$allowed_html['a'] = array_merge( $allowed_html['a'] ?? [], [
			'href' => true,
			'target' => true,
			'rel' => true,
			'class' => true,
		] );
		$allowed_html['hr'] = [
			'class' => true,
		];
		
		ob_start();
		?>
		<div id="ass-checkout-shipping-info-wrapper" class="ass-checkout-order-review-wrapper">
			<?php 
			if ( ! empty( $content ) ) {
				echo wp_kses( $content, $allowed_html );
			}
			?>
		</div>
		<?php
		
		// Use ID selector (more specific and reliable)
		$fragments['#ass-checkout-shipping-info-wrapper'] = ob_get_clean();
		
		return $fragments;
	}
}

