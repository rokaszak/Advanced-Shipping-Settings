<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom shipping method for Pickup Locations.
 * Each location configured in admin becomes an instance of this method.
 */
class Pickup_Shipping_Method extends \WC_Shipping_Method {

	/** @var string Pickup location details (for Ready for Pickup email). */
	public $description = '';

	/** @var string|float Pickup fee. */
	public $fee = 0;

	/**
	 * Constructor.
	 */
	public function __construct( $instance_id = 0, $method_id = '', $title = '' ) {
		$this->id                 = $method_id ? $method_id : 'ass_pickup';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = $title ? $title : __( 'Pickup', 'advanced-shipping-settings' );
		$this->method_description = __( 'Custom pickup location created via Advanced Shipping Settings.', 'advanced-shipping-settings' );
		$this->supports           = [ 'shipping-zones', 'instance-settings', 'instance-settings-modal' ];

		$this->init();
	}

	/**
	 * Initialize settings.
	 */
	public function init() {
		// Instance form fields: used by WooCommerce for the zone method modal and for saving.
		$this->instance_form_fields = [
			'enabled' => [
				'title'   => __( 'Enable/Disable', 'advanced-shipping-settings' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this pickup location', 'advanced-shipping-settings' ),
				'default' => 'yes',
			],
			'title' => [
				'title'       => __( 'Title', 'advanced-shipping-settings' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'advanced-shipping-settings' ),
				'default'     => $this->method_title,
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Pickup location details', 'advanced-shipping-settings' ),
				'type'        => 'textarea',
				'description' => __( 'Details and instructions for this pickup location. Shown in the "Ready for Pickup" email to the customer.', 'advanced-shipping-settings' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'fee' => [
				'title'       => __( 'Pickup fee', 'advanced-shipping-settings' ),
				'type'        => 'price',
				'description' => __( 'Fee for this pickup option. Leave 0 for free pickup.', 'advanced-shipping-settings' ),
				'default'     => '0',
				'placeholder' => wc_format_localized_price( 0 ),
				'desc_tip'    => true,
			],
			'min_amount_for_free_shipping' => [
				'title'       => __( 'Minimum order amount for free shipping', 'advanced-shipping-settings' ),
				'type'        => 'price',
				'description' => __( 'Orders at or above this amount get free pickup (if a fee is set above). Leave 0 to disable.', 'advanced-shipping-settings' ),
				'default'     => '0',
				'placeholder' => wc_format_localized_price( 0 ),
				'desc_tip'    => true,
			],
		];

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled     = $this->get_option( 'enabled' );
		$this->title       = $this->get_option( 'title', $this->method_title );
		$this->fee         = $this->get_option( 'fee', 0 );
		$this->description = $this->get_option( 'description', '' );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	/**
	 * Init form fields. Use same as instance form fields so init_settings/get_option work.
	 */
	public function init_form_fields() {
		$this->form_fields = $this->instance_form_fields;
	}

	/**
	 * Calculate shipping.
	 */
	public function calculate_shipping( $package = [] ) {
		$shipping_tax_class = get_option( 'woocommerce_shipping_tax_class' );
		$fee                 = (float) $this->get_option( 'fee', 0 );

		$rate = [
			'id'        => $this->id,
			'label'     => $this->title,
			'cost'      => $fee,
			'package'   => $package,
			'tax_class' => $shipping_tax_class,
		];

		$this->free_shipping_check( $package, $rate );
		$this->add_rate( $rate );
	}

	/**
	 * Apply free shipping when cart meets minimum amount or a free-shipping coupon is applied.
	 *
	 * @param array $package Package data.
	 * @param array $rate    Rate array (passed by reference; cost may be set to 0).
	 */
	public function free_shipping_check( $package, &$rate ) {
		$min_amount = (float) $this->get_option( 'min_amount_for_free_shipping', 0 );

		if ( $min_amount > 0 && WC()->cart ) {
			$order_cost = WC()->cart->get_cart_contents_total() + WC()->cart->get_cart_contents_tax();
			if ( $order_cost >= $min_amount ) {
				$rate['cost'] = 0;
			}
		}

		if ( WC()->cart ) {
			$applied_coupons = WC()->cart->get_applied_coupons();
			foreach ( $applied_coupons as $coupon_code ) {
				$coupon = new \WC_Coupon( $coupon_code );
				if ( $coupon->get_free_shipping() ) {
					$rate['cost'] = 0;
					break;
				}
			}
		}
	}
}

