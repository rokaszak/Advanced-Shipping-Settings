<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ready_For_Pickup {

	const STATUS_SLUG = 'wc-ready-for-pickup';

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', [ $this, 'register_order_status' ] );
		add_filter( 'wc_order_statuses', [ $this, 'add_to_order_statuses' ] );
		add_filter( 'bulk_actions-edit-shop_order', [ $this, 'add_bulk_actions' ], 50, 1 );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', [ $this, 'add_bulk_actions' ], 50, 1 );
		add_filter( 'woocommerce_email_classes', [ $this, 'register_email_class' ] );
		add_action( 'woocommerce_order_status_ready-for-pickup', [ $this, 'prepare_ready_order' ], 5, 2 );
		add_action( 'woocommerce_order_status_ready-for-pickup', [ $this, 'trigger_email' ], 10, 2 );
	}

	public function register_order_status(): void {
		register_post_status( self::STATUS_SLUG, [
			'label'                     => Pickup_Helper::ready_status_label(),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop(
				'Ready for Pickup <span class="count">(%s)</span>',
				'Ready for Pickup <span class="count">(%s)</span>',
				'advanced-shipping-settings'
			),
		] );
	}

	public function add_to_order_statuses( array $order_statuses ): array {
		$new = [];
		foreach ( $order_statuses as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'wc-processing' === $key ) {
				$new[ self::STATUS_SLUG ] = Pickup_Helper::ready_status_label();
			}
		}
		if ( ! isset( $new[ self::STATUS_SLUG ] ) ) {
			$new[ self::STATUS_SLUG ] = Pickup_Helper::ready_status_label();
		}
		return $new;
	}

	public function add_bulk_actions( array $bulk_actions ): array {
		$bulk_actions['mark_ready-for-pickup'] = __( 'Change status to ready for pickup', 'advanced-shipping-settings' );
		return $bulk_actions;
	}

	public function register_email_class( array $emails ): array {
		$path = ASS_PATH . 'includes/emails/class-email-ready-for-pickup.php';
		if ( file_exists( $path ) ) {
			$emails['ASS_Email_Ready_For_Pickup'] = require_once $path;
		}
		return $emails;
	}

	public function prepare_ready_order( $order_id, $order = null ): void {
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		try {
			if ( Pickup_Helper::uses_own_pickup( $order ) ) {
				Pickup_Code::ensure_code( $order );
			}
			$order->update_meta_data( Pickup_Helper::META_READY_AT, time() );
			$order->update_meta_data( Pickup_Sms::META_STATUS, Pickup_Sms::STATUS_NOT_SENT );
			$order->save();
		} catch ( \Throwable $error ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'Pickup preparation failed for order ' . $order->get_id() . ': ' . $error->getMessage(), [ 'source' => 'advanced-shipping-settings' ] );
			}
		}
	}

	public function trigger_email( $order_id, $order = null ): void {
		if ( ! isset( WC()->mailer()->emails['ASS_Email_Ready_For_Pickup'] ) ) {
			return;
		}
		WC()->mailer()->emails['ASS_Email_Ready_For_Pickup']->trigger( $order_id, $order );
	}
}
