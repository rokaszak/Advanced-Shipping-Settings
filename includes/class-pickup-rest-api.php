<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pickup_Rest_Api {

	const REST_NAMESPACE  = 'wc-pickup/v1';
	const MISS_LIMIT      = 30;
	const MISS_WINDOW     = 600;

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$code_args = [
			'code' => [
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => [ $this, 'validate_code' ],
			],
		];

		register_rest_route(
			self::REST_NAMESPACE,
			'/orders/code/(?P<code>[^/]+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_order_by_code' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => $code_args,
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/orders/code/(?P<code>[^/]+)/complete',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'complete_order_by_code' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => array_merge(
					$code_args,
					[
						'order_id' => [
							'required' => false,
							'type'     => 'integer',
						],
					]
				),
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/orders/open',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_open_orders' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'date'      => [
						'required'          => false,
						'type'              => 'string',
						'validate_callback' => [ $this, 'validate_date' ],
					],
					'method_id' => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/orders/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_order_by_id' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'id' => [
						'required' => true,
						'type'     => 'integer',
					],
				],
			]
		);
	}

	public function validate_code( $value ): bool {
		return '' !== self::normalize_code( $value );
	}

	public function validate_date( $value ): bool {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}
		$parsed = \DateTime::createFromFormat( '!Y-m-d', $value );
		return $parsed && $parsed->format( 'Y-m-d' ) === $value;
	}

	public static function normalize_code( $value ): string {
		$value = is_scalar( $value ) ? trim( rawurldecode( (string) $value ) ) : '';
		if ( 0 === stripos( $value, Pickup_Code::QR_PREFIX ) ) {
			$value = substr( $value, strlen( Pickup_Code::QR_PREFIX ) );
		}
		return preg_match( '/^\d{' . Pickup_Code::MIN_DIGITS . ',' . Pickup_Code::MAX_DIGITS . '}$/', $value ) ? $value : '';
	}

	public function check_permission() {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'ass_pickup_unauthorized', __( 'Authentication is required.', 'advanced-shipping-settings' ), [ 'status' => 401 ] );
		}
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		return new \WP_Error( 'ass_pickup_forbidden', __( 'You are not allowed to use the pickup API.', 'advanced-shipping-settings' ), [ 'status' => 403 ] );
	}

	private static function miss_key(): string {
		return 'ass_pickup_miss_' . get_current_user_id();
	}

	private static function is_throttled(): bool {
		$state = get_transient( self::miss_key() );
		return is_array( $state ) && (int) ( $state['count'] ?? 0 ) >= self::MISS_LIMIT && (int) ( $state['until'] ?? 0 ) > time();
	}

	private static function register_miss(): void {
		$state = get_transient( self::miss_key() );
		if ( ! is_array( $state ) || (int) ( $state['until'] ?? 0 ) <= time() ) {
			$state = [
				'count' => 0,
				'until' => time() + self::MISS_WINDOW,
			];
		}
		$state['count']++;
		set_transient( self::miss_key(), $state, max( 1, $state['until'] - time() ) );
	}

	private static function throttled_error(): \WP_Error {
		return new \WP_Error( 'ass_pickup_too_many_attempts', __( 'Too many unknown pickup codes. Try again in a few minutes.', 'advanced-shipping-settings' ), [ 'status' => 429 ] );
	}

	private function envelope( \WC_Order $order, bool $with_code ): array {
		$reason = $this->describe_status( $order );

		return [
			'ready'   => Pickup_Helper::STATUS_READY === $order->get_status(),
			'reason'  => $reason['reason'],
			'message' => $reason['message'],
			'order'   => $this->prepare_order( $order, $with_code ),
		];
	}

	private function resolve_code( string $code ) {
		if ( self::is_throttled() ) {
			return self::throttled_error();
		}

		$order = Pickup_Code::find_ready_order( $code );
		if ( $order ) {
			return $order;
		}

		$other = Pickup_Code::find_other_order( $code );
		if ( ! $other ) {
			self::register_miss();
			return new \WP_Error( 'ass_pickup_code_not_found', __( 'No order found for this pickup code.', 'advanced-shipping-settings' ), [ 'status' => 404 ] );
		}

		$data = array_merge( [ 'status' => 409 ], $this->envelope( $other, false ) );

		if ( 'completed' === $other->get_status() ) {
			return new \WP_Error( 'ass_pickup_already_picked_up', __( 'This order has already been picked up.', 'advanced-shipping-settings' ), $data );
		}

		self::register_miss();

		return new \WP_Error( 'ass_pickup_not_ready', __( 'This order is not ready for pickup.', 'advanced-shipping-settings' ), $data );
	}

	public function get_order_by_code( \WP_REST_Request $request ) {
		$order = $this->resolve_code( self::normalize_code( $request['code'] ) );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		return rest_ensure_response( $this->envelope( $order, true ) );
	}

	public function complete_order_by_code( \WP_REST_Request $request ) {
		$code  = self::normalize_code( $request['code'] );
		$order = $this->resolve_code( $code );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$expected_id = absint( $request['order_id'] );
		if ( $expected_id && $expected_id !== $order->get_id() ) {
			return new \WP_Error( 'ass_pickup_order_mismatch', __( 'The pickup code belongs to a different order.', 'advanced-shipping-settings' ), [ 'status' => 409 ] );
		}

		$user = wp_get_current_user();
		$note = sprintf( __( 'Picked up with pickup code %1$s. Completed through the pickup API by %2$s.', 'advanced-shipping-settings' ), $code, $user && $user->exists() ? $user->user_login : '-' );

		try {
			$order->update_status( 'completed', $note, true );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'ass_pickup_complete_failed', __( 'The order could not be completed.', 'advanced-shipping-settings' ), [ 'status' => 500 ] );
		}

		$order = wc_get_order( $order->get_id() );
		if ( ! $order || 'completed' !== $order->get_status() ) {
			return new \WP_Error( 'ass_pickup_complete_failed', __( 'The order could not be completed.', 'advanced-shipping-settings' ), [ 'status' => 500 ] );
		}

		return rest_ensure_response(
			[
				'completed' => true,
				'order'     => $this->prepare_order( $order, true ),
			]
		);
	}

	public function get_order_by_id( \WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() || 'trash' === $order->get_status() ) {
			return new \WP_Error( 'ass_pickup_order_not_found', __( 'Order not found.', 'advanced-shipping-settings' ), [ 'status' => 404 ] );
		}

		return rest_ensure_response( $this->envelope( $order, false ) );
	}

	public function get_open_orders( \WP_REST_Request $request ) {
		$today  = wp_date( 'Y-m-d' );
		$date   = is_string( $request['date'] ) ? $request['date'] : '';
		$method = is_string( $request['method_id'] ) ? $request['method_id'] : '';

		$orders = wc_get_orders(
			[
				'type'    => 'shop_order',
				'status'  => Pickup_Helper::listed_statuses(),
				'limit'   => -1,
				'orderby' => 'ID',
				'order'   => 'ASC',
			]
		);

		$methods = [];
		$picked  = [];

		foreach ( is_array( $orders ) ? $orders : [] as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$method_id = Pickup_Helper::get_method_id( $order );
			$reserved  = Pickup_Helper::get_pickup_date( $order );

			if ( '' === $reserved || ! Pickup_Helper::is_reservation_order( $order, $method_id ) ) {
				continue;
			}
			if ( '' !== $date ? $reserved !== $date : $reserved > $today ) {
				continue;
			}

			$is_ready = Pickup_Helper::STATUS_READY === $order->get_status();

			if ( ! isset( $methods[ $method_id ] ) ) {
				$logo                  = Pickup_Helper::method_logo_url( $method_id );
				$methods[ $method_id ] = [
					'method_id'    => $method_id,
					'method_label' => Pickup_Helper::get_method_label( $order ),
					'logo'         => '' !== $logo ? $logo : null,
					'total'        => 0,
					'ready'        => 0,
					'not_ready'    => 0,
				];
			}
			$methods[ $method_id ]['total']++;
			$methods[ $method_id ][ $is_ready ? 'ready' : 'not_ready' ]++;

			if ( '' !== $method && $method !== $method_id ) {
				continue;
			}

			$picked[] = [
				'date'  => $reserved,
				'id'    => $order->get_id(),
				'ready' => $is_ready,
				'order' => $order,
			];
		}

		usort(
			$picked,
			static function ( array $a, array $b ): int {
				return [ $a['date'], $a['id'] ] <=> [ $b['date'], $b['id'] ];
			}
		);

		$list  = [];
		$ready = 0;
		foreach ( $picked as $entry ) {
			$list[] = $this->prepare_order( $entry['order'], false );
			if ( $entry['ready'] ) {
				$ready++;
			}
		}

		return rest_ensure_response(
			[
				'today'     => $today,
				'date'      => '' !== $date ? $date : null,
				'method_id' => '' !== $method ? $method : null,
				'counts'    => [
					'total'     => count( $list ),
					'ready'     => $ready,
					'not_ready' => count( $list ) - $ready,
				],
				'methods'   => array_values( $methods ),
				'orders'    => $list,
			]
		);
	}

	private function describe_status( \WC_Order $order ): array {
		$status = $order->get_status();
		$label  = Pickup_Helper::status_label( $status );

		switch ( $status ) {
			case Pickup_Helper::STATUS_READY:
				return [
					'reason'  => 'ready',
					'message' => __( 'The order is ready for pickup.', 'advanced-shipping-settings' ),
				];
			case 'pending':
				return [
					'reason'  => 'awaiting_payment',
					'message' => __( 'The order is not paid yet.', 'advanced-shipping-settings' ),
				];
			case 'on-hold':
				return [
					'reason'  => 'on_hold',
					'message' => __( 'The order is on hold and has not been confirmed yet.', 'advanced-shipping-settings' ),
				];
			case 'processing':
				return [
					'reason'  => 'not_ready_yet',
					'message' => __( 'The order is paid and being prepared. It has not been marked ready for pickup yet.', 'advanced-shipping-settings' ),
				];
			case 'completed':
				return [
					'reason'  => 'already_completed',
					'message' => __( 'The order is already completed.', 'advanced-shipping-settings' ),
				];
			case 'cancelled':
				return [
					'reason'  => 'cancelled',
					'message' => __( 'The order was cancelled.', 'advanced-shipping-settings' ),
				];
			case 'refunded':
				return [
					'reason'  => 'refunded',
					'message' => __( 'The order was refunded.', 'advanced-shipping-settings' ),
				];
			case 'failed':
				return [
					'reason'  => 'payment_failed',
					'message' => __( 'The payment for this order failed.', 'advanced-shipping-settings' ),
				];
		}

		return [
			'reason'  => 'other_status',
			'message' => sprintf( __( 'The order status is "%s".', 'advanced-shipping-settings' ), $label ),
		];
	}

	private static function date( $date ): ?string {
		return $date instanceof \WC_DateTime ? wp_date( 'c', $date->getTimestamp() ) : null;
	}

	private function prepare_order( \WC_Order $order, bool $with_code ): array {
		$shipping  = Pickup_Helper::get_shipping_item( $order );
		$method_id = $shipping ? (string) $shipping->get_method_id() : '';
		$code      = Pickup_Code::get_code( $order );
		$reserved  = Pickup_Helper::get_pickup_date( $order );
		$window    = explode( '-', Pickup_Helper::get_pickup_time( $order ), 2 );
		$logo      = '' !== $method_id ? Pickup_Helper::method_logo_url( $method_id ) : '';

		$data = [
			'id'                   => $order->get_id(),
			'number'               => (string) $order->get_order_number(),
			'status'               => $order->get_status(),
			'status_label'         => Pickup_Helper::status_label( $order->get_status() ),
			'pickup_code'          => $with_code && '' !== $code ? $code : null,
			'has_pickup_code'      => '' !== $code,
			'reservation'          => [
				'date'      => $reserved,
				'day_name'  => '' !== $reserved ? Pickup_Helper::day_name( $reserved ) : '',
				'label'     => Pickup_Helper::rule_date_label( Pickup_Helper::get_rule( $method_id ), $reserved ),
				'time_from' => (string) $window[0],
				'time_to'   => (string) ( $window[1] ?? '' ),
			],
			'shipping'             => [
				'method_id'     => $method_id,
				'method_label'  => Pickup_Helper::get_method_label( $order ),
				'pickup_point'  => Pickup_Helper::get_pickup_point( $order ),
				'city'          => Pickup_Helper::get_city( $order ),
				'logo'          => '' !== $logo ? $logo : null,
				'is_own_pickup' => Pickup_Helper::uses_own_pickup( $order ),
				'first_name'    => (string) $order->get_shipping_first_name(),
				'last_name'     => (string) $order->get_shipping_last_name(),
				'phone'         => method_exists( $order, 'get_shipping_phone' ) ? (string) $order->get_shipping_phone() : '',
			],
			'billing'              => [
				'first_name' => (string) $order->get_billing_first_name(),
				'last_name'  => (string) $order->get_billing_last_name(),
				'company'    => (string) $order->get_billing_company(),
				'phone'      => (string) $order->get_billing_phone(),
				'email'      => (string) $order->get_billing_email(),
			],
			'items'                => [],
			'customer_note'        => (string) $order->get_customer_note(),
			'total'                => wc_format_decimal( $order->get_total(), wc_get_price_decimals() ),
			'currency'             => $order->get_currency(),
			'payment_method_title' => (string) $order->get_payment_method_title(),
			'date_created'         => self::date( $order->get_date_created() ),
			'date_paid'            => self::date( $order->get_date_paid() ),
			'date_completed'       => self::date( $order->get_date_completed() ),
		];

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			$meta    = [];

			foreach ( $item->get_formatted_meta_data( '_', true ) as $entry ) {
				$meta[] = [
					'key'   => wp_strip_all_tags( (string) $entry->display_key ),
					'value' => trim( wp_strip_all_tags( (string) $entry->display_value ) ),
				];
			}

			$data['items'][] = [
				'id'           => $item->get_id(),
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'name'         => wp_strip_all_tags( (string) $item->get_name() ),
				'sku'          => $product ? (string) $product->get_sku() : '',
				'quantity'     => (int) $item->get_quantity(),
				'total'        => wc_format_decimal( (float) $item->get_total() + (float) $item->get_total_tax(), wc_get_price_decimals() ),
				'image'        => $this->get_item_image( $product ),
				'meta'         => $meta,
			];
		}

		return $data;
	}

	private function get_item_image( $product ): ?array {
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		$image_id = (int) $product->get_image_id();
		if ( ! $image_id && $product->get_parent_id() ) {
			$parent   = wc_get_product( $product->get_parent_id() );
			$image_id = $parent ? (int) $parent->get_image_id() : 0;
		}
		if ( ! $image_id ) {
			return null;
		}

		$full      = wp_get_attachment_image_url( $image_id, 'full' );
		$thumbnail = wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );
		if ( ! $full ) {
			return null;
		}

		return [
			'thumbnail' => $thumbnail ? $thumbnail : $full,
			'full'      => $full,
		];
	}
}
