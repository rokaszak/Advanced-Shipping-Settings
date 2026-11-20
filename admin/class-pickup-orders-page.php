<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pickup_Orders_Page {

	const SLUG            = 'ass-pickup-orders';
	const CAPABILITY      = 'manage_options';
	const NO_DATE         = 'none';
	const EXPORT_ACTION   = 'ass_pickup_export_pdf';
	const COUNT_TRANSIENT = 'ass_pickup_pending_count';
	const MAX_ROWS        = 1000;

	private static $instance = null;

	private $hook = '';
	private $rows = null;

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
			if ( Pickup_Helper::STATUS_READY !== $row['status'] ) {
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

	public static function view_args( string $view ): array {
		return 'pending' === $view ? [] : [ 'pickup_view' => $view ];
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

		$this->rows = self::sort_rows( $this->build_rows( is_array( $orders ) ? $orders : [] ) );

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

		$rows = [];
		foreach ( $by_id as $order_id => $order ) {
			$method_id = $shipping[ $order_id ]['method_id'] ?? '';
			if ( ! Pickup_Helper::is_reservation_order( $order, $method_id ) ) {
				continue;
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
				'status'       => $order->get_status(),
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
		$view   = isset( $source['pickup_view'] ) && is_scalar( $source['pickup_view'] ) ? sanitize_key( $source['pickup_view'] ) : 'pending';
		$date   = isset( $source['pickup_date'] ) && is_scalar( $source['pickup_date'] ) ? sanitize_text_field( $source['pickup_date'] ) : '';
		$method = isset( $source['pickup_method'] ) && is_scalar( $source['pickup_method'] ) ? sanitize_text_field( $source['pickup_method'] ) : '';
		$cats   = isset( $source['pickup_cats'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) $source['pickup_cats'] ) ) ) ) : [];

		return [
			'view'   => in_array( $view, [ 'all', 'pending', 'ready' ], true ) ? $view : 'pending',
			'date'   => self::NO_DATE === $date || preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '',
			'method' => $method,
			'cats'   => $cats,
		];
	}

	public function current_filters(): array {
		return $this->read_filters( wp_unslash( $_GET ) );
	}

	private function build_view( array $rows, array $filters ): array {
		$counts = [
			'all'     => count( $rows ),
			'pending' => 0,
			'ready'   => 0,
		];
		foreach ( $rows as $row ) {
			$counts[ Pickup_Helper::STATUS_READY === $row['status'] ? 'ready' : 'pending' ]++;
		}

		if ( 'all' !== $filters['view'] ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( array $row ) use ( $filters ): bool {
						$is_ready = Pickup_Helper::STATUS_READY === $row['status'];
						return 'ready' === $filters['view'] ? $is_ready : ! $is_ready;
					}
				)
			);
		}

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
		foreach ( $filters['cats'] as $term_id ) {
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

		set_transient( self::COUNT_TRANSIENT, (int) $view['counts']['pending'], 10 * MINUTE_IN_SECONDS );
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
				<?php if ( 'pending' !== $filters['view'] ) : ?>
					<input type="hidden" name="pickup_view" value="<?php echo esc_attr( $filters['view'] ); ?>">
				<?php endif; ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}
}
