<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Email_Ready_For_Pickup extends \WC_Email {

	public function __construct() {
		$this->id             = 'ass_ready_for_pickup';
		$this->customer_email = true;
		$this->title          = __( 'Ready for Pickup', 'advanced-shipping-settings' );
		$this->description    = __( 'Ready for Pickup emails are sent to customers when an order is marked ready for pickup.', 'advanced-shipping-settings' );
		$this->template_html  = 'emails/ass-ready-for-pickup.php';
		$this->template_plain = 'emails/plain/ass-ready-for-pickup.php';
		$this->template_base  = ASS_PATH . 'templates/';
		$this->placeholders   = [
			'{order_number}'        => '',
			'{order_date}'          => '',
			'{customer_first_name}' => '',
			'{customer_last_name}'  => '',
			'{pickup_location}'     => '',
			'{pickup_description}'  => '',
			'{pickup_code}'         => '',
		];

		parent::__construct();
	}

	public static function default_code_label(): string {
		return Email_Ready_For_Pickup_Texts::default_code_label();
	}

	public static function default_code_hint(): string {
		return Email_Ready_For_Pickup_Texts::default_code_hint();
	}

	public static function default_point_line(): string {
		return Email_Ready_For_Pickup_Texts::default_point_line();
	}

	public function init_form_fields() {
		parent::init_form_fields();
		$this->form_fields = array_merge(
			$this->form_fields,
			[
				'greeting_with_name'     => [
					'title'       => __( 'Greeting (with name)', 'advanced-shipping-settings' ),
					'type'        => 'text',
					'description' => __( 'Used when the customer has a first name. Use %s as placeholder for the first name.', 'advanced-shipping-settings' ),
					'default'     => __( 'Hi %s,', 'advanced-shipping-settings' ),
					'desc_tip'    => true,
				],
				'greeting_without_name'  => [
					'title'       => __( 'Greeting (without name)', 'advanced-shipping-settings' ),
					'type'        => 'text',
					'description' => __( 'Used when the customer has no first name.', 'advanced-shipping-settings' ),
					'default'     => __( 'Hi,', 'advanced-shipping-settings' ),
					'desc_tip'    => true,
				],
				'ready_at_line'          => [
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
				'pickup_code_label'      => [
					'title'       => __( 'Pickup code label', 'advanced-shipping-settings' ),
					'type'        => 'text',
					'description' => __( 'Shown above the pickup code. Only orders for your own pickup locations get a code.', 'advanced-shipping-settings' ),
					'default'     => self::default_code_label(),
					'desc_tip'    => true,
				],
				'pickup_code_hint'       => [
					'title'       => __( 'Pickup code hint', 'advanced-shipping-settings' ),
					'type'        => 'text',
					'description' => __( 'Shown below the QR code. Leave empty to hide.', 'advanced-shipping-settings' ),
					'default'     => self::default_code_hint(),
					'desc_tip'    => true,
				],
				'pickup_point_line'      => [
					'title'       => __( 'Pickup point line', 'advanced-shipping-settings' ),
					'type'        => 'text',
					'description' => __( 'Shown for orders shipped to an external pickup point (for example a bus station). Use %s for the pickup point name. Leave empty to hide.', 'advanced-shipping-settings' ),
					'default'     => self::default_point_line(),
					'desc_tip'    => true,
				],
				'method_info_intro'      => [
					'title'       => __( 'Method info intro', 'advanced-shipping-settings' ),
					'type'        => 'text',
					'description' => __( 'Optional line before the pickup location description (address, hours, etc.). Leave empty to hide.', 'advanced-shipping-settings' ),
					'default'     => __( 'Some information about the method:', 'advanced-shipping-settings' ),
					'desc_tip'    => true,
				],
				'order_reminder_line'    => [
					'title'       => __( 'Order reminder line', 'advanced-shipping-settings' ),
					'type'        => 'text',
					'description' => __( 'Shown before the order details table (when email improvements are enabled).', 'advanced-shipping-settings' ),
					'default'     => __( "Here's a reminder of what you've ordered:", 'advanced-shipping-settings' ),
					'desc_tip'    => true,
				],
				'thanks_line'            => [
					'title'       => __( 'Thanks line', 'advanced-shipping-settings' ),
					'type'        => 'text',
					'description' => __( 'Shown at the end of the plain-text email.', 'advanced-shipping-settings' ),
					'default'     => __( 'Thanks!', 'advanced-shipping-settings' ),
					'desc_tip'    => true,
				],
			]
		);
	}

	public function get_default_subject() {
		return __( 'Your {site_title} order #{order_number} is ready for pickup', 'advanced-shipping-settings' );
	}

	public function get_default_heading() {
		return __( 'Order #{order_number} is ready for pickup', 'advanced-shipping-settings' );
	}

	public function get_default_additional_content() {
		return __( 'Thanks for shopping with us.', 'advanced-shipping-settings' );
	}

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
		$this->placeholders['{order_date}']          = wc_format_datetime( $this->object->get_date_created() );
		$this->placeholders['{customer_first_name}'] = $this->object->get_billing_first_name();
		$this->placeholders['{customer_last_name}']  = $this->object->get_billing_last_name();

		$details = $this->get_pickup_details( $this->object );

		$this->placeholders['{pickup_location}']    = $details['location'];
		$this->placeholders['{pickup_description}'] = $details['description'];
		$this->placeholders['{pickup_code}']        = $details['code'];

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
	}

	protected function get_pickup_details( \WC_Order $order ): array {
		$details = [
			'location'    => '',
			'description' => '',
			'code'        => '',
			'qr_url'      => '',
			'point'       => '',
		];

		$item = Pickup_Helper::get_shipping_item( $order );
		if ( ! $item ) {
			return $details;
		}

		if ( Pickup_Helper::uses_own_pickup( $order ) ) {
			$settings               = get_option( 'woocommerce_' . $item->get_method_id() . '_' . $item->get_instance_id() . '_settings', [] );
			$details['location']    = (string) $item->get_name();
			$details['description'] = is_array( $settings ) && isset( $settings['description'] ) ? (string) $settings['description'] : '';
			$details['code']        = Pickup_Code::get_code( $order );
			$details['qr_url']      = Pickup_Code::qr_url( $order );
		} else {
			$details['point'] = Pickup_Helper::get_pickup_point( $order );
		}

		return $details;
	}

	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			$this->get_template_args(),
			'advanced-shipping-settings/',
			$this->template_base
		);
	}

	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			$this->get_template_args( true ),
			'advanced-shipping-settings/',
			$this->template_base
		);
	}

	protected function get_template_args( $plain_text = false ) {
		$is_preview = (bool) apply_filters( 'woocommerce_is_email_preview', false );
		$details    = ! $is_preview && is_a( $this->object, 'WC_Order' ) ? $this->get_pickup_details( $this->object ) : $this->get_pickup_details_preview();

		return [
			'order'              => $this->object,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain_text,
			'email'              => $this,
			'pickup_location'    => $details['location'],
			'pickup_description' => $details['description'],
			'pickup_code'        => $details['code'],
			'pickup_qr_url'      => $details['qr_url'],
			'pickup_point'       => $details['point'],
		];
	}

	protected function get_pickup_details_preview(): array {
		return [
			'location'    => '',
			'description' => '',
			'code'        => '1234',
			'qr_url'      => '',
			'point'       => '',
		];
	}
}

return new Email_Ready_For_Pickup();
