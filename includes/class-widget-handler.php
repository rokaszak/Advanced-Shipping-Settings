<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle AJAX requests for free shipping widget updates.
 */
class Widget_Handler {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', [ $this, 'register_ajax_endpoint' ] );
		// Filter shipping rates to apply free shipping when threshold is met
		add_filter( 'woocommerce_package_rates', [ $this, 'apply_free_shipping_when_threshold_met' ], 999, 2 );
	}

	/**
	 * Register AJAX endpoint.
	 */
	public function register_ajax_endpoint(): void {
		add_action( 'wc_ajax_ass_free_shipping_widget', [ $this, 'handle_ajax_request' ] );
	}

	/**
	 * Apply free shipping when cart total meets threshold.
	 * Uses high priority (999) to run after other plugins.
	 */
	public function apply_free_shipping_when_threshold_met( array $rates, array $package ): array {
		$settings = Settings_Manager::instance()->get_widget_settings();

		// Only apply if widget is enabled
		if ( ! $settings['enabled'] ) {
			return $rates;
		}

		// Need cart to calculate totals
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return $rates;
		}

		// Calculate cart total (respecting pre-discount setting)
		if ( $settings['use_pre_discount'] ) {
			$cart_total = (float) WC()->cart->get_displayed_subtotal();
		} else {
			$cart_total = (float) WC()->cart->get_cart_contents_total();
			if ( wc_prices_include_tax() ) {
				$cart_total += (float) WC()->cart->get_cart_contents_tax();
			}
		}

		// Check each rate and apply free shipping if threshold is met
		foreach ( $rates as $rate_id => $rate ) {
			$threshold = ass_get_shipping_method_threshold( $rate );

			// Only apply if threshold is set and cart meets it
			if ( $threshold > 0 && $cart_total >= $threshold ) {
				$rate->cost = 0;
				$rate->taxes = [];
				$rates[ $rate_id ] = $rate;
			}
		}

		return $rates;
	}

	/**
	 * Handle AJAX request for widget update.
	 */
	public function handle_ajax_request(): void {
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			wp_send_json_success( [
				'html' => '',
				'mode' => 'hidden',
			] );
			return;
		}

		// Recalculate to get fresh totals
		WC()->cart->calculate_totals();
		WC()->cart->calculate_shipping();

		$rate_id = isset( $_REQUEST['rate_id'] ) 
			? sanitize_text_field( wp_unslash( $_REQUEST['rate_id'] ) )
			: '';

		// Get chosen method from session if no rate_id provided
		if ( empty( $rate_id ) ) {
			$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
			$rate_id = $chosen_methods[0] ?? '';
		}

		$html = $this->get_widget_html( $rate_id );

		wp_send_json_success( [
			'html' => $html,
			'mode' => ! empty( $html ) ? 'visible' : 'hidden',
		] );
	}

	/**
	 * Get widget HTML for given rate ID.
	 */
	public function get_widget_html( string $rate_id = '' ): string {
		$settings = Settings_Manager::instance()->get_widget_settings();

		if ( ! $settings['enabled'] ) {
			return '';
		}

		if ( empty( $rate_id ) ) {
			$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
			$rate_id = $chosen_methods[0] ?? '';
		}

		if ( empty( $rate_id ) ) {
			if ( $settings['hide_no_threshold'] ) {
				return '';
			}
			return $this->render_message( $settings['texts']['no_method'] );
		}

		// Get rate from packages
		$packages = WC()->shipping()->get_packages();
		if ( empty( $packages ) ) {
			if ( $settings['hide_no_threshold'] ) {
				return '';
			}
			return $this->render_message( $settings['texts']['no_method'] );
		}

		$rate = null;
		foreach ( $packages as $pkg ) {
			if ( ! empty( $pkg['rates'][ $rate_id ] ) ) {
				$rate = $pkg['rates'][ $rate_id ];
				break;
			}
		}

		if ( ! $rate ) {
			if ( $settings['hide_no_threshold'] ) {
				return '';
			}
			return $this->render_message( $settings['texts']['no_method'] );
		}

		$threshold = ass_get_shipping_method_threshold( $rate );
		
		if ( $settings['use_pre_discount'] ) {
			$cart_total = (float) WC()->cart->get_displayed_subtotal();
		} else {
			$cart_total = (float) WC()->cart->get_cart_contents_total();
			if ( wc_prices_include_tax() ) {
				$cart_total += (float) WC()->cart->get_cart_contents_tax();
			}
		}

		$shipping_cost = (float) $rate->get_cost();
		
		// If shipping is free (cost is 0), show same display as when progress is met
		if ( $shipping_cost <= 0 ) {
			if ( $settings['hide_already_free'] ) {
				return '';
			}
			// Always show 100% progress when shipping is free
			$display_threshold = $threshold > 0 ? $threshold : max( $cart_total, 1 );
			$progress_data = [
				'progress' => 100,
				'remaining' => 0,
				'threshold' => $display_threshold,
				'cart_total' => $cart_total,
			];
			return $this->render_widget( $settings, $progress_data, $settings['texts']['already_free'] );
		}

		// Shipping has cost - check threshold
		if ( $threshold <= 0 ) {
			if ( $settings['hide_no_threshold'] ) {
				return '';
			}
			return $this->render_message( $settings['texts']['no_threshold'] );
		}

		// Calculate progress (threshold is set and shipping has cost)
		$progress_data = $this->calculate_progress( $cart_total, $threshold );

		// If threshold is met, show widget with 100% progress and "already_free" message
		if ( $progress_data['progress'] >= 100 ) {
			if ( $settings['hide_already_free'] ) {
				return '';
			}
			return $this->render_widget( $settings, $progress_data, $settings['texts']['already_free'] );
		}

		// Threshold is set but cart doesn't meet it yet - show progress
		return $this->render_widget( $settings, $progress_data );
	}

	/**
	 * Calculate progress percentage and remaining amount.
	 */
	private function calculate_progress( float $cart_total, float $threshold ): array {
		$progress = max( 0, min( 100, round( ( $cart_total / $threshold ) * 100 ) ) );
		$remaining = max( 0, $threshold - $cart_total );

		return [
			'progress' => $progress,
			'remaining' => $remaining,
			'threshold' => $threshold,
			'cart_total' => $cart_total,
		];
	}

	/**
	 * Render widget HTML.
	 */
	private function render_widget( array $settings, array $progress_data, string $custom_note = '' ): string {
		$title = esc_html( $settings['texts']['title'] );
		$progress = $progress_data['progress'];
		$remaining = wc_price( $progress_data['remaining'] );
		$threshold = wc_price( $progress_data['threshold'] );

		// Use custom note if provided (e.g., "already_free" message), otherwise use template
		if ( ! empty( $custom_note ) ) {
			$note_text = esc_html( $custom_note );
		} else {
			// Replace placeholders in template
			$note_text = str_replace(
				[ '{remaining}', '{threshold}' ],
				[ $remaining, $threshold ],
				$settings['texts']['progress_template']
			);
		}

		ob_start();
		?>
		<div class="ass-free-shipping-widget" aria-live="polite">
			<div class="ass-free-shipping-widget__title">
				<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
					<path d="M3 7h11a2 2 0 0 1 2 2v1h2.5l2 3V17a2 2 0 0 1-2 2h-1a2 2 0 0 1-4 0H9a2 2 0 0 1-4 0H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1Z"></path>
				</svg>
				<?php echo $title; ?>
			</div>
			<div class="ass-free-shipping-widget__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( $progress ); ?>">
				<span style="width:<?php echo esc_attr( $progress ); ?>%"></span>
			</div>
			<div class="ass-free-shipping-widget__note">
				<?php echo wp_kses_post( $note_text ); ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render message HTML.
	 */
	private function render_message( string $message ): string {
		ob_start();
		?>
		<div class="ass-free-shipping-widget" aria-live="polite" data-state="message">
			<div class="ass-free-shipping-widget__note">
				<?php echo esc_html( $message ); ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
