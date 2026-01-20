<?php
namespace ASS;

use WC_Order;
use DateTime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Display ship_by_date and deliver_by_date in admin, emails, and customer order details.
 */
class Order_Display_Handler {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Admin order edit page display (outside order_data panel, right below it)
		add_action( 'add_meta_boxes', [ $this, 'add_shipping_dates_meta_box' ] );
		
		// Save admin order meta edits
		add_action( 'woocommerce_process_shop_order_meta', [ $this, 'save_admin_order_meta' ], 10, 1 );
		
		// Email display
		add_action( 'woocommerce_email_order_meta', [ $this, 'display_email_order_meta' ], 10, 4 );
		
		// Customer order details display
		add_action( 'woocommerce_order_details_after_order_table', [ $this, 'display_customer_order_details' ], 10, 1 );
	}

	/**
	 * Add shipping dates meta box to order edit page.
	 */
	public function add_shipping_dates_meta_box(): void {
		// Support both HPOS and legacy order screens
		$screens = [ 'shop_order' ];
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		
		foreach ( $screens as $screen ) {
			add_meta_box(
				'ass_shipping_dates',
				__( 'Shipping Dates', 'advanced-shipping-settings' ),
				[ $this, 'display_admin_order_meta' ],
				$screen,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Display editable date fields in admin order edit page.
	 */
	public function display_admin_order_meta( $post_or_order ): void {
		// Handle both post object (legacy) and order object (HPOS)
		if ( $post_or_order instanceof WC_Order ) {
			$order = $post_or_order;
		} else {
			$order = wc_get_order( is_object( $post_or_order ) ? $post_or_order->ID : $post_or_order );
		}
		
		if ( ! $order ) {
			return;
		}
		$ship_by_date = $order->get_meta( 'ship_by_date' );
		$deliver_by_date = $order->get_meta( 'deliver_by_date' );
		
		// Only show if at least one date exists or we want to allow manual entry
		// Show fields if order has shipping method that might use dates
		$shipping_methods = $order->get_shipping_methods();
		if ( empty( $shipping_methods ) && empty( $ship_by_date ) && empty( $deliver_by_date ) ) {
			return;
		}

		?>
		<p class="form-field">
			<label for="_ass_ship_by_date"><strong><?php esc_html_e( 'Ship By Date:', 'advanced-shipping-settings' ); ?></strong></label>
			<input type="text" id="_ass_ship_by_date" name="_ass_ship_by_date" value="<?php echo esc_attr( $ship_by_date ); ?>" class="regular-text" placeholder="YYYY-MM-DD">
		</p>
		<p class="form-field">
			<label for="_ass_deliver_by_date"><strong><?php esc_html_e( 'Deliver By Date:', 'advanced-shipping-settings' ); ?></strong></label>
			<input type="text" id="_ass_deliver_by_date" name="_ass_deliver_by_date" value="<?php echo esc_attr( $deliver_by_date ); ?>" class="regular-text" placeholder="YYYY-MM-DD">
		</p>
		<?php
	}

	/**
	 * Save admin order meta edits.
	 */
	public function save_admin_order_meta( int $order_id ): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Save ship_by_date
		if ( isset( $_POST['_ass_ship_by_date'] ) ) {
			$ship_by_date = sanitize_text_field( wp_unslash( $_POST['_ass_ship_by_date'] ) );
			if ( ! empty( $ship_by_date ) ) {
				// Validate date format
				if ( $this->is_valid_date( $ship_by_date ) ) {
					$order->update_meta_data( 'ship_by_date', $ship_by_date );
				}
			} else {
				// Allow clearing the field
				$order->delete_meta_data( 'ship_by_date' );
			}
		}

		// Save deliver_by_date
		if ( isset( $_POST['_ass_deliver_by_date'] ) ) {
			$deliver_by_date = sanitize_text_field( wp_unslash( $_POST['_ass_deliver_by_date'] ) );
			if ( ! empty( $deliver_by_date ) ) {
				// Validate date format
				if ( $this->is_valid_date( $deliver_by_date ) ) {
					$order->update_meta_data( 'deliver_by_date', $deliver_by_date );
				}
			} else {
				// Allow clearing the field
				$order->delete_meta_data( 'deliver_by_date' );
			}
		}

		$order->save();
	}

	/**
	 * Display deliver_by_date in order emails.
	 */
	public function display_email_order_meta( WC_Order $order, bool $sent_to_admin, bool $plain_text, $email ): void {
		$deliver_by_date = $order->get_meta( 'deliver_by_date' );
		if ( empty( $deliver_by_date ) ) {
			return;
		}

		$formatted_date = $this->format_delivery_date_display( $deliver_by_date );
		if ( empty( $formatted_date ) ) {
			return;
		}

		$delivery_text = Settings_Manager::instance()->get_order_delivery_text();
		$display_text = $delivery_text . ' ' . $formatted_date;

		if ( $plain_text ) {
			echo "\n" . esc_html( $display_text ) . "\n";
			
			// Add disclaimer if enabled
			$disclaimer = $this->render_disclaimer( true );
			if ( ! empty( $disclaimer ) ) {
				echo "\n" . strip_tags( $disclaimer ) . "\n";
			}
		} else {
			echo '<p style="color: #1e1e1e; display: block; font-family: Tahoma,Verdana,Segoe,sans-serif; font-size: 20px; font-weight: bold; line-height: 160%; margin: 0 0 18px; text-align: left;"><strong>' . esc_html( $display_text ) . '</strong></p>';
			
			// Add disclaimer if enabled
			$disclaimer = $this->render_disclaimer( false );
			if ( ! empty( $disclaimer ) ) {
				echo wp_kses_post( $disclaimer );
			}
		}
	}

	/**
	 * Display deliver_by_date in customer order details.
	 */
	public function display_customer_order_details( WC_Order $order ): void {
		$deliver_by_date = $order->get_meta( 'deliver_by_date' );
		if ( empty( $deliver_by_date ) ) {
			return;
		}

		$formatted_date = $this->format_delivery_date_display( $deliver_by_date );
		if ( empty( $formatted_date ) ) {
			return;
		}

		$delivery_text = Settings_Manager::instance()->get_order_delivery_text();
		$display_text = $delivery_text . ' ' . $formatted_date;

		?>
		<div class="ass-order-delivery-info">
			<p><strong><?php echo esc_html( $display_text ); ?></strong></p>
			<?php
			// Add disclaimer if enabled (use CSS classes for customer-facing display)
			$disclaimer = $this->render_disclaimer( false, false );
			if ( ! empty( $disclaimer ) ) {
				echo wp_kses_post( $disclaimer );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Format date for display (ISO format: Y-m-d).
	 */
	private function format_delivery_date_display( string $date_str ): string {
		// Validate date format
		$date = DateTime::createFromFormat( 'Y-m-d', $date_str );
		if ( ! $date ) {
			return '';
		}
		
		// Return ISO format as requested
		return $date->format( 'Y-m-d' );
	}

	/**
	 * Validate date format (Y-m-d).
	 */
	private function is_valid_date( string $date_str ): bool {
		$date = DateTime::createFromFormat( 'Y-m-d', $date_str );
		return $date && $date->format( 'Y-m-d' ) === $date_str;
	}

	/**
	 * Render disclaimer HTML if enabled.
	 *
	 * @param bool $plain_text Whether to render as plain text (for email).
	 * @param bool $use_inline_styles Whether to use inline styles (true for email, false for customer-facing).
	 * @return string Disclaimer HTML or empty string.
	 */
	private function render_disclaimer( bool $plain_text = false, bool $use_inline_styles = true ): string {
		$settings_manager = Settings_Manager::instance();
		
		if ( ! $settings_manager->should_show_delivery_disclaimer() ) {
			return '';
		}

		$disclaimer_text = $settings_manager->get_delivery_disclaimer_text();
		$disclaimer_url = $settings_manager->get_delivery_disclaimer_url();

		if ( empty( $disclaimer_text ) || empty( $disclaimer_url ) ) {
			return '';
		}

		if ( $plain_text ) {
			return $disclaimer_text . ': ' . $disclaimer_url;
		}

		if ( $use_inline_styles ) {
			// For email compatibility
			$html = '<div class="ass-delivery-disclaimer" style="margin-top: 10px;">';
			$html .= '<hr class="ass-disclaimer-divider" style="margin: 15px 0 10px 0; border: 0; border-top: 1px solid #e5e5e5; background: none; height: 0;">';
			$html .= '<a href="' . esc_url( $disclaimer_url ) . '" target="_blank" rel="noopener noreferrer" class="ass-disclaimer-link" style="display: inline-block; font-size: 12px; color: #666; text-decoration: none;">' . esc_html( $disclaimer_text ) . '</a>';
			$html .= '</div>';
		} else {
			// For customer-facing display (use CSS classes)
			$html = '<div class="ass-delivery-disclaimer">';
			$html .= '<hr class="ass-disclaimer-divider">';
			$html .= '<a href="' . esc_url( $disclaimer_url ) . '" target="_blank" rel="noopener noreferrer" class="ass-disclaimer-link">' . esc_html( $disclaimer_text ) . '</a>';
			$html .= '</div>';
		}

		return $html;
	}
}
