<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$email_improvements_enabled = class_exists( 'Automattic\WooCommerce\Utilities\FeaturesUtil' )
	&& \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'email_improvements' );

do_action( 'woocommerce_email_header', $email_heading, $email );

$greeting_with_name    = $email->get_option( 'greeting_with_name', __( 'Hi %s,', 'advanced-shipping-settings' ) );
$greeting_without_name = $email->get_option( 'greeting_without_name', __( 'Hi,', 'advanced-shipping-settings' ) );
$ready_at_line         = $email->get_option( 'ready_at_line', __( 'Your order #%1$s is ready for pickup at %2$s.', 'advanced-shipping-settings' ) );
$fallback_location     = $email->get_option( 'fallback_location_name', __( 'your chosen location', 'advanced-shipping-settings' ) );
$method_info_intro     = $email->get_option( 'method_info_intro' );
$order_reminder_line   = $email->get_option( 'order_reminder_line', __( "Here's a reminder of what you've ordered:", 'advanced-shipping-settings' ) );
$pickup_code_label     = $email->get_option( 'pickup_code_label' );
$pickup_code_hint      = $email->get_option( 'pickup_code_hint' );
$pickup_point_line     = $email->get_option( 'pickup_point_line' );
$location_display      = $pickup_location ? $pickup_location : $fallback_location;
$pickup_code           = isset( $pickup_code ) ? (string) $pickup_code : '';
$pickup_qr_url         = isset( $pickup_qr_url ) ? (string) $pickup_qr_url : '';
$pickup_point          = isset( $pickup_point ) ? (string) $pickup_point : '';
?>
<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<p>
<?php
if ( ! empty( $order->get_billing_first_name() ) ) {
	echo esc_html( \ASS\Email_Ready_For_Pickup_Texts::format( (string) $greeting_with_name, $order->get_billing_first_name() ) );
} else {
	echo esc_html( $greeting_without_name );
}
?>
</p>
<p><?php echo esc_html( \ASS\Email_Ready_For_Pickup_Texts::format( (string) $ready_at_line, $order->get_order_number(), $location_display ) ); ?></p>
<?php if ( '' !== $pickup_point && '' !== trim( (string) $pickup_point_line ) ) : ?>
	<p><strong><?php echo esc_html( \ASS\Email_Ready_For_Pickup_Texts::format( (string) $pickup_point_line, $pickup_point ) ); ?></strong></p>
<?php endif; ?>
<?php if ( '' !== $pickup_code ) : ?>
	<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation" style="margin: 8px 0 24px;">
		<tr>
			<td align="center" bgcolor="#ffffff" style="background-color: #ffffff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 24px 16px; text-align: center;">
				<?php if ( '' !== trim( (string) $pickup_code_label ) ) : ?>
					<p style="margin: 0 0 8px; font-size: 14px; line-height: 1.4; color: #636363; text-align: center;"><?php echo esc_html( $pickup_code_label ); ?></p>
				<?php endif; ?>
				<p style="margin: 0 0 16px; padding-left: 8px; font-size: 44px; line-height: 1.1; font-weight: bold; letter-spacing: 8px; color: #1e1e1e; text-align: center;"><?php echo esc_html( $pickup_code ); ?></p>
				<?php if ( '' !== $pickup_qr_url ) : ?>
					<img src="<?php echo esc_url( $pickup_qr_url ); ?>" width="200" height="200" alt="<?php echo esc_attr( $pickup_code ); ?>" style="display: block; width: 200px; height: 200px; margin: 0 auto 12px; border: 0; outline: none; background-color: #ffffff;">
				<?php endif; ?>
				<?php if ( '' !== trim( (string) $pickup_code_hint ) ) : ?>
					<p style="margin: 0; font-size: 14px; line-height: 1.4; color: #636363; text-align: center;"><?php echo esc_html( $pickup_code_hint ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
	</table>
<?php endif; ?>
<?php if ( ! empty( $pickup_description ) ) : ?>
	<?php if ( ! empty( $method_info_intro ) ) : ?>
		<p><?php echo esc_html( $method_info_intro ); ?></p>
	<?php endif; ?>
	<p><?php echo wp_kses_post( wpautop( make_clickable( wptexturize( $pickup_description ) ) ) ); ?></p>
<?php endif; ?>
<?php if ( $email_improvements_enabled ) : ?>
	<p><?php echo esc_html( $order_reminder_line ); ?></p>
<?php endif; ?>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}

do_action( 'woocommerce_email_footer', $email );
