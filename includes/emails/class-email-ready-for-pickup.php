<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Ready for Pickup" customer email.
 *
 * @extends \WC_Email
 */
class Email_Ready_For_Pickup extends \WC_Email {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'ass_ready_for_pickup';
		$this->customer_email = true;
		$this->title          = __( 'Ready for Pickup', 'advanced-shipping-settings' );
		$this->description   = __( 'Ready for Pickup emails are sent to customers when an order is marked ready for pickup.', 'advanced-shipping-settings' );
		$this->template_html  = 'emails/ass-ready-for-pickup.php';
		$this->template_plain = 'emails/plain/ass-ready-for-pickup.php';
		$this->template_base  = ASS_PATH . 'templates/';
		$this->placeholders   = [
			'{order_number}'         => '',
			'{order_date}'           => '',
			'{customer_first_name}'  => '',
			'{customer_last_name}'   => '',
			'{pickup_location}'     => '',
			'{pickup_description}'  => '',
		];

		parent::__construct();
	}

	/**
	 * Initialize form fields for editable email strings (WooCommerce > Settings > Emails > Ready for Pickup).
	 */
	public function init_form_fields() {
		parent::init_form_fields();
		$this->form_fields = array_merge(
			$this->form_fields,
			[
				'greeting_with_name'    => [
					'title'       => __( 'Greeting (with name)', 'advanced-shipping-settings' ),
					'type'        => 'text',
					'description' => __( 'Used when the customer has a first name. Use %s as placeholder for the first name.', 'advanced-shipping-settings' ),
					'default'     => __( 'Hi %s,', 'advanced-shipping-settings' ),
					'desc_tip'    => true,
				],
				'greeting_without_name' => [
					'title'       => __( 'Greeting (without name)', 'advanced-shipping-settings' ),
					'type'        => 'text',
					'description' => __( 'Used when the customer has no first name.', 'advanced-shipping-settings' ),
					'default'     => __( 'Hi,', 'advanced-shipping-settings' ),
					'desc_tip'    => true,
				],
				'ready_at_line'         => [
					'title'       => __( 'Ready for pickup line', 'advanced-shipping-settings' ),
					'type'        => 'text',
					'description' => __( 'Use %1$s for order number, %2$s for pickup location name.', 'advanced-shipping-settings' ),
					'default'     => __( 'Your order #%1$s is ready for pickup at %2$s.', 'advanced-shipping-settings' ),
					'desc_tip'    => true,
				],
				'fallback_location_name' => [
					'title'       => __( 'Fallback location name', 'advanced-shipping-settings' ),
					'type'        => 'text',
					'description' => __( 'Shown when the pickup location name is not available.', 'advanced-shipping-settings' ),
					'default'     => __( 'your chosen location', 'advanced-shipping-settings' ),
					'desc_tip'    => true,
				],
				'method_info_intro'     => [
					'title'       => __( 'Method info intro', 'advanced-shipping-settings' ),
					'type'        => 'text',
					'description' => __( 'Optional line before the pickup location description (address, hours, etc.). Leave empty to hide.', 'advanced-shipping-settings' ),
					'default'     => __( 'Some information about the method:', 'advanced-shipping-settings' ),
					'desc_tip'    => true,
				],
				'order_reminder_line'   => [
					'title'       => __( 'Order reminder line', 'advanced-shipping-settings' ),
					'type'        => 'text',
					'description' => __( 'Shown before the order details table (when email improvements are enabled).', 'advanced-shipping-settings' ),
					'default'     => __( "Here's a reminder of what you've ordered:", 'advanced-shipping-settings' ),
					'desc_tip'    => true,
				],
				'thanks_line'           => [
					'title'       => __( 'Thanks line', 'advanced-shipping-settings' ),
					'type'        => 'text',
					'description' => __( 'Shown at the end of the plain-text email.', 'advanced-shipping-settings' ),
					'default'     => __( 'Thanks!', 'advanced-shipping-settings' ),
					'desc_tip'    => true,
				],
			]
		);
	}

	/**
	 * Get default email subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your {site_title} order #{order_number} is ready for pickup', 'advanced-shipping-settings' );
	}

	/**
	 * Get default email heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Order #{order_number} is ready for pickup', 'advanced-shipping-settings' );
	}

	/**
	 * Get default additional content.
	 *
	 * @return string
	 */
	public function get_default_additional_content() {
		return __( 'Thanks for shopping with us.', 'advanced-shipping-settings' );
	}

	/**
	 * Trigger the email.
	 *
	 * @param int|null    $order_id Order ID.
	 * @param \WC_Order|null $order   Order object.
	 */
	public function trigger( $order_id = null, $order = null ) {
		$this->setup_locale();

		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! is_a( $order, 'WC_Order' ) ) {
			$this->restore_locale();
			return;
		}

		$this->object    = $order;
		$this->recipient = $this->object->get_billing_email();

		$this->placeholders['{order_number}']        = $this->object->get_order_number();
		$this->placeholders['{order_date}']         = wc_format_datetime( $this->object->get_date_created() );
		$this->placeholders['{customer_first_name}'] = $this->object->get_billing_first_name();
		$this->placeholders['{customer_last_name}']  = $this->object->get_billing_last_name();

		$pickup_location  = '';
		$pickup_description = '';

		$pickup_locations = Settings_Manager::instance()->get_pickup_locations();
		$pickup_method_ids = [];
		foreach ( $pickup_locations as $loc ) {
			if ( ! empty( $loc['method_id'] ) ) {
				$pickup_method_ids[] = $loc['method_id'];
			}
		}

		foreach ( $this->object->get_items( 'shipping' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Shipping ) {
				continue;
			}
			$method_id = $item->get_method_id();
			if ( in_array( $method_id, $pickup_method_ids, true ) ) {
				$pickup_location = $item->get_name();
				$instance_id    = $item->get_instance_id();
				$option_key     = 'woocommerce_' . $method_id . '_' . $instance_id . '_settings';
				$settings       = get_option( $option_key, [] );
				$pickup_description = isset( $settings['description'] ) ? $settings['description'] : '';
				break;
			}
		}

		$this->placeholders['{pickup_location}']   = $pickup_location;
		$this->placeholders['{pickup_description}'] = $pickup_description;

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
	}

	/**
	 * Get content html.
	 *
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			$this->get_template_args(),
			'advanced-shipping-settings/',
			$this->template_base
		);
	}

	/**
	 * Get content plain.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			$this->get_template_args( true ),
			'advanced-shipping-settings/',
			$this->template_base
		);
	}

	/**
	 * Get template args for the email template.
	 *
	 * @param bool $plain_text Whether this is the plain-text.
	 * @return array
	 */
	protected function get_template_args( $plain_text = false ) {
		$pickup_location    = $this->placeholders['{pickup_location}'];
		$pickup_description = $this->placeholders['{pickup_description}'];

		return [
			'order'              => $this->object,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain_text,
			'email'              => $this,
			'pickup_location'    => $pickup_location,
			'pickup_description' => $pickup_description,
		];
	}
}

return new Email_Ready_For_Pickup();
