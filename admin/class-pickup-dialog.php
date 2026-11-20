<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pickup_Dialog {

	const NONCE        = 'ass_pickup_dialog';
	const SAVE_NONCE   = 'ass_pickup_order_save';
	const CAPABILITY   = 'edit_shop_orders';
	const MAX_PREVIEW  = 1000;
	const MAX_PROCESS  = 10;

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_ass_pickup_preview', [ $this, 'ajax_preview' ] );
		add_action( 'wp_ajax_ass_pickup_process', [ $this, 'ajax_process' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ], 20 );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ], 100, 2 );
		add_action( 'woocommerce_process_shop_order_meta', [ $this, 'after_order_save' ], 60, 1 );
	}

	private function screen_context(): array {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return [ 'context' => '' ];
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( Pickup_Orders_Page::SLUG === $page ) {
			return [ 'context' => 'pickup_page' ];
		}

		$hpos_screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : '';

		if ( '' !== $hpos_screen && $hpos_screen === $screen->id ) {
			$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
			if ( 'edit' === $action && ! empty( $_GET['id'] ) ) {
				return [
					'context'  => 'order_edit',
					'order_id' => absint( $_GET['id'] ),
				];
			}
			return 'new' === $action ? [ 'context' => '' ] : [ 'context' => 'orders_list' ];
		}

		if ( 'edit-shop_order' === $screen->id ) {
			return [ 'context' => 'orders_list' ];
		}

		if ( 'shop_order' === $screen->id && 'post' === $screen->base ) {
			global $post;
			if ( $post && ! empty( $post->ID ) && 'add' !== $screen->action ) {
				return [
					'context'  => 'order_edit',
					'order_id' => (int) $post->ID,
				];
			}
		}

		return [ 'context' => '' ];
	}

	public function enqueue(): void {
		$context = $this->screen_context();
		if ( '' === $context['context'] || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		wp_enqueue_style( 'ass-pickup-admin', ASS_URL . 'admin/css/pickup-admin.css', [], ASS_VERSION );
		wp_enqueue_script( 'ass-pickup-dialog', ASS_URL . 'admin/js/pickup-dialog.js', [ 'jquery' ], ASS_VERSION, true );

		$data = [
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( self::NONCE ),
			'context'     => $context['context'],
			'readyStatus' => Ready_For_Pickup::STATUS_SLUG,
			'bulkAction'  => 'mark_ready-for-pickup',
			'chunkSize'   => 4,
			'limits'      => [
				'single'   => Sms_Text::SINGLE_LIMIT,
				'part'     => Sms_Text::PART_LIMIT,
				'maxParts' => Sms_Text::MAX_PARTS,
			],
			'i18n'        => [
				'titleReady'      => __( 'Mark ready for pickup', 'advanced-shipping-settings' ),
				'titleSms'        => __( 'Send pickup SMS', 'advanced-shipping-settings' ),
				'titleComplete'   => __( 'Change status to completed', 'advanced-shipping-settings' ),
				'loading'         => __( 'Loading…', 'advanced-shipping-settings' ),
				'noSelection'     => __( 'Select at least one order first.', 'advanced-shipping-settings' ),
				'requestFailed'   => __( 'The request failed. Check your connection and try again.', 'advanced-shipping-settings' ),
				'cancel'          => __( 'Cancel', 'advanced-shipping-settings' ),
				'close'           => __( 'Close', 'advanced-shipping-settings' ),
				'readyAndSms'     => __( 'Mark ready and send SMS', 'advanced-shipping-settings' ),
				'readyOnly'       => __( 'Mark ready without SMS', 'advanced-shipping-settings' ),
				'sendSms'         => __( 'Send SMS', 'advanced-shipping-settings' ),
				'message'         => __( 'Message', 'advanced-shipping-settings' ),
				'insertVariable'  => __( 'Insert variable:', 'advanced-shipping-settings' ),
				'preview'         => __( 'Preview', 'advanced-shipping-settings' ),
				'previewFor'      => __( 'Example recipient: %s', 'advanced-shipping-settings' ),
				'anotherExample'  => __( 'Show another example', 'advanced-shipping-settings' ),
				'reset'           => __( 'Reset to saved template', 'advanced-shipping-settings' ),
				'groupCode'       => __( 'Orders with a pickup code', 'advanced-shipping-settings' ),
				'groupPlain'      => __( 'Orders without a pickup code', 'advanced-shipping-settings' ),
				'recipients'      => __( '%d recipient(s)', 'advanced-shipping-settings' ),
				'summaryReady'    => __( '%d order(s) will be marked ready for pickup.', 'advanced-shipping-settings' ),
				'summarySms'      => __( '%d order(s) selected for SMS.', 'advanced-shipping-settings' ),
				'summaryComplete' => __( '%d order(s) will be marked completed.', 'advanced-shipping-settings' ),
				'completeConfirm' => __( 'Change status to completed', 'advanced-shipping-settings' ),
				'doneCompleted'   => __( 'Marked completed: %d', 'advanced-shipping-settings' ),
				'skipped'         => __( '%d order(s) will be skipped:', 'advanced-shipping-settings' ),
				'noPhone'         => __( '%d order(s) have no valid mobile number and will not receive an SMS:', 'advanced-shipping-settings' ),
				'onHold'          => __( '%d order(s) are on hold (payment not confirmed).', 'advanced-shipping-settings' ),
				'notConfigured'   => __( 'SMS sending is not set up yet, so no SMS will be sent. Configure BulkGate under Shipping Settings → Pickup & SMS.', 'advanced-shipping-settings' ),
				'nothingToDo'     => __( 'None of the selected orders can be processed.', 'advanced-shipping-settings' ),
				'counterSingle'   => __( '%1$d / %2$d characters · 1 SMS', 'advanced-shipping-settings' ),
				'counterMulti'    => __( '%1$d characters · sent as %2$d SMS', 'advanced-shipping-settings' ),
				'counterLongest'  => __( '(longest for these recipients: %1$d characters, %2$d SMS)', 'advanced-shipping-settings' ),
				'tooLong'         => __( 'The message is too long. Shorten it before sending.', 'advanced-shipping-settings' ),
				'emptyMessage'    => __( 'The message is empty.', 'advanced-shipping-settings' ),
				'unknownVariable' => __( 'Unknown variable: %s', 'advanced-shipping-settings' ),
				'emptyVariable'   => __( '%1$s is empty for %2$d recipient(s).', 'advanced-shipping-settings' ),
				'progress'        => __( 'Processing %1$d of %2$d…', 'advanced-shipping-settings' ),
				'doneTitle'       => __( 'Done', 'advanced-shipping-settings' ),
				'doneReady'       => __( 'Marked ready for pickup: %d', 'advanced-shipping-settings' ),
				'doneSmsSent'     => __( 'SMS sent: %d', 'advanced-shipping-settings' ),
				'doneSmsFailed'   => __( 'SMS failed: %d', 'advanced-shipping-settings' ),
				'doneNoPhone'     => __( 'No valid mobile number: %d', 'advanced-shipping-settings' ),
				'doneErrors'      => __( 'Not processed: %d', 'advanced-shipping-settings' ),
				'orderLine'       => __( 'Order #%1$s: %2$s', 'advanced-shipping-settings' ),
				'reload'          => __( 'Reload', 'advanced-shipping-settings' ),
				'sender'          => __( 'SMS', 'advanced-shipping-settings' ),
				'exportTitle'     => __( 'Export PDF', 'advanced-shipping-settings' ),
				'exportStarting'  => __( 'Starting…', 'advanced-shipping-settings' ),
				'exportLoading'   => __( 'Loading orders: %1$d of %2$d', 'advanced-shipping-settings' ),
				'exportWriting'   => __( 'Writing PDF: %1$d of %2$d', 'advanced-shipping-settings' ),
				'exportDone'      => __( 'Done. The PDF with %d order(s) was downloaded.', 'advanced-shipping-settings' ),
				'exportAgain'     => __( 'Download again', 'advanced-shipping-settings' ),
				'exportFailed'    => __( 'The PDF could not be created.', 'advanced-shipping-settings' ),
			],
		];

		if ( 'order_edit' === $context['context'] ) {
			$order = wc_get_order( $context['order_id'] );
			if ( ! $order instanceof \WC_Order ) {
				return;
			}
			$data['order'] = [
				'id'        => $order->get_id(),
				'status'    => 'wc-' . $order->get_status(),
				'saveNonce' => wp_create_nonce( self::SAVE_NONCE . '_' . $order->get_id() ),
			];
		}

		if ( 'pickup_page' === $context['context'] && current_user_can( Pickup_Orders_Page::CAPABILITY ) ) {
			$data['export'] = [
				'action'  => Pickup_Orders_Page::EXPORT_ACTION,
				'nonce'   => wp_create_nonce( Pickup_Orders_Page::EXPORT_ACTION ),
				'filters' => Pickup_Orders_Page::instance()->current_filters(),
			];
		}

		$credentials         = Pickup_Sms::credentials();
		$data['senderName']  = '' !== $credentials['sender'] ? $credentials['sender'] : $data['i18n']['sender'];

		wp_localize_script( 'ass-pickup-dialog', 'assPickup', $data );
	}

	private function verify_ajax(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to do this.', 'advanced-shipping-settings' ) ], 403 );
		}
	}

	private function posted_ids( int $limit ): array {
		$raw = isset( $_POST['order_ids'] ) ? wp_unslash( $_POST['order_ids'] ) : [];
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $raw ) ) ) );
		return array_slice( $ids, 0, $limit );
	}

	private function posted_mode(): string {
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'ready';
		return in_array( $mode, [ 'sms', 'edit', 'complete' ], true ) ? $mode : 'ready';
	}

	private function skip_reason( \WC_Order $order, string $mode, bool $allow_resend ): string {
		$status = $order->get_status();
		if ( 'edit' === $mode ) {
			return '';
		}
		if ( 'ready' === $mode ) {
			if ( Pickup_Helper::can_become_ready( $order ) ) {
				return '';
			}
			if ( Pickup_Helper::STATUS_READY === $status ) {
				return __( 'already ready for pickup', 'advanced-shipping-settings' );
			}
			return sprintf( __( 'status is "%s"', 'advanced-shipping-settings' ), Pickup_Helper::status_label( $status ) );
		}
		if ( Pickup_Helper::STATUS_READY === $status ) {
			if ( 'sms' === $mode && ! $allow_resend && Pickup_Sms::STATUS_SENT === Pickup_Sms::get_status( $order ) ) {
				return __( 'SMS already sent. Use "Resend SMS" on the order to send it again.', 'advanced-shipping-settings' );
			}
			return '';
		}
		return sprintf( __( 'not ready for pickup (status "%s")', 'advanced-shipping-settings' ), Pickup_Helper::status_label( $status ) );
	}

	public function ajax_preview(): void {
		$this->verify_ajax();

		$mode   = $this->posted_mode();
		$ids    = $this->posted_ids( self::MAX_PREVIEW );
		$resend = ! empty( $_POST['resend'] ) && 1 === count( $ids );
		$groups = [
			Pickup_Sms::GROUP_CODE  => [
				'template'   => Pickup_Sms::template_for( Pickup_Sms::GROUP_CODE ),
				'recipients' => [],
			],
			Pickup_Sms::GROUP_PLAIN => [
				'template'   => Pickup_Sms::template_for( Pickup_Sms::GROUP_PLAIN ),
				'recipients' => [],
			],
		];

		$eligible = [];
		$skipped  = [];
		$on_hold  = 0;

		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() ) {
				continue;
			}

			$reason = $this->skip_reason( $order, $mode, $resend );
			if ( '' !== $reason ) {
				$skipped[] = [
					'number' => (string) $order->get_order_number(),
					'reason' => $reason,
				];
				continue;
			}

			if ( 'complete' === $mode ) {
				$eligible[] = $order->get_id();
				continue;
			}

			if ( 'on-hold' === $order->get_status() ) {
				$on_hold++;
			}

			if ( Pickup_Helper::uses_own_pickup( $order ) && in_array( $order->get_status(), Pickup_Code::holding_statuses(), true ) ) {
				Pickup_Code::ensure_code( $order );
			}

			$phone      = Pickup_Helper::get_sms_number( $order );
			$eligible[] = $order->get_id();

			$groups[ Pickup_Sms::group_for( $order ) ]['recipients'][] = [
				'id'      => $order->get_id(),
				'number'  => (string) $order->get_order_number(),
				'name'    => Pickup_Helper::get_contact_name( $order ),
				'email'   => (string) $order->get_billing_email(),
				'phone'   => $phone['valid'] ? Pickup_Helper::format_phone( $phone['number'] ) : Pickup_Helper::get_raw_phone( $order ),
				'phoneOk' => (bool) $phone['valid'],
				'vars'    => Pickup_Sms::variables( $order ),
			];
		}

		wp_send_json_success(
			[
				'mode'       => $mode,
				'configured' => Pickup_Sms::is_configured(),
				'eligible'   => $eligible,
				'skipped'    => $skipped,
				'onHold'     => $on_hold,
				'groups'     => $groups,
				'variables'  => Pickup_Sms::variable_labels(),
			]
		);
	}

	private function posted_templates(): array {
		$raw       = isset( $_POST['templates'] ) && is_array( $_POST['templates'] ) ? wp_unslash( $_POST['templates'] ) : [];
		$templates = [];
		foreach ( [ Pickup_Sms::GROUP_CODE, Pickup_Sms::GROUP_PLAIN ] as $group ) {
			$templates[ $group ] = isset( $raw[ $group ] ) ? Pickup_Sms::sanitize_template( $raw[ $group ] ) : '';
		}
		return $templates;
	}

	public function ajax_process(): void {
		$this->verify_ajax();

		$mode      = in_array( $this->posted_mode(), [ 'sms', 'complete' ], true ) ? $this->posted_mode() : 'ready';
		$ids       = $this->posted_ids( self::MAX_PROCESS );
		$send_sms  = 'complete' !== $mode && ! empty( $_POST['send_sms'] ) && Pickup_Sms::is_configured();
		$note      = ! empty( $_POST['bulk'] ) ? __( 'Order status changed by bulk edit.', 'woocommerce' ) : '';
		$templates = $this->posted_templates();
		$resend    = ! empty( $_POST['resend'] ) && 1 === count( $ids );
		$results   = [];

		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 120 );
		}

		if ( 'sms' !== $mode && function_exists( 'WC' ) ) {
			WC()->payment_gateways();
		}

		foreach ( $ids as $id ) {
			$result = [
				'id'        => $id,
				'number'    => (string) $id,
				'ready'     => false,
				'completed' => false,
				'sms'       => '',
				'error'     => '',
			];

			try {
				$order = wc_get_order( $id );
				if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() ) {
					$result['error'] = __( 'Order not found.', 'advanced-shipping-settings' );
					$results[]       = $result;
					continue;
				}

				$result['number'] = (string) $order->get_order_number();

				$reason = $this->skip_reason( $order, $mode, $resend );
				if ( '' !== $reason ) {
					$result['error'] = $reason;
					$results[]       = $result;
					continue;
				}

				if ( 'sms' !== $mode ) {
					$new_status = 'complete' === $mode ? 'completed' : Pickup_Helper::STATUS_READY;
					$order->update_status( $new_status, $note, true );
					do_action( 'woocommerce_order_edit_status', $order->get_id(), $new_status );
					if ( $new_status !== $order->get_status() ) {
						$result['error'] = __( 'The status could not be changed.', 'advanced-shipping-settings' );
						$results[]       = $result;
						continue;
					}
					$result[ 'complete' === $mode ? 'completed' : 'ready' ] = true;
				}

				if ( $send_sms ) {
					$template        = $templates[ Pickup_Sms::group_for( $order ) ];
					$sms             = Pickup_Sms::send( $order, '' !== $template ? $template : null );
					$result['sms']   = $sms['status'];
					$result['error'] = Pickup_Sms::STATUS_SENT === $sms['status'] ? '' : (string) $sms['error'];
				}
			} catch ( \Throwable $error ) {
				$result['error'] = __( 'Unexpected error while processing the order.', 'advanced-shipping-settings' );
				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->error( 'Pickup processing failed for order ' . $id . ': ' . $error->getMessage(), [ 'source' => 'advanced-shipping-settings' ] );
				}
			}

			$results[] = $result;
		}

		wp_send_json_success( [ 'results' => $results ] );
	}

	public function after_order_save( $order_id ): void {
		$order_id = absint( $order_id );
		if ( ! $order_id || empty( $_POST['ass_pickup_sms_send'] ) || empty( $_POST['ass_pickup_sms_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ass_pickup_sms_nonce'] ) ), self::SAVE_NONCE . '_' . $order_id ) || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		if ( ! Pickup_Sms::is_configured() ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || Pickup_Helper::STATUS_READY !== $order->get_status() || Pickup_Sms::STATUS_SENT === Pickup_Sms::get_status( $order ) ) {
			return;
		}

		$template = isset( $_POST['ass_pickup_sms_template'] ) ? Pickup_Sms::sanitize_template( wp_unslash( $_POST['ass_pickup_sms_template'] ) ) : '';

		try {
			Pickup_Sms::send( $order, '' !== $template ? $template : null );
		} catch ( \Throwable $error ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'Pickup SMS failed for order ' . $order_id . ': ' . $error->getMessage(), [ 'source' => 'advanced-shipping-settings' ] );
			}
		}
	}

	public function add_meta_box( $screen_or_post_type, $post_or_order = null ): void {
		$order = null;
		if ( $post_or_order instanceof \WC_Order ) {
			$order = $post_or_order;
		} elseif ( $post_or_order instanceof \WP_Post && 'shop_order' === $post_or_order->post_type ) {
			$order = wc_get_order( $post_or_order->ID );
		}

		if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() ) {
			return;
		}

		$has_content = '' !== Pickup_Code::get_code( $order ) || Pickup_Helper::STATUS_READY === $order->get_status() || Pickup_Sms::get_log( $order );
		if ( ! $has_content ) {
			return;
		}

		$screens = [ 'shop_order' ];
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		global $wp_meta_boxes;

		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'ass_pickup_box', __( 'Pickup', 'advanced-shipping-settings' ), [ $this, 'render_meta_box' ], $screen, 'side', 'high' );

			$boxes = $wp_meta_boxes[ $screen ]['side']['high'] ?? null;
			if ( ! is_array( $boxes ) || ! isset( $boxes['ass_pickup_box'] ) ) {
				continue;
			}

			$own = $boxes['ass_pickup_box'];
			unset( $boxes['ass_pickup_box'] );

			$sorted = [];
			$placed = false;
			foreach ( $boxes as $id => $box ) {
				$sorted[ $id ] = $box;
				if ( 'woocommerce-order-actions' === $id ) {
					$sorted['ass_pickup_box'] = $own;
					$placed                   = true;
				}
			}

			$wp_meta_boxes[ $screen ]['side']['high'] = $placed ? $sorted : array_merge( [ 'ass_pickup_box' => $own ], $boxes );
		}
	}

	public function render_meta_box( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( is_object( $post_or_order ) ? $post_or_order->ID : $post_or_order );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$code       = Pickup_Code::get_code( $order );
		$sms_status = Pickup_Sms::get_status( $order );
		$labels     = Pickup_Sms::status_labels();
		$entry      = Pickup_Sms::last_log_entry( $order );
		$is_ready   = Pickup_Helper::STATUS_READY === $order->get_status();
		$time       = Pickup_Helper::get_pickup_time_display( $order );
		?>
		<div class="ass-pickup-box">
			<?php if ( '' !== $code ) : ?>
				<p class="ass-pickup-box-label"><?php esc_html_e( 'Pickup code', 'advanced-shipping-settings' ); ?></p>
				<p class="ass-pickup-box-code"><?php echo esc_html( $code ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $time ) : ?>
				<p><strong><?php esc_html_e( 'Pickup time:', 'advanced-shipping-settings' ); ?></strong> <?php echo esc_html( $time ); ?></p>
			<?php endif; ?>
			<p>
				<strong><?php esc_html_e( 'SMS:', 'advanced-shipping-settings' ); ?></strong>
				<?php if ( '' !== $sms_status ) : ?>
					<span class="ass-sms-status ass-sms-status-<?php echo esc_attr( $sms_status ); ?>"><?php echo esc_html( $labels[ $sms_status ] ); ?></span>
				<?php else : ?>
					&ndash;
				<?php endif; ?>
			</p>
			<?php if ( $entry ) : ?>
				<p class="description">
					<?php
					echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) );
					if ( ! empty( $entry['number'] ) ) {
						echo ' &middot; ' . esc_html( Pickup_Helper::format_phone( (string) $entry['number'] ) );
					}
					?>
					<br>
					<?php echo esc_html( ! empty( $entry['error'] ) ? (string) $entry['error'] : (string) ( $entry['text'] ?? '' ) ); ?>
				</p>
			<?php endif; ?>
			<?php if ( $is_ready ) : ?>
				<p>
					<button type="button" class="button ass-pickup-send-sms" data-resend="<?php echo Pickup_Sms::STATUS_SENT === $sms_status ? '1' : '0'; ?>" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
						<?php echo esc_html( Pickup_Sms::STATUS_SENT === $sms_status ? __( 'Resend SMS…', 'advanced-shipping-settings' ) : __( 'Send SMS…', 'advanced-shipping-settings' ) ); ?>
					</button>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
