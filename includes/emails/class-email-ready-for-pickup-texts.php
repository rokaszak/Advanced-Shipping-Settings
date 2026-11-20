<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Email_Ready_For_Pickup_Texts {

	const OPTION = 'woocommerce_ass_ready_for_pickup_settings';

	public static function default_code_label(): string {
		return Pickup_Helper::default_text( 'Your pickup code', 'Tavo atsiėmimo kodas' );
	}

	public static function default_code_hint(): string {
		return Pickup_Helper::default_text( 'Show this code or the QR code when you collect your order.', 'Atsiimdamas užsakymą parodyk šį kodą arba QR kodą.' );
	}

	public static function default_point_line(): string {
		return Pickup_Helper::default_text( 'Pickup point: %s', 'Atsiėmimo vieta: %s' );
	}

	private static function stored( string $key, string $default ): string {
		$settings = get_option( self::OPTION, [] );
		if ( is_array( $settings ) && array_key_exists( $key, $settings ) ) {
			return trim( (string) $settings[ $key ] );
		}
		return $default;
	}

	public static function format( string $template, ...$arguments ): string {
		try {
			$result = @sprintf( $template, ...$arguments );
		} catch ( \Throwable $error ) {
			$result = false;
		}
		if ( is_string( $result ) ) {
			return $result;
		}
		return trim( $template . ' ' . implode( ' ', array_map( 'strval', $arguments ) ) );
	}

	public static function code_label(): string {
		return self::stored( 'pickup_code_label', self::default_code_label() );
	}

	public static function code_hint(): string {
		return self::stored( 'pickup_code_hint', self::default_code_hint() );
	}
}
