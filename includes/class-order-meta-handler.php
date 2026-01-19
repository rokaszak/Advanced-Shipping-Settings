<?php
namespace ASS;

use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Save ship_by_date and deliver_by_date to order meta (HPOS compatible).
 */
class Order_Meta_Handler {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_checkout_create_order', [ $this, 'save_shipping_date_meta' ], 10, 2 );
	}

	/**
	 * Save shipping date meta to order.
	 */
	public function save_shipping_date_meta( WC_Order $order, array $data ): void {
		// Get shipping rate and rule from session/packages (order doesn't have shipping methods yet)
		$shipping_data = ass_get_current_shipping_rate_and_rule();
		if ( ! $shipping_data ) {
			return;
		}

		$rule = $shipping_data['rule'];

		if ( 'asap' === $rule['type'] ) {
			// Calculate both dates server-side (no user input, fully secure)
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
			
			if ( ! empty( $dates['ship_by_date'] ) ) {
				$order->update_meta_data( 'ship_by_date', $dates['ship_by_date'] );
			}
			if ( ! empty( $dates['deliver_by_date'] ) ) {
				$order->update_meta_data( 'deliver_by_date', $dates['deliver_by_date'] );
			}
		} elseif ( 'by_date' === $rule['type'] ) {
			// User selects deliver_by_date, ship_by_date equals deliver_by_date
			if ( ! empty( $_POST['deliver_by_date'] ) ) {
				$deliver_by_date = sanitize_text_field( wp_unslash( $_POST['deliver_by_date'] ) );
				$order->update_meta_data( 'deliver_by_date', $deliver_by_date );
				// For reservation-based methods, ship_by_date equals deliver_by_date
				$order->update_meta_data( 'ship_by_date', $deliver_by_date );
			}
		}
	}
}

