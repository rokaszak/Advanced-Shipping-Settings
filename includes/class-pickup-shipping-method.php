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

	/**
	 * Constructor.
	 */
	public function __construct( $instance_id = 0, $method_id = 'ass_pickup', $title = 'Pickup' ) {
		$this->id                 = $method_id;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = $title;
		$this->method_description = __( 'Custom pickup location created via Advanced Shipping Settings.', 'advanced-shipping-settings' );
		$this->supports           = [ 'shipping-zones', 'instance-settings' ];

		$this->init();
	}

	/**
	 * Initialize settings.
	 */
	public function init() {
		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables.
		$this->enabled = $this->get_option( 'enabled' );
		$this->title   = $this->get_option( 'title', $this->method_title );

		// Actions.
		add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	/**
	 * Init form fields.
	 */
	public function init_form_fields() {
		$this->form_fields = [
			'enabled' => [
				'title'   => __( 'Enable/Disable', 'advanced-shipping-settings' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this pickup location', 'advanced-shipping-settings' ),
				'default' => 'yes',
			],
			'title'   => [
				'title'       => __( 'Method Title', 'advanced-shipping-settings' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'advanced-shipping-settings' ),
				'default'     => $this->method_title,
				'desc_tip'    => true,
			],
		];
	}

	/**
	 * Calculate shipping.
	 */
	public function calculate_shipping( $package = [] ) {
		$this->add_rate( [
			'id'    => $this->id,
			'label' => $this->title,
			'cost'  => 0,
		] );
	}
}

