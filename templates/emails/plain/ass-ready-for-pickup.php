<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$greeting_with_name    = $email->get_option( 'greeting_with_name', __( 'Hi %s,', 'advanced-shipping-settings' ) );
$greeting_without_name = $email->get_option( 'greeting_without_name', __( 'Hi,', 'advanced-shipping-settings' ) );
$ready_at_line         = $email->get_option( 'ready_at_line', __( 'Your order #%1$s is ready for pickup at %2$s.', 'advanced-shipping-settings' ) );
$fallback_location     = $email->get_option( 'fallback_location_name', __( 'your chosen location', 'advanced-shipping-settings' ) );
$method_info_intro     = $email->get_option( 'method_info_intro' );
$thanks_line           = $email->get_option( 'thanks_line', __( 'Thanks!', 'advanced-shipping-settings' ) );
$pickup_code_label     = $email->get_option( 'pickup_code_label' );
$pickup_code_hint      = $email->get_option( 'pickup_code_hint' );
$pickup_point_line     = $email->get_option( 'pickup_point_line' );
$location_display      = $pickup_location ? $pickup_location : $fallback_location;
$pickup_code           = isset( $pickup_code ) ? (string) $pickup_code : '';
$pickup_point          = isset( $pickup_point ) ? (string) $pickup_point : '';

echo '= ' . esc_html( $email_heading ) . " =\n\n";

if ( ! empty( $order->get_billing_first_name() ) ) {
	echo esc_html( \ASS\Email_Ready_For_Pickup_Texts::format( (string) $greeting_with_name, $order->get_billing_first_name() ) ) . "\n\n";
} else {
	echo esc_html( $greeting_without_name ) . "\n\n";
}

echo esc_html( \ASS\Email_Ready_For_Pickup_Texts::format( (string) $ready_at_line, $order->get_order_number(), $location_display ) ) . "\n\n";

if ( '' !== $pickup_point && '' !== trim( (string) $pickup_point_line ) ) {
	echo esc_html( \ASS\Email_Ready_For_Pickup_Texts::format( (string) $pickup_point_line, $pickup_point ) ) . "\n\n";
}

if ( '' !== $pickup_code ) {
	$code_label = trim( (string) $pickup_code_label );
	echo esc_html( '' !== $code_label ? $code_label . ': ' . $pickup_code : $pickup_code ) . "\n";
	if ( '' !== trim( (string) $pickup_code_hint ) ) {
		echo esc_html( $pickup_code_hint ) . "\n";
	}
	echo "\n";
}

if ( ! empty( $pickup_description ) ) {
	if ( ! empty( $method_info_intro ) ) {
		echo esc_html( $method_info_intro ) . "\n\n";
	}
	echo wp_strip_all_tags( $pickup_description ) . "\n\n";
}

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo "\n" . wp_strip_all_tags( wpautop( wptexturize( $additional_content ) ) ) . "\n";
}

echo "\n" . esc_html( $thanks_line ) . "\n\n";

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo esc_html( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
