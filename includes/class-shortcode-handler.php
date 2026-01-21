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
			
			// Add categories from priority days
			if ( ! empty( $rule['priority_days'] ) ) {
				foreach ( $rule['priority_days'] as $p_day ) {
					if ( ! empty( $p_day['categories'] ) ) {
						$allowed = array_merge( $allowed, $p_day['categories'] );
					}
				}
				$allowed = array_unique( $allowed );
			}
			
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
		
		$method_images = Settings_Manager::instance()->get_method_images();

		foreach ( $methods as $method_id => $rule ) {
			$method_name = $this->get_method_name( $method_id );
			$image_id = $method_images[ $method_id ] ?? '';
			
			echo '<div class="ass-method">';
			
			echo '<div class="ass-method-header-display">';
			if ( $image_id ) {
				$image_url = wp_get_attachment_image_url( $image_id, 'medium' );
				if ( $image_url ) {
					echo '<img src="' . esc_url( $image_url ) . '" class="ass-method-logo" alt="' . esc_attr( $method_name ) . '">';
				}
			}
			echo '<div class="ass-method-name"><strong>' . esc_html( $method_name ) . '</strong></div>';
			echo '</div>';

			if ( 'asap' === $rule['type'] ) {
				$this->render_asap_ui( $rule, $product_cats );
			} else {
				$this->render_by_date_ui( $rule, $product_cats );
			}
			echo '</div>';
		}

		// Add disclaimer at the bottom if enabled
		$settings_manager = Settings_Manager::instance();
		if ( $settings_manager->should_show_delivery_disclaimer() ) {
			$disclaimer_text = $settings_manager->get_delivery_disclaimer_text();
			$disclaimer_url = $settings_manager->get_delivery_disclaimer_url();
			
			if ( ! empty( $disclaimer_text ) && ! empty( $disclaimer_url ) ) {
				echo '<div class="ass-delivery-disclaimer">';
				echo '<hr class="ass-disclaimer-divider">';
				echo '<a href="' . esc_url( $disclaimer_url ) . '" target="_blank" rel="noopener noreferrer" class="ass-disclaimer-link">' . esc_html( $disclaimer_text ) . '</a>';
				echo '</div>';
			}
		}

		echo '</div>';
	}

	/**
	 * Render ASAP info.
	 */
	private function render_asap_ui( array $rule, array $product_cats ): void {
		$holidays      = Settings_Manager::instance()->get_holiday_dates();
		$asap_date     = Date_Calculator::instance()->calculate_asap_date_with_priority( $rule, $holidays, [ $product_cats ] );

		if ( ! $asap_date ) {
			return;
		}

		$prefix = Settings_Manager::instance()->get_translation( 'asap_prefix', 'Delivery no later than' );
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

		$label = Settings_Manager::instance()->get_translation( 'shortcode_rezervuoti', 'Available to reserve:' );
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
		$custom_names = Settings_Manager::instance()->get_method_display_names();
		
		// 1. Check if we have a custom name for the full instance ID (e.g. flat_rate:1)
		if ( ! empty( $custom_names[ $method_id_instance ] ) ) {
			return $custom_names[ $method_id_instance ];
		}

		$parts = explode( ':', $method_id_instance );
		$method_id = $parts[0];
		$instance_id = $parts[1] ?? '';

		// 2. Check if we have a custom name for the base method ID (e.g. flat_rate)
		if ( ! empty( $custom_names[ $method_id ] ) ) {
			return $custom_names[ $method_id ];
		}

		if ( $instance_id ) {
			$method = \WC_Shipping_Zones::get_shipping_method( $instance_id );
			if ( $method ) {
				return $method->get_title();
			}
		}

		return $method_id_instance;
	}

	/**
	 * Render the [ass_free_shipping_widget] shortcode.
	 */
	public function render_free_shipping_widget( array $atts = [] ): string {
		$settings = Settings_Manager::instance()->get_widget_settings();

		if ( ! $settings['enabled'] ) {
			return '';
		}

		// Get current shipping method
		$chosen_methods = WC()->session ? WC()->session->get( 'chosen_shipping_methods' ) : [];
		$rate_id = $chosen_methods[0] ?? '';

		// Get widget HTML from handler
		$widget_handler = Widget_Handler::instance();
		$html = $widget_handler->get_widget_html( $rate_id );

		// Add inline JavaScript for AJAX updates
		$script_id = 'ass-free-shipping-widget-' . uniqid();
		$ajax_url = $this->get_ajax_url();

		ob_start();
		?>
		<div id="<?php echo esc_attr( $script_id ); ?>-container">
			<?php echo $html; ?>
		</div>
		<script type="text/javascript">
		(function($) {
			'use strict';
			
			var container = $('#<?php echo esc_js( $script_id ); ?>-container');
			if (!container.length) return;
			
			var ajaxUrl = '<?php echo esc_js( $ajax_url ); ?>';
			var xhr = null;
			
			function refreshWidget() {
				if (xhr) {
					xhr.abort();
				}
				
				// Always get fresh reference to widget (it might have been removed/hidden)
				var widget = container.find('.ass-free-shipping-widget');
				
				var selectedMethod = $('input[name^="shipping_method["]:checked').val() || '';
				if (!selectedMethod && typeof wc_cart_fragments_params !== 'undefined') {
					var chosenMethods = wc_cart_fragments_params.chosen_shipping_methods || [];
					selectedMethod = chosenMethods[0] || '';
				}
				
				var params = selectedMethod ? { rate_id: selectedMethod } : {};
				var url = ajaxUrl + (ajaxUrl.indexOf('?') > -1 ? '&' : '?') + $.param(params);
				
				// Add loading class if widget exists
				if (widget.length) {
					widget.addClass('ass-free-shipping-widget--loading');
				}
				
				xhr = $.ajax({
					type: 'GET',
					url: url,
					success: function(response) {
						if (response && response.success && response.data) {
							if (response.data.html && response.data.html.trim()) {
								// Widget HTML returned - replace or append
								if (widget.length) {
									widget.replaceWith(response.data.html);
								} else {
									// Widget was removed, append new one
									container.html(response.data.html);
								}
								// Get fresh reference to new widget
								widget = container.find('.ass-free-shipping-widget');
								if (widget.length) {
									widget.show(); // Ensure it's visible
								}
							} else {
								// Empty HTML - hide or remove widget
								if (widget.length) {
									widget.hide();
								}
							}
						}
					},
					error: function() {
						// Silently fail - widget stays as is
					},
					complete: function() {
						// Get fresh reference and remove loading class
						widget = container.find('.ass-free-shipping-widget');
						if (widget.length) {
							widget.removeClass('ass-free-shipping-widget--loading');
						}
						xhr = null;
					}
				});
			}
			
			// Listen to cart/checkout update events
			$(document.body).on('updated_cart_totals updated_checkout wc_fragments_refreshed applied_coupon removed_coupon added_to_cart removed_from_cart', refreshWidget);
			
			// Listen to shipping method changes
			$(document.body).on('change', 'input[name^="shipping_method["]', refreshWidget);
			
			// Initial refresh after a short delay to ensure cart is loaded
			setTimeout(refreshWidget, 500);
		})(jQuery);
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Get AJAX URL for widget updates.
	 */
	private function get_ajax_url(): string {
		// Use WooCommerce AJAX endpoint
		return home_url( '/?wc-ajax=ass_free_shipping_widget' );
	}
}

