<?php
namespace ASS;

use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Save ASAP/reservation dates to order meta (HPOS compatible).
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
		$shipping_methods = $order->get_shipping_methods();
		if ( empty( $shipping_methods ) ) {
			return;
		}

		// WC_Order_Item_Shipping
		$shipping_method = reset( $shipping_methods );
		
		$method_id = $shipping_method->get_method_id() . ':' . $shipping_method->get_instance_id();
		
		$rules = Settings_Manager::instance()->get_shipping_rules();
		$rule  = $rules[ $method_id ] ?? null;

		if ( ! $rule ) {
			return;
		}

		if ( 'asap' === $rule['type'] ) {
			if ( ! empty( $_POST['ass_calculated_asap_date'] ) ) {
				$asap_date = sanitize_text_field( wp_unslash( $_POST['ass_calculated_asap_date'] ) );
				$order->update_meta_data( 'asap_date', $asap_date );
			} else {
				// Fallback calculation if hidden field missing for some reason.
				$sending_days  = $rule['sending_days'] ?? [];
				$max_ship_days = $rule['max_ship_days'] ?? 0;
				$holidays      = Settings_Manager::instance()->get_holiday_dates();
				$asap_date     = Date_Calculator::instance()->calculate_asap_date( $sending_days, $max_ship_days, $holidays );
				if ( $asap_date ) {
					$order->update_meta_data( 'asap_date', $asap_date );
				}
			}
		} elseif ( 'by_date' === $rule['type'] ) {
			if ( ! empty( $_POST['reservation_date'] ) ) {
				$reservation_date = sanitize_text_field( wp_unslash( $_POST['reservation_date'] ) );
				$order->update_meta_data( 'reservation_date', $reservation_date );
			}
		}
	}
}

