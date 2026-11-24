<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pickup_Orders_Page {

	const SLUG            = 'ass-pickup-orders';
	const CAPABILITY      = 'manage_options';
	const NO_DATE         = 'none';
	const GROUP_OWN       = 'own';
	const NONCE_ACTION    = 'bulk-pickup-orders';
	const EXPORT_ACTION   = 'ass_pickup_export_pdf';
	const COUNT_TRANSIENT = 'ass_pickup_pending_count';
	const MAX_ROWS        = 1000;

	private static $instance = null;

	private $hook   = '';
	private $rows   = null;
	private $groups = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'register_page' ], 60 );
		add_filter( 'woocommerce_screen_ids', [ $this, 'add_screen_id' ] );
		add_action( 'wp_ajax_' . self::EXPORT_ACTION, [ $this, 'export_pdf' ] );

		foreach ( [ 'woocommerce_order_status_changed', 'woocommerce_new_order', 'woocommerce_trash_order', 'woocommerce_untrash_order', 'woocommerce_delete_order' ] as $hook ) {
			add_action( $hook, [ $this, 'forget_pending_count' ], 10, 0 );
		}
	}

	public function forget_pending_count(): void {
		delete_transient( self::COUNT_TRANSIENT );
	}

	private function pending_count(): int {
		$cached = get_transient( self::COUNT_TRANSIENT );
		if ( false !== $cached && is_numeric( $cached ) ) {
			return (int) $cached;
		}

		$count = 0;
		foreach ( $this->collect_rows() as $row ) {
			if ( 'pending' === $row['view'] ) {
				$count++;
			}
		}
		set_transient( self::COUNT_TRANSIENT, $count, 10 * MINUTE_IN_SECONDS );

		return $count;
	}

	public function register_page(): void {
		$title = __( 'Pickup Orders', 'advanced-shipping-settings' );
		$menu  = $title;

		if ( current_user_can( self::CAPABILITY ) && function_exists( 'wc_get_orders' ) ) {
			$count = $this->pending_count();
			if ( $count > 0 ) {
				$menu .= ' <span class="menu-counter count-' . (int) $count . '"><span class="pending-count">' . esc_html( number_format_i18n( $count ) ) . '</span></span>';
			}
		}

		$this->hook = (string) add_submenu_page(
			'woocommerce',
			$title,
			$menu,
			self::CAPABILITY,
			self::SLUG,
			[ $this, 'render' ]
		);

		if ( '' !== $this->hook ) {
			add_action( 'load-' . $this->hook, [ $this, 'on_load' ] );
		}

		$this->move_below_orders();
	}

	private function move_below_orders(): void {
		global $submenu;

		if ( empty( $submenu['woocommerce'] ) || ! is_array( $submenu['woocommerce'] ) ) {
			return;
		}

		$items = array_values( $submenu['woocommerce'] );
		$own   = null;
		$rest  = [];

		foreach ( $items as $item ) {
			if ( isset( $item[2] ) && self::SLUG === $item[2] ) {
				$own = $item;
			} else {
				$rest[] = $item;
			}
		}

		if ( null === $own ) {
			return;
		}

		$position = null;
		foreach ( $rest as $index => $item ) {
			if ( isset( $item[2] ) && in_array( $item[2], [ 'wc-orders', 'edit.php?post_type=shop_order' ], true ) ) {
				$position = $index + 1;
				break;
			}
		}

		if ( null === $position ) {
			return;
		}

		array_splice( $rest, $position, 0, [ $own ] );
		$submenu['woocommerce'] = $rest;
	}

	public function add_screen_id( $screen_ids ) {
		if ( is_array( $screen_ids ) && '' !== $this->hook ) {
			$screen_ids[] = $this->hook;
		}
		return $screen_ids;
	}

	public function on_load(): void {
		$action = $this->requested_action();
		if ( 0 === strpos( $action, 'multiparcels_' ) ) {
			$this->run_external_action( $action );
		}

		$noise = [ '_wp_http_referer', '_wpnonce', 'action', 'action2', 'filter_action', 'order_ids' ];
		foreach ( $noise as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				wp_safe_redirect( remove_query_arg( $noise, wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) );
				exit;
			}
		}

		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'wc-enhanced-select' );
	}

	public static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ), admin_url( 'admin.php' ) );
	}

	public static function view_args( string $group, string $view ): array {
		$args = [];
		if ( self::GROUP_OWN !== $group ) {
			$args['pickup_group'] = $group;
		}
		if ( 'pending' !== $view ) {
			$args['pickup_view'] = $view;
		}
		return $args;
	}

	public static function group_views( string $group ): array {
		return self::GROUP_OWN === $group ? [ 'pending', 'ready' ] : [ 'pending', 'nosms' ];
	}

	private static function orders_screen_id(): string {
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		return $hpos ? 'woocommerce_page_wc-orders' : 'edit-shop_order';
	}

	public static function external_actions(): array {
		$actions = [];
		foreach ( (array) apply_filters( 'bulk_actions-' . self::orders_screen_id(), [] ) as $key => $label ) {
			if ( is_string( $key ) && 0 === strpos( $key, 'multiparcels_' ) && is_scalar( $label ) ) {
				$actions[ $key ] = (string) $label;
			}
		}
		return $actions;
	}

	private function requested_action(): string {
		foreach ( [ 'action', 'action2' ] as $key ) {
			$value = isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : '';
			if ( '' !== $value && '-1' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	private function run_external_action( string $action ): void {
		check_admin_referer( self::NONCE_ACTION );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'advanced-shipping-settings' ) );
		}

		$filters = $this->read_filters( wp_unslash( $_GET ) );
		$back    = self::url( self::view_args( $filters['group'], $filters['view'] ) );
		$ids     = isset( $_GET['order_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_GET['order_ids'] ) ) ) ) ) : [];

		if ( ! $ids || ! isset( self::external_actions()[ $action ] ) ) {
			wp_safe_redirect( $back );
			exit;
		}

		$target = apply_filters( 'handle_bulk_actions-' . self::orders_screen_id(), $back, $action, $ids );
		wp_safe_redirect( is_string( $target ) && '' !== $target ? $target : $back );
		exit;
	}

	public function groups(): array {
		if ( null !== $this->groups ) {
			return $this->groups;
		}

		$own    = Pickup_Helper::own_locations();
		$today  = wp_date( 'Y-m-d' );
		$others = [];

		foreach ( (array) Settings_Manager::instance()->get_shipping_rules() as $method_id => $rule ) {
			$method_id = (string) $method_id;
			if ( '' === $method_id || ! is_array( $rule ) || 'by_date' !== ( $rule['type'] ?? '' ) || isset( $own[ $method_id ] ) ) {
				continue;
			}
			foreach ( (array) ( $rule['dates'] ?? [] ) as $date_info ) {
				if ( is_array( $date_info ) && (string) ( $date_info['date'] ?? '' ) >= $today ) {
					$others[ $method_id ] = '';
					break;
				}
			}
		}

		foreach ( $this->collect_rows() as $row ) {
			if ( self::GROUP_OWN !== $row['group'] && '' === ( $others[ $row['group'] ] ?? '' ) ) {
				$others[ $row['group'] ] = (string) $row['method_label'];
			}
		}

		$this->groups = [ self::GROUP_OWN => __( 'Cafes', 'advanced-shipping-settings' ) ];
		foreach ( $others as $method_id => $label ) {
			$this->groups[ $method_id ] = '' !== $label ? $label : Pickup_Helper::method_display_name( $method_id, self::method_title( $method_id ) );
		}

		return $this->groups;
	}

	private static function method_title( string $method_id ): string {
		$methods = function_exists( 'WC' ) && WC()->shipping() ? WC()->shipping()->get_shipping_methods() : [];
		$method  = $methods[ $method_id ] ?? null;
		return is_object( $method ) && method_exists( $method, 'get_method_title' ) ? (string) $method->get_method_title() : $method_id;
	}

	private function collect_rows(): array {
		if ( null !== $this->rows ) {
			return $this->rows;
		}

		$orders = wc_get_orders(
			[
				'type'    => 'shop_order',
				'status'  => Pickup_Helper::listed_statuses(),
				'limit'   => -1,
				'orderby' => 'ID',
				'order'   => 'ASC',
			]
		);

		$completed = wc_get_orders(
			[
				'type'       => 'shop_order',
				'status'     => [ 'completed' ],
				'limit'      => -1,
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'meta_query' => [
					[
						'key'     => 'deliver_by_date',
						'value'   => wp_date( 'Y-m-d' ),
						'compare' => '>=',
					],
				],
			]
		);

		$this->rows = self::sort_rows( $this->build_rows( array_merge( is_array( $orders ) ? $orders : [], is_array( $completed ) ? $completed : [] ) ) );

		return $this->rows;
	}

	private function rows_for_ids( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return [];
		}

		$orders = wc_get_orders(
			[
				'type'     => 'shop_order',
				'status'   => Pickup_Helper::listed_statuses(),
				'post__in' => $ids,
				'limit'    => -1,
			]
		);

		$wanted = array_flip( $ids );
		$picked = [];
		foreach ( is_array( $orders ) ? $orders : [] as $order ) {
			if ( $order instanceof \WC_Order && isset( $wanted[ $order->get_id() ] ) ) {
				$picked[] = $order;
			}
		}

		return $this->build_rows( $picked );
	}

	private static function sort_rows( array $rows ): array {
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$date_a = '' === $a['date'] ? '9999-99-99' : $a['date'];
				$date_b = '' === $b['date'] ? '9999-99-99' : $b['date'];
				return [ $date_a, $a['id'] ] <=> [ $date_b, $b['id'] ];
			}
		);
		return $rows;
	}

	private function build_rows( array $orders ): array {
		global $wpdb;

		if ( empty( $orders ) ) {
			return [];
		}

		$by_id = [];
		foreach ( $orders as $order ) {
			if ( $order instanceof \WC_Order ) {
				$by_id[ $order->get_id() ] = $order;
			}
		}

		$shipping = [];
		$products = [];
		$items    = [];

		foreach ( array_chunk( array_keys( $by_id ), 500 ) as $chunk ) {
			$in      = implode( ',', array_map( 'absint', $chunk ) );
			$results = $wpdb->get_results(
				"SELECT i.order_id, i.order_item_id, i.order_item_type, i.order_item_name,
					MAX( CASE WHEN m.meta_key = '_product_id' THEN m.meta_value END ) AS product_id,
					MAX( CASE WHEN m.meta_key = '_variation_id' THEN m.meta_value END ) AS variation_id,
					MAX( CASE WHEN m.meta_key = '_qty' THEN m.meta_value END ) AS qty,
					MAX( CASE WHEN m.meta_key = 'method_id' THEN m.meta_value END ) AS method_id
				FROM {$wpdb->prefix}woocommerce_order_items i
				LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta m ON m.order_item_id = i.order_item_id AND m.meta_key IN ( '_product_id', '_variation_id', '_qty', 'method_id' )
				WHERE i.order_id IN ( {$in} ) AND i.order_item_type IN ( 'line_item', 'shipping' )
				GROUP BY i.order_item_id, i.order_id, i.order_item_type, i.order_item_name
				ORDER BY i.order_item_id ASC"
			);

			foreach ( (array) $results as $row ) {
				$order_id = (int) $row->order_id;
				if ( 'shipping' === $row->order_item_type ) {
					if ( ! isset( $shipping[ $order_id ] ) ) {
						$shipping[ $order_id ] = [
							'method_id' => (string) $row->method_id,
							'name'      => (string) $row->order_item_name,
						];
					}
					continue;
				}

				$items[ $order_id ][] = [
					'name'         => (string) $row->order_item_name,
					'qty'          => null === $row->qty || '' === $row->qty ? 1.0 : (float) $row->qty,
					'product_id'   => (int) $row->product_id,
					'variation_id' => (int) $row->variation_id,
				];
				if ( (int) $row->product_id > 0 ) {
					$products[ $order_id ][] = (int) $row->product_id;
				}
			}
		}

		$all_products = [];
		foreach ( $products as $list ) {
			foreach ( $list as $product_id ) {
				$all_products[ $product_id ] = true;
			}
		}

		$categories = [];
		if ( $all_products ) {
			$terms = wp_get_object_terms( array_keys( $all_products ), 'product_cat', [ 'fields' => 'all_with_object_id' ] );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$categories[ (int) $term->object_id ][ (int) $term->term_id ] = (string) $term->name;
				}
			}
		}

		$own_locations = Pickup_Helper::own_locations();

		$rows = [];
		foreach ( $by_id as $order_id => $order ) {
			$method_id = $shipping[ $order_id ]['method_id'] ?? '';
			if ( ! Pickup_Helper::is_reservation_order( $order, $method_id ) ) {
				continue;
			}

			$is_own = '' === $method_id || isset( $own_locations[ $method_id ] );
			$status = $order->get_status();
			if ( 'completed' === $status ) {
				if ( $is_own || Pickup_Sms::STATUS_SENT === Pickup_Sms::get_status( $order ) ) {
					continue;
				}
				$view = 'nosms';
			} else {
				$view = $is_own && Pickup_Helper::STATUS_READY === $status ? 'ready' : 'pending';
			}

			$order_categories = [];
			foreach ( $products[ $order_id ] ?? [] as $product_id ) {
				foreach ( $categories[ $product_id ] ?? [] as $term_id => $name ) {
					$order_categories[ $term_id ] = $name;
				}
			}

			$rows[] = [
				'order'        => $order,
				'id'           => $order_id,
				'status'       => $status,
				'group'        => $is_own ? self::GROUP_OWN : $method_id,
				'view'         => $view,
				'date'         => Pickup_Helper::get_pickup_date( $order ),
				'method_id'    => $method_id,
				'method_label' => Pickup_Helper::method_display_name( $method_id, $shipping[ $order_id ]['name'] ?? '' ),
				'categories'   => $order_categories,
				'items'        => $items[ $order_id ] ?? [],
			];
		}

		return $rows;
	}

	private function read_filters( array $source ): array {
		$group  = isset( $source['pickup_group'] ) && is_scalar( $source['pickup_group'] ) ? sanitize_text_field( $source['pickup_group'] ) : '';
		$group  = '' !== $group ? $group : self::GROUP_OWN;
		$view   = isset( $source['pickup_view'] ) && is_scalar( $source['pickup_view'] ) ? sanitize_key( $source['pickup_view'] ) : 'pending';
		$date   = isset( $source['pickup_date'] ) && is_scalar( $source['pickup_date'] ) ? sanitize_text_field( $source['pickup_date'] ) : '';
		$method = isset( $source['pickup_method'] ) && is_scalar( $source['pickup_method'] ) ? sanitize_text_field( $source['pickup_method'] ) : '';
		$cats   = isset( $source['pickup_cats'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) $source['pickup_cats'] ) ) ) ) : [];
		$xcats  = isset( $source['pickup_xcats'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) $source['pickup_xcats'] ) ) ) ) : [];

		return [
			'group'  => $group,
			'view'   => in_array( $view, self::group_views( $group ), true ) ? $view : 'pending',
			'date'   => self::NO_DATE === $date || preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '',
			'method' => self::GROUP_OWN === $group ? $method : '',
			'cats'   => $cats,
			'xcats'  => array_values( array_diff( $xcats, $cats ) ),
		];
	}

	public function current_filters(): array {
		$filters = $this->read_filters( wp_unslash( $_GET ) );
		if ( ! isset( $this->groups()[ $filters['group'] ] ) ) {
			$filters['group']  = self::GROUP_OWN;
			$filters['view']   = 'pending';
			$filters['method'] = '';
		}
		return $filters;
	}

	private function build_view( array $rows, array $filters ): array {
		$counts = [];
		foreach ( $rows as $row ) {
			$counts[ $row['group'] ][ $row['view'] ] = ( $counts[ $row['group'] ][ $row['view'] ] ?? 0 ) + 1;
		}

		$rows = array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $filters ): bool {
					return $row['group'] === $filters['group'] && $row['view'] === $filters['view'];
				}
			)
		);

		$facet_rows = $rows;

		$dates = [];
		foreach ( $rows as $row ) {
			$key           = '' === $row['date'] ? self::NO_DATE : $row['date'];
			$dates[ $key ] = ( $dates[ $key ] ?? 0 ) + 1;
		}
		if ( '' !== $filters['date'] ) {
			if ( ! isset( $dates[ $filters['date'] ] ) ) {
				$dates[ $filters['date'] ] = 0;
			}
			$rows = array_values(
				array_filter(
					$rows,
					static function ( array $row ) use ( $filters ): bool {
						return ( '' === $row['date'] ? self::NO_DATE : $row['date'] ) === $filters['date'];
					}
				)
			);
		}
		ksort( $dates );

		$methods = [];
		foreach ( $rows as $row ) {
			if ( ! isset( $methods[ $row['method_id'] ] ) ) {
				$methods[ $row['method_id'] ] = [
					'label' => $row['method_label'],
					'count' => 0,
				];
			}
			$methods[ $row['method_id'] ]['count']++;
		}
		if ( '' !== $filters['method'] ) {
			if ( ! isset( $methods[ $filters['method'] ] ) ) {
				$methods[ $filters['method'] ] = [
					'label' => $filters['method'],
					'count' => 0,
				];
			}
			$rows = array_values(
				array_filter(
					$rows,
					static function ( array $row ) use ( $filters ): bool {
						return $row['method_id'] === $filters['method'];
					}
				)
			);
		}

		$categories = [];
		foreach ( $rows as $row ) {
			foreach ( $row['categories'] as $term_id => $name ) {
				if ( ! isset( $categories[ $term_id ] ) ) {
					$categories[ $term_id ] = [
						'label' => $name,
						'count' => 0,
					];
				}
				$categories[ $term_id ]['count']++;
			}
		}
		foreach ( array_merge( $filters['cats'], $filters['xcats'] ) as $term_id ) {
			if ( ! isset( $categories[ $term_id ] ) ) {
				$term                   = get_term( $term_id, 'product_cat' );
				$categories[ $term_id ] = [
					'label' => $term && ! is_wp_error( $term ) ? $term->name : '#' . $term_id,
					'count' => 0,
				];
			}
		}
		if ( $filters['cats'] ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( array $row ) use ( $filters ): bool {
						return ! array_diff( $filters['cats'], array_keys( $row['categories'] ) );
					}
				)
			);
		}
		if ( $filters['xcats'] ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( array $row ) use ( $filters ): bool {
						return ! array_intersect( $filters['xcats'], array_keys( $row['categories'] ) );
					}
				)
			);
		}
		uasort(
			$categories,
			static function ( array $a, array $b ): int {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);

		return [
			'rows'       => $rows,
			'facet_rows' => $facet_rows,
			'counts'     => $counts,
			'groups'     => $this->groups(),
			'dates'      => $dates,
			'methods'    => $methods,
			'categories' => $categories,
		];
	}

	private function export_event( array $event ): void {
		echo wp_json_encode( $event ) . "\n";
		flush();
	}

	public function export_pdf(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to do this.', 'advanced-shipping-settings' ) ], 403 );
		}
		check_ajax_referer( self::EXPORT_ACTION, 'nonce' );

		if ( ! function_exists( 'mb_strlen' ) || ! function_exists( 'wc_get_orders' ) || ! is_readable( ASS_PATH . 'lib/tfpdf/tfpdf.php' ) ) {
			wp_send_json_error( [ 'message' => __( 'The PDF export is not available on this server (the PHP mbstring extension is required).', 'advanced-shipping-settings' ) ], 500 );
		}

		$raw     = isset( $_POST['order_ids'] ) && is_scalar( $_POST['order_ids'] ) ? (string) wp_unslash( $_POST['order_ids'] ) : '';
		$ids     = array_slice( array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) ) ), 0, 5000 );
		$filters = $this->read_filters( wp_unslash( $_POST ) );
		$shown   = isset( $_POST['shown'] ) && is_scalar( $_POST['shown'] ) ? absint( $_POST['shown'] ) : 0;
		$kind    = isset( $_POST['kind'] ) && 'products' === $_POST['kind'] ? 'products' : 'orders';

		if ( ! $ids ) {
			wp_send_json_error( [ 'message' => __( 'Select at least one order first.', 'advanced-shipping-settings' ) ], 400 );
		}

		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 300 );
		}
		if ( function_exists( 'ini_set' ) ) {
			ini_set( 'zlib.output_compression', '0' );
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/x-ndjson; charset=utf-8' );
		header( 'X-Accel-Buffering: no' );
		header( 'X-Content-Type-Options: nosniff' );

		$total    = count( $ids );
		$done     = 0;
		$rows     = [];
		$pdf      = '';
		$switched = false;

		$this->export_event( [ 'stage' => 'orders', 'done' => 0, 'total' => $total ] );

		try {
			foreach ( array_chunk( $ids, 50 ) as $chunk ) {
				$rows  = array_merge( $rows, $this->rows_for_ids( $chunk ) );
				$done += count( $chunk );
				$this->export_event( [ 'stage' => 'orders', 'done' => $done, 'total' => $total ] );
			}

			$rows = self::sort_rows( $rows );
			if ( ! $rows ) {
				$this->export_event( [ 'stage' => 'error', 'message' => __( 'None of the selected orders can be exported. Reload the list and try again.', 'advanced-shipping-settings' ) ] );
				exit;
			}

			$switched = switch_to_locale( get_locale() );

			$progress = function ( int $written, int $count ): void {
				$this->export_event( [ 'stage' => 'pdf', 'done' => $written, 'total' => $count ] );
			};

			require_once ASS_PATH . 'admin/class-pickup-pdf.php';
			if ( 'products' === $kind ) {
				require_once ASS_PATH . 'admin/class-pickup-products-pdf.php';
				$pdf = Pickup_Products_Pdf::render( $rows, $filters, $shown, $progress );
			} else {
				$pdf = Pickup_Pdf::render( $rows, $filters, $shown, $progress );
			}
		} catch ( \Throwable $error ) {
			$pdf = '';
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'Pickup PDF export failed: ' . $error->getMessage(), [ 'source' => 'advanced-shipping-settings' ] );
			}
		}

		if ( $switched ) {
			restore_previous_locale();
		}

		if ( '' === $pdf ) {
			$this->export_event( [ 'stage' => 'error', 'message' => __( 'The PDF could not be created. The reason was written to the WooCommerce log.', 'advanced-shipping-settings' ) ] );
			exit;
		}

		$this->export_event(
			[
				'stage' => 'file',
				'name'  => ( 'products' === $kind ? 'produkcija-' : 'isdavimo-lapas-' ) . wp_date( 'Y-m-d-Hi' ) . '.pdf',
				'count' => count( $rows ),
				'data'  => base64_encode( $pdf ),
			]
		);
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'advanced-shipping-settings' ) );
		}

		require_once ASS_PATH . 'admin/class-pickup-orders-table.php';

		$filters = $this->current_filters();
		$view    = $this->build_view( $this->collect_rows(), $filters );
		$table   = new Pickup_Orders_Table( $view, $filters );
		$table->prepare_items();

		$pending = 0;
		foreach ( $view['counts'] as $group_counts ) {
			$pending += (int) ( $group_counts['pending'] ?? 0 );
		}
		set_transient( self::COUNT_TRANSIENT, $pending, 10 * MINUTE_IN_SECONDS );
		?>
		<div class="wrap ass-pickup-orders">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Pickup Orders', 'advanced-shipping-settings' ); ?></h1>
			<hr class="wp-header-end">
			<?php if ( ! Pickup_Sms::is_configured() ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php esc_html_e( 'SMS sending is not set up yet. Orders can be marked ready for pickup, but no SMS will be sent.', 'advanced-shipping-settings' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Pickup_Settings_Page::SLUG ) ); ?>"><?php esc_html_e( 'Open Pickup & SMS settings', 'advanced-shipping-settings' ); ?></a>
					</p>
				</div>
			<?php endif; ?>
			<?php $table->views(); ?>
			<form method="get" id="ass-pickup-orders-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<?php foreach ( self::view_args( $filters['group'], $filters['view'] ) as $name => $value ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
				<?php endforeach; ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}
}
