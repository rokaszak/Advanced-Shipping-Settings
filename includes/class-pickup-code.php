<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pickup_Code {

	const META_CODE  = '_ass_pickup_code';
	const META_TOKEN = '_ass_pickup_qr_token';
	const QR_PREFIX  = 'woocommerce_ready_pickup:';
	const MIN_DIGITS = 4;
	const MAX_DIGITS = 8;

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_qr_route' ] );
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', [ $this, 'apply_legacy_code_query' ], 10, 2 );
	}

	public function apply_legacy_code_query( $query, $query_vars ) {
		if ( ! is_array( $query ) || ! is_array( $query_vars ) || empty( $query_vars['ass_pickup_code'] ) || ! is_array( $query_vars['ass_pickup_code'] ) ) {
			return $query;
		}

		$clause = [ 'key' => self::META_CODE ];
		if ( isset( $query_vars['ass_pickup_code']['value'] ) ) {
			$clause['value'] = (string) $query_vars['ass_pickup_code']['value'];
		} else {
			$clause['compare'] = 'EXISTS';
		}

		if ( ! isset( $query['meta_query'] ) || ! is_array( $query['meta_query'] ) ) {
			$query['meta_query'] = [];
		}
		$query['meta_query'][] = $clause;

		return $query;
	}

	public static function released_statuses(): array {
		return (array) apply_filters( 'ass_pickup_code_released_statuses', [ 'completed', 'cancelled', 'refunded', 'failed', 'trash' ] );
	}

	public static function holding_statuses(): array {
		$statuses = [];
		foreach ( array_keys( wc_get_order_statuses() ) as $status ) {
			$statuses[] = 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
		}
		return array_values( array_diff( array_unique( $statuses ), self::released_statuses() ) );
	}

	public static function get_code( \WC_Order $order ): string {
		$code = (string) $order->get_meta( self::META_CODE );
		return preg_match( '/^\d{' . self::MIN_DIGITS . ',' . self::MAX_DIGITS . '}$/', $code ) ? $code : '';
	}

	public static function qr_payload( string $code ): string {
		return self::QR_PREFIX . $code;
	}

	public static function ensure_code( \WC_Order $order ): string {
		if ( ! $order->get_id() ) {
			return '';
		}

		$locked = self::lock();

		try {
			$changed = false;
			$stored  = wc_get_order( $order->get_id() );

			if ( $stored instanceof \WC_Order && $stored !== $order ) {
				foreach ( [ self::META_CODE, self::META_TOKEN ] as $key ) {
					$value = (string) $stored->get_meta( $key );
					if ( '' !== $value && $value !== (string) $order->get_meta( $key ) ) {
						$order->update_meta_data( $key, $value );
						$changed = true;
					}
				}
			}

			$code  = self::get_code( $order );
			$taken = self::taken_codes( $order->get_id() );

			if ( '' === $code || isset( $taken[ $code ] ) ) {
				$code = self::pick_code( $taken );
				$order->update_meta_data( self::META_CODE, $code );
				$changed = true;
			}

			if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $order->get_meta( self::META_TOKEN ) ) ) {
				$order->update_meta_data( self::META_TOKEN, bin2hex( random_bytes( 16 ) ) );
				$changed = true;
			}

			if ( $changed ) {
				$order->save();
			}
		} finally {
			if ( $locked ) {
				self::unlock();
			}
		}

		return $code;
	}

	private static function lock_name(): string {
		global $wpdb;
		return substr( 'ass_pickup_code_' . md5( DB_NAME . '|' . $wpdb->prefix ), 0, 60 );
	}

	private static function lock(): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name(), 8 ) );
	}

	private static function unlock(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
	}

	private static function orders_with_code_query( array $statuses, array $extra = [] ): array {
		if ( empty( $statuses ) ) {
			return [];
		}
		$args = array_merge(
			[
				'type'   => 'shop_order',
				'status' => $statuses,
				'limit'  => -1,
				'return' => 'ids',
			],
			$extra
		);
		$ids = wc_get_orders( $args );
		return is_array( $ids ) ? array_map( 'absint', $ids ) : [];
	}

	private static function taken_codes( int $exclude_order_id ): array {
		$ids = self::orders_with_code_query(
			self::holding_statuses(),
			[
				'ass_pickup_code' => [ 'compare' => 'EXISTS' ],
				'meta_query'      => [
					[
						'key'     => self::META_CODE,
						'compare' => 'EXISTS',
					],
				],
			]
		);

		$taken = [];
		foreach ( $ids as $id ) {
			if ( $id === $exclude_order_id ) {
				continue;
			}
			$other = wc_get_order( $id );
			if ( ! $other ) {
				continue;
			}
			$code = self::get_code( $other );
			if ( '' !== $code ) {
				$taken[ $code ] = $id;
			}
		}
		return $taken;
	}

	private static function pick_code( array $taken ): string {
		$used_by_length = [];
		foreach ( array_keys( $taken ) as $code ) {
			$length                    = strlen( (string) $code );
			$used_by_length[ $length ] = ( $used_by_length[ $length ] ?? 0 ) + 1;
		}

		for ( $digits = self::MIN_DIGITS; $digits <= self::MAX_DIGITS; $digits++ ) {
			$minimum  = 10 ** ( $digits - 1 );
			$maximum  = 10 ** $digits - 1;
			$capacity = $maximum - $minimum + 1;
			if ( ( $used_by_length[ $digits ] ?? 0 ) >= $capacity * 0.7 ) {
				continue;
			}
			for ( $attempt = 0; $attempt < 300; $attempt++ ) {
				$candidate = (string) random_int( $minimum, $maximum );
				if ( ! isset( $taken[ $candidate ] ) ) {
					return $candidate;
				}
			}
		}

		return (string) random_int( 10 ** ( self::MAX_DIGITS - 1 ), 10 ** self::MAX_DIGITS - 1 );
	}

	private static function find_by_code( string $code, array $statuses, int $limit = 5 ): array {
		if ( ! preg_match( '/^\d{' . self::MIN_DIGITS . ',' . self::MAX_DIGITS . '}$/', $code ) ) {
			return [];
		}

		$ids = self::orders_with_code_query(
			$statuses,
			[
				'limit'           => $limit,
				'orderby'         => 'date',
				'order'           => 'DESC',
				'ass_pickup_code' => [ 'value' => $code ],
				'meta_query'      => [
					[
						'key'   => self::META_CODE,
						'value' => $code,
					],
				],
			]
		);

		$orders = [];
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order && self::get_code( $order ) === $code ) {
				$orders[] = $order;
			}
		}
		return $orders;
	}

	public static function find_ready_order( string $code ): ?\WC_Order {
		$orders = self::find_by_code( $code, [ Pickup_Helper::STATUS_READY ], 1 );
		return $orders ? $orders[0] : null;
	}

	public static function find_other_order( string $code ): ?\WC_Order {
		$holding = array_values( array_diff( self::holding_statuses(), [ Pickup_Helper::STATUS_READY ] ) );
		$orders  = self::find_by_code( $code, $holding, 1 );
		if ( $orders ) {
			return $orders[0];
		}

		$latest = null;
		foreach ( self::find_by_code( $code, [ 'completed' ], 20 ) as $order ) {
			$completed = $order->get_date_completed();
			if ( ! $completed ) {
				continue;
			}
			if ( null === $latest || $completed->getTimestamp() > $latest->get_date_completed()->getTimestamp() ) {
				$latest = $order;
			}
		}
		return $latest;
	}

	public static function qr_url( \WC_Order $order ): string {
		$token = (string) $order->get_meta( self::META_TOKEN );
		if ( '' === self::get_code( $order ) || ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return '';
		}
		return rest_url( 'ass/v1/pickup-qr/' . $order->get_id() . '/' . $token );
	}

	public static function qr_svg( \WC_Order $order, int $size = 180 ): string {
		$code = self::get_code( $order );
		return '' === $code ? '' : Qr_Encoder::svg( self::qr_payload( $code ), $size );
	}

	public function register_qr_route(): void {
		register_rest_route(
			'ass/v1',
			'/pickup-qr/(?P<order>\d+)/(?P<token>[a-f0-9]{32})',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'serve_qr' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function serve_qr( \WP_REST_Request $request ) {
		$not_found = new \WP_Error( 'ass_qr_not_found', __( 'Not found.', 'advanced-shipping-settings' ), [ 'status' => 404 ] );
		$order     = wc_get_order( absint( $request['order'] ) );

		if ( ! $order instanceof \WC_Order ) {
			return $not_found;
		}

		$token = (string) $order->get_meta( self::META_TOKEN );
		$code  = self::get_code( $order );

		if ( '' === $token || '' === $code || ! hash_equals( $token, (string) $request['token'] ) || ! in_array( $order->get_status(), self::holding_statuses(), true ) ) {
			return $not_found;
		}

		$png = Qr_Encoder::png( self::qr_payload( $code ), 8, 4 );
		if ( '' === $png ) {
			return $not_found;
		}

		header( 'Content-Type: image/png' );
		header( 'Cache-Control: private, max-age=86400' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $png;
		exit;
	}
}
