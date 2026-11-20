<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pickup_Helper {

	const STATUS_READY    = 'ready-for-pickup';
	const META_TIME       = 'pickup_time';
	const META_RULE_TYPE  = '_ass_rule_type';
	const META_READY_AT   = '_ass_pickup_ready_at';

	public static function default_text( string $english, string $lithuanian ): string {
		return 0 === strpos( (string) get_locale(), 'lt' ) ? $lithuanian : $english;
	}

	public static function ready_status_label(): string {
		$locale = function_exists( 'determine_locale' ) ? (string) determine_locale() : (string) get_locale();
		return 0 === strpos( $locale, 'lt' ) ? 'Paruoštas atsiimti' : _x( 'Ready for Pickup', 'Order status', 'advanced-shipping-settings' );
	}

	public static function listed_statuses(): array {
		return [ 'processing', 'on-hold', self::STATUS_READY ];
	}

	public static function can_become_ready( \WC_Order $order ): bool {
		return in_array( $order->get_status(), [ 'processing', 'on-hold' ], true );
	}

	public static function get_shipping_item( \WC_Order $order ): ?\WC_Order_Item_Shipping {
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Shipping ) {
				return $item;
			}
		}
		return null;
	}

	public static function get_method_id( \WC_Order $order ): string {
		$item = self::get_shipping_item( $order );
		return $item ? (string) $item->get_method_id() : '';
	}

	public static function own_locations(): array {
		$locations = [];
		foreach ( (array) Settings_Manager::instance()->get_pickup_locations() as $location ) {
			if ( ! is_array( $location ) || empty( $location['method_id'] ) ) {
				continue;
			}
			$locations[ (string) $location['method_id'] ] = (string) ( $location['name'] ?? '' );
		}
		return $locations;
	}

	public static function uses_own_pickup( \WC_Order $order ): bool {
		$method_id = self::get_method_id( $order );
		return '' !== $method_id && array_key_exists( $method_id, self::own_locations() );
	}

	public static function get_rule( string $method_id ): ?array {
		if ( '' === $method_id ) {
			return null;
		}
		$rules = (array) Settings_Manager::instance()->get_shipping_rules();
		$rule  = $rules[ $method_id ] ?? null;
		return is_array( $rule ) ? $rule : null;
	}

	public static function is_reservation_method( string $method_id ): bool {
		$rule = self::get_rule( $method_id );
		return null !== $rule && 'by_date' === ( $rule['type'] ?? '' );
	}

	public static function is_reservation_order( \WC_Order $order, ?string $method_id = null ): bool {
		$stored = (string) $order->get_meta( self::META_RULE_TYPE );
		if ( '' !== $stored ) {
			return 'by_date' === $stored;
		}
		return self::is_reservation_method( null === $method_id ? self::get_method_id( $order ) : $method_id );
	}

	public static function method_display_name( string $method_id, string $fallback ): string {
		$names = (array) Settings_Manager::instance()->get_method_display_names();
		$name  = isset( $names[ $method_id ] ) ? trim( (string) $names[ $method_id ] ) : '';
		return '' !== $name ? $name : $fallback;
	}

	public static function method_logo_url( string $method_id ): string {
		static $cache = [];

		if ( '' === $method_id ) {
			return '';
		}
		if ( isset( $cache[ $method_id ] ) ) {
			return $cache[ $method_id ];
		}

		$images   = (array) Settings_Manager::instance()->get_method_images();
		$image_id = isset( $images[ $method_id ] ) ? absint( $images[ $method_id ] ) : 0;

		if ( ! $image_id ) {
			foreach ( (array) Settings_Manager::instance()->get_pickup_locations() as $location ) {
				if ( is_array( $location ) && ( $location['method_id'] ?? '' ) === $method_id ) {
					$image_id = absint( $location['image_id'] ?? 0 );
					break;
				}
			}
		}

		$url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';
		if ( ! $url && $image_id ) {
			$url = wp_get_attachment_image_url( $image_id, 'full' );
		}
		$cache[ $method_id ] = $url ? (string) $url : '';

		return $cache[ $method_id ];
	}

	public static function get_method_label( \WC_Order $order ): string {
		$item = self::get_shipping_item( $order );
		if ( ! $item ) {
			return '';
		}
		return self::method_display_name( (string) $item->get_method_id(), (string) $item->get_name() );
	}

	public static function get_city( \WC_Order $order ): string {
		$city = trim( (string) $order->get_shipping_city() );
		return '' !== $city ? $city : trim( (string) $order->get_billing_city() );
	}

	public static function get_pickup_point( \WC_Order $order ): string {
		$method_id = self::get_method_id( $order );
		$own       = self::own_locations();
		$point     = '';

		if ( isset( $own[ $method_id ] ) && '' !== $own[ $method_id ] ) {
			$point = $own[ $method_id ];
		} else {
			$terminal = trim( (string) $order->get_meta( 'multiparcels_location_identifier_name' ) );
			if ( '' !== $terminal ) {
				$country = (string) $order->get_shipping_country();
				if ( '' === $country ) {
					$country = (string) $order->get_billing_country();
				}
				if ( '' !== $country && preg_match( '/\s' . preg_quote( $country, '/' ) . '$/', $terminal ) ) {
					$terminal = trim( substr( $terminal, 0, -strlen( $country ) ) );
				}
				$point = $terminal;
			}
		}

		if ( '' === $point ) {
			$point = self::get_city( $order );
		}

		return (string) apply_filters( 'ass_pickup_point_label', $point, $order, $method_id );
	}

	public static function get_pickup_date( \WC_Order $order ): string {
		$date = trim( (string) $order->get_meta( 'deliver_by_date' ) );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
	}

	public static function day_name( string $date ): string {
		$parsed = \DateTime::createFromFormat( '!Y-m-d', $date );
		if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $date ) {
			return '';
		}

		$days = [
			1 => [ 'day_monday', 'Monday' ],
			2 => [ 'day_tuesday', 'Tuesday' ],
			3 => [ 'day_wednesday', 'Wednesday' ],
			4 => [ 'day_thursday', 'Thursday' ],
			5 => [ 'day_friday', 'Friday' ],
			6 => [ 'day_saturday', 'Saturday' ],
			7 => [ 'day_sunday', 'Sunday' ],
		];
		$day  = $days[ (int) $parsed->format( 'N' ) ];

		return Settings_Manager::instance()->get_translation( $day[0], $day[1] );
	}

	public static function sanitize_time( $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( preg_match( '/^(\d{1,2}):(\d{2})/', $value, $match ) && (int) $match[1] < 24 && (int) $match[2] < 60 ) {
			return sprintf( '%02d:%02d', (int) $match[1], (int) $match[2] );
		}
		return '';
	}

	public static function build_time_window( $from, $to ): string {
		$from = self::sanitize_time( $from );
		$to   = self::sanitize_time( $to );
		if ( '' === $from ) {
			return '';
		}
		return '' === $to ? $from : $from . '-' . $to;
	}

	public static function sanitize_time_window( $value ): string {
		$parts = explode( '-', is_scalar( $value ) ? (string) $value : '', 2 );
		return self::build_time_window( $parts[0], $parts[1] ?? '' );
	}

	public static function format_time_window( string $window ): string {
		return str_replace( '-', '–', $window );
	}

	public static function date_info_time_window( array $date_info ): string {
		return self::build_time_window( $date_info['time_from'] ?? '', $date_info['time_to'] ?? '' );
	}

	public static function date_info_label( array $date_info ): string {
		$label  = ! empty( $date_info['label'] ) ? (string) $date_info['label'] : (string) ( $date_info['date'] ?? '' );
		$window = self::date_info_time_window( $date_info );
		return '' === $window ? $label : $label . ', ' . self::format_time_window( $window );
	}

	public static function rule_time_window( ?array $rule, string $date ): string {
		if ( null === $rule || '' === $date || 'by_date' !== ( $rule['type'] ?? '' ) ) {
			return '';
		}
		foreach ( (array) ( $rule['dates'] ?? [] ) as $date_info ) {
			if ( is_array( $date_info ) && ( $date_info['date'] ?? '' ) === $date ) {
				return self::date_info_time_window( $date_info );
			}
		}
		return '';
	}

	public static function rule_date_label( ?array $rule, string $date ): string {
		if ( null === $rule || '' === $date || 'by_date' !== ( $rule['type'] ?? '' ) ) {
			return '';
		}
		foreach ( (array) ( $rule['dates'] ?? [] ) as $date_info ) {
			if ( is_array( $date_info ) && ( $date_info['date'] ?? '' ) === $date ) {
				return trim( (string) ( $date_info['label'] ?? '' ) );
			}
		}
		return '';
	}

	public static function get_pickup_time( \WC_Order $order ): string {
		if ( $order->meta_exists( self::META_TIME ) ) {
			return self::sanitize_time_window( $order->get_meta( self::META_TIME ) );
		}
		return self::rule_time_window( self::get_rule( self::get_method_id( $order ) ), self::get_pickup_date( $order ) );
	}

	public static function get_pickup_time_display( \WC_Order $order ): string {
		return self::format_time_window( self::get_pickup_time( $order ) );
	}

	public static function get_contact_first_name( \WC_Order $order ): string {
		$name = trim( (string) $order->get_shipping_first_name() );
		return '' !== $name ? $name : trim( (string) $order->get_billing_first_name() );
	}

	public static function get_contact_last_name( \WC_Order $order ): string {
		$name = trim( (string) $order->get_shipping_last_name() );
		return '' !== $name ? $name : trim( (string) $order->get_billing_last_name() );
	}

	public static function get_contact_name( \WC_Order $order ): string {
		$shipping = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		if ( '' !== $shipping ) {
			return $shipping;
		}
		return trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	}

	public static function get_raw_phone( \WC_Order $order ): string {
		$phone = method_exists( $order, 'get_shipping_phone' ) ? trim( (string) $order->get_shipping_phone() ) : '';
		return '' !== $phone ? $phone : trim( (string) $order->get_billing_phone() );
	}

	public static function get_sms_number( \WC_Order $order ): array {
		$candidates = [];
		if ( method_exists( $order, 'get_shipping_phone' ) ) {
			$candidates[] = (string) $order->get_shipping_phone();
		}
		$candidates[] = (string) $order->get_billing_phone();

		$first_failure = null;
		foreach ( $candidates as $candidate ) {
			if ( '' === trim( $candidate ) ) {
				continue;
			}
			$result = self::normalize_phone( $candidate );
			if ( $result['valid'] ) {
				return $result;
			}
			if ( null === $first_failure ) {
				$first_failure = $result;
			}
		}

		return null !== $first_failure ? $first_failure : [ 'valid' => false, 'number' => '', 'reason' => 'empty' ];
	}

	public static function base_calling_code(): string {
		$code = '';
		if ( function_exists( 'WC' ) && WC()->countries ) {
			$code = WC()->countries->get_country_calling_code( WC()->countries->get_base_country() );
			if ( is_array( $code ) ) {
				$code = reset( $code );
			}
		}
		return preg_replace( '/\D+/', '', (string) $code );
	}

	public static function normalize_phone( string $raw ): array {
		$raw           = trim( $raw );
		$digits        = preg_replace( '/\D+/', '', $raw );
		$international = 0 === strpos( $raw, '+' );

		if ( '' === $digits ) {
			return [ 'valid' => false, 'number' => '', 'reason' => 'empty' ];
		}

		if ( ! $international && 0 === strpos( $digits, '00' ) ) {
			$digits        = substr( $digits, 2 );
			$international = true;
		}

		$calling_code = self::base_calling_code();

		if ( ! $international ) {
			if ( '370' === $calling_code ) {
				if ( preg_match( '/^(?:8|0|08)?(6\d{7})$/', $digits, $match ) ) {
					$digits = '370' . $match[1];
				} elseif ( 0 !== strpos( $digits, '370' ) ) {
					return [ 'valid' => false, 'number' => $digits, 'reason' => 'invalid' ];
				}
			} elseif ( '' !== $calling_code && 0 !== strpos( $digits, $calling_code ) ) {
				$digits = $calling_code . ltrim( $digits, '0' );
			}
		}

		if ( strlen( $digits ) < 8 || strlen( $digits ) > 15 ) {
			return [ 'valid' => false, 'number' => $digits, 'reason' => 'invalid' ];
		}

		if ( 0 === strpos( $digits, '370' ) && ! preg_match( '/^3706\d{7}$/', $digits ) ) {
			return [ 'valid' => false, 'number' => $digits, 'reason' => 'not_mobile' ];
		}

		return [ 'valid' => true, 'number' => $digits, 'reason' => '' ];
	}

	public static function format_phone( string $digits ): string {
		return '' === $digits ? '' : '+' . $digits;
	}

	public static function status_label( string $status ): string {
		return wc_get_order_status_name( $status );
	}
}
