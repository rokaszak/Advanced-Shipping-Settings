<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Pickup_Orders_Table extends \WP_List_Table {

	private $view;
	private $filters;
	private $thumbs = [];

	public function __construct( array $view, array $filters ) {
		parent::__construct(
			[
				'singular' => 'pickup-order',
				'plural'   => 'pickup-orders',
				'ajax'     => false,
			]
		);
		$this->view    = $view;
		$this->filters = $filters;
	}

	public function get_columns() {
		return [
			'cb'         => '<input type="checkbox" />',
			'order'      => __( 'Order', 'advanced-shipping-settings' ),
			'pickup'     => __( 'Reservation', 'advanced-shipping-settings' ),
			'shipping'   => __( 'Shipping', 'advanced-shipping-settings' ),
			'products'   => __( 'Products', 'advanced-shipping-settings' ),
			'customer'   => __( 'Shipping Info', 'advanced-shipping-settings' ),
			'pickup_sms' => __( 'Pickup code / SMS', 'advanced-shipping-settings' ),
		];
	}

	protected function get_primary_column_name() {
		return 'order';
	}

	protected function get_bulk_actions() {
		return [
			'ass_mark_ready'     => __( 'Change status to ready for pickup', 'advanced-shipping-settings' ),
			'ass_mark_completed' => __( 'Change status to completed', 'advanced-shipping-settings' ),
			'ass_send_sms'       => __( 'Send SMS', 'advanced-shipping-settings' ),
			'ass_export_pdf'     => __( 'Export / print orders PDF', 'advanced-shipping-settings' ),
			'ass_export_products_pdf' => __( 'Export / print products PDF', 'advanced-shipping-settings' ),
		];
	}

	public function prepare_items() {
		$per_page = Pickup_Orders_Page::MAX_ROWS;
		$total    = count( $this->view['rows'] );
		$page     = max( 1, min( $this->get_pagenum(), (int) ceil( $total / $per_page ) ) );

		$this->_column_headers = [ $this->get_columns(), [], [], 'order' ];
		$this->items           = array_slice( $this->view['rows'], ( $page - 1 ) * $per_page, $per_page );

		$this->set_pagination_args(
			[
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			]
		);

		$this->prime_product_images();
	}

	private function prime_product_images(): void {
		$ids = [];
		foreach ( $this->items as $row ) {
			foreach ( (array) ( $row['items'] ?? [] ) as $item ) {
				foreach ( [ 'variation_id', 'product_id' ] as $key ) {
					if ( ! empty( $item[ $key ] ) ) {
						$ids[ (int) $item[ $key ] ] = true;
					}
				}
			}
		}
		if ( ! $ids || ! function_exists( '_prime_post_caches' ) ) {
			return;
		}

		_prime_post_caches( array_keys( $ids ), false, true );

		$images = [];
		foreach ( array_keys( $ids ) as $id ) {
			$image = absint( get_post_meta( $id, '_thumbnail_id', true ) );
			if ( $image ) {
				$images[ $image ] = true;
			}
		}
		if ( $images ) {
			_prime_post_caches( array_keys( $images ), false, true );
		}
	}

	private function item_thumb( array $item ): string {
		$key = (int) $item['variation_id'] . ':' . (int) $item['product_id'];
		if ( isset( $this->thumbs[ $key ] ) ) {
			return $this->thumbs[ $key ];
		}

		$url = '';
		foreach ( [ 'variation_id', 'product_id' ] as $field ) {
			$image = ! empty( $item[ $field ] ) ? absint( get_post_meta( (int) $item[ $field ], '_thumbnail_id', true ) ) : 0;
			if ( $image ) {
				$url = (string) wp_get_attachment_image_url( $image, 'thumbnail' );
				if ( '' !== $url ) {
					break;
				}
			}
		}
		if ( '' === $url && function_exists( 'wc_placeholder_img_src' ) ) {
			$url = (string) wc_placeholder_img_src( 'thumbnail' );
		}

		$this->thumbs[ $key ] = $url;
		return $url;
	}

	protected function get_views() {
		$labels = [
			'pending' => __( 'To prepare', 'advanced-shipping-settings' ),
			'ready'   => __( 'Ready for pickup', 'advanced-shipping-settings' ),
			'all'     => __( 'All', 'advanced-shipping-settings' ),
		];

		$views = [];
		foreach ( $labels as $key => $label ) {
			$views[ $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( Pickup_Orders_Page::url( Pickup_Orders_Page::view_args( $key ) ) ),
				$this->filters['view'] === $key ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $this->view['counts'][ $key ] ) )
			);
		}
		return $views;
	}

	public function no_items() {
		esc_html_e( 'No pickup orders found.', 'advanced-shipping-settings' );
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		?>
		<div class="alignleft actions ass-pickup-filters">
			<label class="screen-reader-text" for="ass-pickup-date"><?php esc_html_e( 'Filter by pickup date', 'advanced-shipping-settings' ); ?></label>
			<select name="pickup_date" id="ass-pickup-date">
				<option value=""><?php esc_html_e( 'All pickup dates', 'advanced-shipping-settings' ); ?></option>
				<?php foreach ( $this->view['dates'] as $date => $count ) : ?>
					<option value="<?php echo esc_attr( $date ); ?>" <?php selected( $this->filters['date'], (string) $date ); ?>>
						<?php
						$label = Pickup_Orders_Page::NO_DATE === (string) $date ? __( 'No date', 'advanced-shipping-settings' ) : $this->format_date( (string) $date );
						echo esc_html( sprintf( '%1$s (%2$s)', $label, number_format_i18n( $count ) ) );
						?>
					</option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="ass-pickup-method"><?php esc_html_e( 'Filter by shipping method', 'advanced-shipping-settings' ); ?></label>
			<select name="pickup_method" id="ass-pickup-method">
				<option value=""><?php esc_html_e( 'All shipping methods', 'advanced-shipping-settings' ); ?></option>
				<?php foreach ( $this->view['methods'] as $method_id => $method ) : ?>
					<option value="<?php echo esc_attr( $method_id ); ?>" <?php selected( $this->filters['method'], (string) $method_id ); ?>>
						<?php echo esc_html( sprintf( '%1$s (%2$s)', '' !== $method['label'] ? $method['label'] : __( 'No shipping method', 'advanced-shipping-settings' ), number_format_i18n( $method['count'] ) ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="ass-pickup-cats"><?php esc_html_e( 'Filter by product categories', 'advanced-shipping-settings' ); ?></label>
			<select name="pickup_cats[]" id="ass-pickup-cats" class="wc-enhanced-select ass-pickup-cats" multiple="multiple" style="width: 420px;" data-placeholder="<?php esc_attr_e( 'Contains products from…', 'advanced-shipping-settings' ); ?>">
				<?php foreach ( $this->view['categories'] as $term_id => $category ) : ?>
					<option value="<?php echo esc_attr( $term_id ); ?>" <?php selected( in_array( (int) $term_id, $this->filters['cats'], true ) ); ?>>
						<?php echo esc_html( sprintf( '%1$s (%2$s)', $category['label'], number_format_i18n( $category['count'] ) ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<?php submit_button( __( 'Filter', 'advanced-shipping-settings' ), '', 'filter_action', false, [ 'id' => 'ass-pickup-filter-submit' ] ); ?>
			<?php if ( '' !== $this->filters['date'] || '' !== $this->filters['method'] || $this->filters['cats'] ) : ?>
				<a class="ass-pickup-clear" href="<?php echo esc_url( Pickup_Orders_Page::url( Pickup_Orders_Page::view_args( $this->filters['view'] ) ) ); ?>"><?php esc_html_e( 'Clear filters', 'advanced-shipping-settings' ); ?></a>
			<?php endif; ?>
			<script type="application/json" id="ass-pickup-facets"><?php echo wp_json_encode( $this->facet_data(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>
		</div>
		<?php
	}

	private function facet_data(): array {
		$rows    = [];
		$methods = [];
		$cats    = [];
		$dates   = [];

		foreach ( $this->view['facet_rows'] as $row ) {
			$date   = '' === $row['date'] ? Pickup_Orders_Page::NO_DATE : $row['date'];
			$rows[] = [ $date, (string) $row['method_id'], array_map( 'intval', array_keys( $row['categories'] ) ) ];

			$dates[ $date ]                        = Pickup_Orders_Page::NO_DATE === $date ? __( 'No date', 'advanced-shipping-settings' ) : $this->format_date( $date );
			$methods[ (string) $row['method_id'] ] = '' !== $row['method_label'] ? $row['method_label'] : __( 'No shipping method', 'advanced-shipping-settings' );
			foreach ( $row['categories'] as $term_id => $name ) {
				$cats[ (int) $term_id ] = $name;
			}
		}

		return [
			'rows'    => $rows,
			'dates'   => $dates,
			'methods' => $methods,
			'cats'    => $cats,
		];
	}

	private function format_date( string $date ): string {
		$day = Pickup_Helper::day_name( $date );
		return '' !== $day ? $date . ' · ' . $day : $date;
	}

	protected function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="order_ids[]" value="%1$d" data-status="%2$s" aria-label="%3$s" />',
			(int) $item['id'],
			esc_attr( $item['status'] ),
			esc_attr( sprintf( __( 'Select order %s', 'advanced-shipping-settings' ), $item['order']->get_order_number() ) )
		);
	}

	protected function column_order( $item ) {
		$order   = $item['order'];
		$actions = [];

		if ( Pickup_Helper::can_become_ready( $order ) ) {
			$actions['ready'] = sprintf(
				'<a href="#" class="ass-pickup-row-action" data-mode="ready" data-order-id="%1$d">%2$s</a>',
				(int) $item['id'],
				esc_html__( 'Ready for pickup', 'advanced-shipping-settings' )
			);
		}

		$output = sprintf(
			'<a href="%1$s" class="row-title"><strong>#%2$s</strong></a>',
			esc_url( $order->get_edit_order_url() ),
			esc_html( $order->get_order_number() )
		);
		$output .= sprintf(
			'<div class="ass-pickup-order-status"><mark class="order-status status-%1$s"><span>%2$s</span></mark></div>',
			esc_attr( sanitize_html_class( $item['status'] ) ),
			esc_html( Pickup_Helper::status_label( $item['status'] ) )
		);

		return $output . $this->row_actions( $actions );
	}

	protected function column_customer( $item ) {
		$order = $item['order'];
		$name  = Pickup_Helper::get_contact_name( $order );
		$phone = Pickup_Helper::get_sms_number( $order );
		$raw   = Pickup_Helper::get_raw_phone( $order );

		$output = '' !== $name ? esc_html( $name ) : '&ndash;';

		if ( $phone['valid'] ) {
			$display = Pickup_Helper::format_phone( $phone['number'] );
			$output .= sprintf( '<br><a class="ass-pickup-phone" href="%1$s">%2$s</a>', esc_url( 'tel:' . $display ), esc_html( $display ) );
		} elseif ( '' !== $raw ) {
			$output .= sprintf(
				'<br><span class="ass-pickup-bad-phone" title="%1$s">%2$s</span>',
				esc_attr__( 'Not a valid mobile number. No SMS can be sent.', 'advanced-shipping-settings' ),
				esc_html( $raw )
			);
		} else {
			$output .= '<br><span class="ass-pickup-bad-phone">' . esc_html__( 'No phone number', 'advanced-shipping-settings' ) . '</span>';
		}

		$email = trim( (string) $order->get_billing_email() );
		if ( '' !== $email ) {
			$output .= sprintf( '<br><a class="ass-pickup-email" href="%1$s">%2$s</a>', esc_url( 'mailto:' . $email ), esc_html( $email ) );
		}

		return $output;
	}

	protected function column_products( $item ) {
		$lines = [];
		foreach ( (array) ( $item['items'] ?? [] ) as $product ) {
			$quantity = (float) $product['qty'];
			$quantity = floor( $quantity ) === $quantity ? (string) (int) $quantity : (string) $quantity;
			$thumb    = $this->item_thumb( $product );
			$lines[]  = sprintf(
				'<li><span class="ass-pickup-item-thumb" aria-hidden="true">%1$s</span><span class="ass-pickup-item-text">%2$s <strong class="ass-pickup-item-qty">&times;&nbsp;%3$s</strong></span></li>',
				'' !== $thumb ? '<img src="' . esc_url( $thumb ) . '" alt="" width="32" height="32">' : '',
				esc_html( wp_strip_all_tags( html_entity_decode( (string) $product['name'], ENT_QUOTES, 'UTF-8' ) ) ),
				esc_html( $quantity )
			);
		}

		return $lines ? '<ul class="ass-pickup-items">' . implode( '', $lines ) . '</ul>' : '&ndash;';
	}

	protected function column_pickup( $item ) {
		if ( '' === $item['date'] ) {
			return '&ndash;';
		}

		$label = Pickup_Helper::rule_date_label( Pickup_Helper::get_rule( (string) $item['method_id'] ), $item['date'] );
		$time  = Pickup_Helper::get_pickup_time_display( $item['order'] );
		$lines = [];

		if ( '' !== $label && $label !== $item['date'] ) {
			$lines[] = '<strong>' . esc_html( $label ) . '</strong>';
			$lines[] = '<span class="description">' . esc_html( $item['date'] ) . '</span>';
		} else {
			$lines[] = '<strong>' . esc_html( $item['date'] ) . '</strong>';
		}
		if ( '' !== $time ) {
			$lines[] = '<span class="ass-pickup-hours">' . esc_html( $time ) . '</span>';
		}

		return implode( '<br>', $lines );
	}

	private function method_logo_url( string $method_id ): string {
		return Pickup_Helper::method_logo_url( $method_id );
	}

	protected function column_shipping( $item ) {
		$point = Pickup_Helper::get_pickup_point( $item['order'] );
		$logo  = '' !== $item['method_id'] ? $this->method_logo_url( (string) $item['method_id'] ) : '';

		$text = '<span class="ass-pickup-method-name">' . ( '' !== $item['method_label'] ? esc_html( $item['method_label'] ) : '&ndash;' ) . '</span>';
		if ( '' !== $point && $point !== $item['method_label'] ) {
			$text .= '<span class="description">' . esc_html( $point ) . '</span>';
		}

		$image = '' !== $logo ? '<img src="' . esc_url( $logo ) . '" alt="">' : '';

		return '<div class="ass-pickup-method"><span class="ass-pickup-method-logo" aria-hidden="true">' . $image . '</span><div class="ass-pickup-method-text">' . $text . '</div></div>';
	}

	protected function column_pickup_sms( $item ) {
		if ( Pickup_Helper::STATUS_READY !== $item['status'] ) {
			return '&ndash;';
		}

		$order    = $item['order'];
		$code     = Pickup_Code::get_code( $order );
		$status   = Pickup_Sms::get_status( $order );
		$was_sent = Pickup_Sms::STATUS_SENT === $status;
		$output   = '' !== $code ? '<div class="ass-pickup-code">' . esc_html( $code ) . '</div>' : '';

		if ( '' !== $status ) {
			$labels  = Pickup_Sms::status_labels();
			$entry   = Pickup_Sms::last_log_entry( $order );
			$output .= sprintf( '<span class="ass-sms-status ass-sms-status-%1$s">%2$s</span>', esc_attr( $status ), esc_html( $labels[ $status ] ) );

			if ( $entry && Pickup_Sms::STATUS_NOT_SENT !== $status ) {
				$detail = $was_sent
					? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] )
					: (string) $entry['error'];
				if ( '' !== $detail ) {
					$output .= '<br><span class="description">' . esc_html( $detail ) . '</span>';
				}
			}
		} elseif ( '' === $code ) {
			$output = '&ndash;';
		}

		$output .= sprintf(
			'<div class="row-actions"><span class="sms"><a href="#" class="ass-pickup-row-action" data-mode="sms" data-resend="%1$d" data-order-id="%2$d">%3$s</a></span></div>',
			$was_sent ? 1 : 0,
			(int) $item['id'],
			esc_html( $was_sent ? __( 'Resend SMS', 'advanced-shipping-settings' ) : __( 'Send SMS', 'advanced-shipping-settings' ) )
		);

		return $output;
	}
}
