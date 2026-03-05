<?php
/**
 * Customer "Ready for Pickup" order email
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/ass-ready-for-pickup.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package Advanced Shipping Settings
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$email_improvements_enabled = class_exists( 'Automattic\WooCommerce\Utilities\FeaturesUtil' )
	&& \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'email_improvements' );

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email );

$greeting_with_name     = $email->get_option( 'greeting_with_name', __( 'Hi %s,', 'advanced-shipping-settings' ) );
$greeting_without_name  = $email->get_option( 'greeting_without_name', __( 'Hi,', 'advanced-shipping-settings' ) );
$ready_at_line          = $email->get_option( 'ready_at_line', __( 'Your order #%1$s is ready for pickup at %2$s.', 'advanced-shipping-settings' ) );
$fallback_location      = $email->get_option( 'fallback_location_name', __( 'your chosen location', 'advanced-shipping-settings' ) );
$method_info_intro      = $email->get_option( 'method_info_intro', __( 'Some information about the method:', 'advanced-shipping-settings' ) );
$order_reminder_line    = $email->get_option( 'order_reminder_line', __( "Here's a reminder of what you've ordered:", 'advanced-shipping-settings' ) );
$location_display       = $pickup_location ? $pickup_location : $fallback_location;
?>
<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<p>
<?php
if ( ! empty( $order->get_billing_first_name() ) ) {
	printf( esc_html( $greeting_with_name ), esc_html( $order->get_billing_first_name() ) );
} else {
	echo esc_html( $greeting_without_name );
}
?>
</p>
<?php if ( $email_improvements_enabled ) : ?>
	<p><?php echo esc_html( sprintf( $ready_at_line, $order->get_order_number(), $location_display ) ); ?></p>
	<?php if ( ! empty( $pickup_description ) ) : ?>
		<?php if ( ! empty( $method_info_intro ) ) : ?>
			<p><?php echo esc_html( $method_info_intro ); ?></p>
		<?php endif; ?>
		<p><?php echo wp_kses_post( wpautop( wptexturize( $pickup_description ) ) ); ?></p>
	<?php endif; ?>
	<p><?php echo esc_html( $order_reminder_line ); ?></p>
<?php else : ?>
	<p><?php echo esc_html( sprintf( $ready_at_line, $order->get_order_number(), $location_display ) ); ?></p>
	<?php if ( ! empty( $pickup_description ) ) : ?>
		<?php if ( ! empty( $method_info_intro ) ) : ?>
			<p><?php echo esc_html( $method_info_intro ); ?></p>
		<?php endif; ?>
		<p><?php echo wp_kses_post( wpautop( wptexturize( $pickup_description ) ) ); ?></p>
	<?php endif; ?>
<?php endif; ?>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php
/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 * @since 2.5.0
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}

/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );
