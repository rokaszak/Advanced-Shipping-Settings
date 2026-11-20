<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pickup_Sms {

	const OPTION          = 'ass_pickup_settings';
	const META_STATUS     = '_ass_pickup_sms_status';
	const META_LOG        = '_ass_pickup_sms_log';
	const STATUS_NOT_SENT = 'not_sent';
	const STATUS_SENT     = 'sent';
	const STATUS_FAILED   = 'failed';
	const STATUS_NO_PHONE = 'no_phone';
	const GROUP_CODE      = 'code';
	const GROUP_PLAIN     = 'plain';
	const TEMPLATE_LIMIT  = 600;
	const LOG_LIMIT       = 10;

	public static function default_template( string $group ): string {
		if ( self::GROUP_CODE === $group ) {
			return Pickup_Helper::default_text(
				'Order #{order_number} is ready for pickup: {pickup_point}. Pickup code: {pickup_code}',
				'Užsakymas #{order_number} paruoštas atsiimti: {pickup_point}. Atsiėmimo kodas: {pickup_code}'
			);
		}
		return Pickup_Helper::default_text(
			'Order #{order_number} is ready for pickup. {shipping_method}: {pickup_point}',
			'Užsakymas #{order_number} paruoštas atsiimti. {shipping_method}: {pickup_point}'
		);
	}

	public static function get_settings(): array {
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		return [
			'sms_enabled'     => ! empty( $stored['sms_enabled'] ),
			'bulkgate_app_id' => isset( $stored['bulkgate_app_id'] ) ? preg_replace( '/\D+/', '', (string) $stored['bulkgate_app_id'] ) : '',
			'bulkgate_token'  => isset( $stored['bulkgate_token'] ) ? (string) $stored['bulkgate_token'] : '',
			'sms_sender'      => isset( $stored['sms_sender'] ) ? (string) $stored['sms_sender'] : '',
			'template_code'   => isset( $stored['template_code'] ) && '' !== trim( (string) $stored['template_code'] ) ? (string) $stored['template_code'] : self::default_template( self::GROUP_CODE ),
			'template_plain'  => isset( $stored['template_plain'] ) && '' !== trim( (string) $stored['template_plain'] ) ? (string) $stored['template_plain'] : self::default_template( self::GROUP_PLAIN ),
		];
	}

	public static function save_settings( array $settings ): void {
		update_option( self::OPTION, $settings, false );
	}

	public static function sanitize_template( $template ): string {
		$template = is_scalar( $template ) ? sanitize_textarea_field( (string) $template ) : '';
		$template = trim( preg_replace( '/\s+/', ' ', $template ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $template, 0, self::TEMPLATE_LIMIT ) : substr( $template, 0, self::TEMPLATE_LIMIT );
	}

	public static function sanitize_sender( $sender ): string {
		$sender = is_scalar( $sender ) ? preg_replace( '/[^A-Za-z0-9 ]+/', '', remove_accents( (string) $sender ) ) : '';
		return substr( trim( $sender ), 0, 11 );
	}

	private static function secret_key(): string {
		return hash( 'sha256', 'ass_bulkgate_token|' . wp_salt( 'auth' ), true );
	}

	public static function can_encrypt(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) && defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' );
	}

	public static function encrypt( string $plain ): string {
		if ( '' === $plain || ! self::can_encrypt() ) {
			return '';
		}
		try {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::secret_key() ) );
		} catch ( \Throwable $error ) {
			return '';
		}
	}

	public static function decrypt( string $stored ): string {
		if ( 0 !== strpos( $stored, 'v1:' ) || ! self::can_encrypt() ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 3 ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		try {
			$plain = sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				self::secret_key()
			);
		} catch ( \Throwable $error ) {
			return '';
		}
		return false === $plain ? '' : (string) $plain;
	}

	public static function credentials(): array {
		$settings = self::get_settings();

		$id_from_constant    = defined( 'ASS_BULKGATE_APPLICATION_ID' ) && '' !== (string) ASS_BULKGATE_APPLICATION_ID;
		$token_from_constant = defined( 'ASS_BULKGATE_APPLICATION_TOKEN' ) && '' !== (string) ASS_BULKGATE_APPLICATION_TOKEN;

		$application_id = $id_from_constant ? (int) ASS_BULKGATE_APPLICATION_ID : (int) $settings['bulkgate_app_id'];
		$token          = $token_from_constant ? (string) ASS_BULKGATE_APPLICATION_TOKEN : self::decrypt( $settings['bulkgate_token'] );

		return [
			'application_id'      => $application_id,
			'token'               => $token,
			'sender'              => self::sanitize_sender( $settings['sms_sender'] ),
			'id_from_constant'    => $id_from_constant,
			'token_from_constant' => $token_from_constant,
			'token_stored'        => '' !== $settings['bulkgate_token'],
			'token_unreadable'    => ! $token_from_constant && '' !== $settings['bulkgate_token'] && '' === $token,
		];
	}

	public static function is_configured(): bool {
		$settings    = self::get_settings();
		$credentials = self::credentials();
		return $settings['sms_enabled'] && $credentials['application_id'] > 0 && '' !== $credentials['token'];
	}

	public static function client(): Bulkgate_Client {
		$credentials = self::credentials();
		return new Bulkgate_Client( $credentials['application_id'], $credentials['token'], $credentials['sender'] );
	}

	public static function variable_labels(): array {
		return [
			'order_number'    => __( 'Order number', 'advanced-shipping-settings' ),
			'pickup_code'     => __( 'Pickup code', 'advanced-shipping-settings' ),
			'first_name'      => __( 'First name', 'advanced-shipping-settings' ),
			'last_name'       => __( 'Last name', 'advanced-shipping-settings' ),
			'shipping_method' => __( 'Shipping method', 'advanced-shipping-settings' ),
			'pickup_point'    => __( 'Pickup point', 'advanced-shipping-settings' ),
			'city'            => __( 'City', 'advanced-shipping-settings' ),
			'pickup_date'     => __( 'Pickup date', 'advanced-shipping-settings' ),
			'pickup_time'     => __( 'Pickup time', 'advanced-shipping-settings' ),
			'site_title'      => __( 'Site title', 'advanced-shipping-settings' ),
		];
	}

	public static function variables( \WC_Order $order ): array {
		return [
			'order_number'    => (string) $order->get_order_number(),
			'pickup_code'     => Pickup_Code::get_code( $order ),
			'first_name'      => Pickup_Helper::get_contact_first_name( $order ),
			'last_name'       => Pickup_Helper::get_contact_last_name( $order ),
			'shipping_method' => Pickup_Helper::get_method_label( $order ),
			'pickup_point'    => Pickup_Helper::get_pickup_point( $order ),
			'city'            => Pickup_Helper::get_city( $order ),
			'pickup_date'     => Pickup_Helper::get_pickup_date( $order ),
			'pickup_time'     => Pickup_Helper::get_pickup_time( $order ),
			'site_title'      => wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ),
		];
	}

	public static function group_for( \WC_Order $order ): string {
		return Pickup_Helper::uses_own_pickup( $order ) ? self::GROUP_CODE : self::GROUP_PLAIN;
	}

	public static function template_for( string $group ): string {
		$settings = self::get_settings();
		return self::GROUP_CODE === $group ? $settings['template_code'] : $settings['template_plain'];
	}

	public static function get_status( \WC_Order $order ): string {
		$status = (string) $order->get_meta( self::META_STATUS );
		return in_array( $status, [ self::STATUS_NOT_SENT, self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_NO_PHONE ], true ) ? $status : '';
	}

	public static function status_labels(): array {
		return [
			self::STATUS_NOT_SENT => __( 'Not sent', 'advanced-shipping-settings' ),
			self::STATUS_SENT     => __( 'Sent', 'advanced-shipping-settings' ),
			self::STATUS_FAILED   => __( 'Failed', 'advanced-shipping-settings' ),
			self::STATUS_NO_PHONE => __( 'No mobile number', 'advanced-shipping-settings' ),
		];
	}

	public static function get_log( \WC_Order $order ): array {
		$log = $order->get_meta( self::META_LOG );
		return is_array( $log ) ? array_values( array_filter( $log, 'is_array' ) ) : [];
	}

	public static function last_log_entry( \WC_Order $order ): ?array {
		$log = self::get_log( $order );
		return $log ? $log[ count( $log ) - 1 ] : null;
	}

	private static function record( \WC_Order $order, string $status, array $entry ): void {
		$log   = self::get_log( $order );
		$log[] = array_merge(
			[
				'time'       => time(),
				'user'       => get_current_user_id(),
				'status'     => $status,
				'number'     => '',
				'text'       => '',
				'parts'      => 0,
				'message_id' => '',
				'error'      => '',
			],
			$entry
		);

		$order->update_meta_data( self::META_LOG, array_slice( $log, -self::LOG_LIMIT ) );
		$order->update_meta_data( self::META_STATUS, $status );
		$order->save();
	}

	public static function send( \WC_Order $order, ?string $template = null ): array {
		if ( ! self::is_configured() ) {
			return [
				'status' => self::STATUS_FAILED,
				'error'  => __( 'SMS sending is not configured.', 'advanced-shipping-settings' ),
			];
		}

		$phone = Pickup_Helper::get_sms_number( $order );
		if ( ! $phone['valid'] ) {
			$error = 'not_mobile' === $phone['reason']
				? __( 'The phone number is not a mobile number.', 'advanced-shipping-settings' )
				: __( 'The order has no valid phone number.', 'advanced-shipping-settings' );
			self::record( $order, self::STATUS_NO_PHONE, [ 'number' => $phone['number'], 'error' => $error ] );
			return [
				'status' => self::STATUS_NO_PHONE,
				'error'  => $error,
			];
		}

		$template = null === $template ? '' : self::sanitize_template( $template );
		if ( '' === $template ) {
			$template = self::template_for( self::group_for( $order ) );
		}

		$text = Sms_Text::render( $template, self::variables( $order ) );
		if ( ! Sms_Text::is_sendable( $text ) ) {
			$error = '' === $text
				? __( 'The SMS text is empty.', 'advanced-shipping-settings' )
				: __( 'The SMS text is too long.', 'advanced-shipping-settings' );
			self::record( $order, self::STATUS_FAILED, [ 'number' => $phone['number'], 'text' => $text, 'error' => $error ] );
			return [
				'status' => self::STATUS_FAILED,
				'error'  => $error,
			];
		}

		$result = self::client()->send( $phone['number'], $text );
		$status = $result['accepted'] ? self::STATUS_SENT : self::STATUS_FAILED;

		self::record(
			$order,
			$status,
			[
				'number'     => $phone['number'],
				'text'       => $text,
				'parts'      => (int) $result['parts'],
				'message_id' => (string) $result['message_id'],
				'error'      => (string) $result['error'],
			]
		);

		if ( $result['accepted'] ) {
			$order->add_order_note( sprintf( __( 'Pickup SMS sent to %1$s: %2$s', 'advanced-shipping-settings' ), Pickup_Helper::format_phone( $phone['number'] ), $text ) );
		} else {
			$order->add_order_note( sprintf( __( 'Pickup SMS to %1$s failed: %2$s', 'advanced-shipping-settings' ), Pickup_Helper::format_phone( $phone['number'] ), $result['error'] ) );
		}

		return [
			'status' => $status,
			'error'  => (string) $result['error'],
			'number' => $phone['number'],
			'text'   => $text,
			'parts'  => (int) $result['parts'],
		];
	}

	public static function send_test( string $raw_number ): array {
		$credentials = self::credentials();
		if ( $credentials['application_id'] <= 0 || '' === $credentials['token'] ) {
			return [
				'accepted' => false,
				'error'    => __( 'Enter and save the BulkGate Application ID and token first.', 'advanced-shipping-settings' ),
			];
		}

		$phone = Pickup_Helper::normalize_phone( $raw_number );
		if ( ! $phone['valid'] ) {
			return [
				'accepted' => false,
				'error'    => __( 'Enter a valid mobile number.', 'advanced-shipping-settings' ),
			];
		}

		$text = Sms_Text::plain( sprintf( '%s: SMS test %s', wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ), wp_date( 'H:i:s' ) ) );
		return self::client()->send( $phone['number'], $text );
	}
}
