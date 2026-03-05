<?php
/**
 * Customer "Ready for Pickup" order email (plain text)
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/ass-ready-for-pickup.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the release of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package Advanced Shipping Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$greeting_with_name    = $email->get_option( 'greeting_with_name', __( 'Hi %s,', 'advanced-shipping-settings' ) );
$greeting_without_name = $email->get_option( 'greeting_without_name', __( 'Hi,', 'advanced-shipping-settings' ) );
$ready_at_line         = $email->get_option( 'ready_at_line', __( 'Your order #%1$s is ready for pickup at %2$s.', 'advanced-shipping-settings' ) );
$fallback_location     = $email->get_option( 'fallback_location_name', __( 'your chosen location', 'advanced-shipping-settings' ) );
$method_info_intro      = $email->get_option( 'method_info_intro', __( 'Some information about the method:', 'advanced-shipping-settings' ) );
$thanks_line           = $email->get_option( 'thanks_line', __( 'Thanks!', 'advanced-shipping-settings' ) );
$location_display      = $pickup_location ? $pickup_location : $fallback_location;

echo '= ' . esc_html( $email_heading ) . " =\n\n";

if ( ! empty( $order->get_billing_first_name() ) ) {
	echo sprintf( esc_html( $greeting_with_name ), esc_html( $order->get_billing_first_name() ) ) . "\n\n";
} else {
	echo esc_html( $greeting_without_name ) . "\n\n";
}

echo esc_html( sprintf( $ready_at_line, $order->get_order_number(), $location_display ) ) . "\n\n";

if ( ! empty( $pickup_description ) ) {
	if ( ! empty( $method_info_intro ) ) {
		echo esc_html( $method_info_intro ) . "\n\n";
	}
	echo wp_strip_all_tags( $pickup_description ) . "\n\n";
}

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::customer_details() Shows customer details
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo "\n" . wp_strip_all_tags( wpautop( wptexturize( $additional_content ) ) ) . "\n";
}

echo "\n" . esc_html( $thanks_line ) . "\n\n";

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo esc_html( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
