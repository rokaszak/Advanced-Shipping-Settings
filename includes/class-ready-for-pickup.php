<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the "Ready for Pickup" order status and wires the notification email.
 */
class Ready_For_Pickup {

	/** WordPress/WooCommerce status key (wc- prefix); REST API exposes as "ready-for-pickup". */
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
		add_action( 'woocommerce_order_status_ready-for-pickup', [ $this, 'trigger_email' ], 10, 2 );
	}

	/**
	 * Register the custom order status.
	 */
	public function register_order_status(): void {
		register_post_status( self::STATUS_SLUG, [
			'label'                     => _x( 'Ready for Pickup', 'Order status', 'advanced-shipping-settings' ),
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

	/**
	 * Add status to WooCommerce order status list.
	 *
	 * @param array $order_statuses Existing statuses.
	 * @return array
	 */
	public function add_to_order_statuses( array $order_statuses ): array {
		$new = [];
		foreach ( $order_statuses as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'wc-processing' === $key ) {
				$new['wc-ready-for-pickup'] = _x( 'Ready for Pickup', 'Order status', 'advanced-shipping-settings' );
			}
		}
		return $new;
	}

	/**
	 * Add bulk action to change order status to Ready for Pickup.
	 *
	 * @param array $bulk_actions Existing bulk actions.
	 * @return array
	 */
	public function add_bulk_actions( array $bulk_actions ): array {
		$bulk_actions['mark_ready-for-pickup'] = __( 'Change status to ready for pickup', 'advanced-shipping-settings' );
		return $bulk_actions;
	}

	/**
	 * Register the Ready for Pickup email class.
	 *
	 * @param array $emails Existing email classes.
	 * @return array
	 */
	public function register_email_class( array $emails ): array {
		$path = ASS_PATH . 'includes/emails/class-email-ready-for-pickup.php';
		if ( file_exists( $path ) ) {
			$emails['ASS_Email_Ready_For_Pickup'] = require_once $path;
		}
		return $emails;
	}

	/**
	 * Trigger the Ready for Pickup email when order transitions to this status.
	 *
	 * @param int        $order_id Order ID.
	 * @param \WC_Order|null $order   Order object (may be null).
	 */
	public function trigger_email( $order_id, $order = null ): void {
		if ( ! isset( WC()->mailer()->emails['ASS_Email_Ready_For_Pickup'] ) ) {
			return;
		}
		WC()->mailer()->emails['ASS_Email_Ready_For_Pickup']->trigger( $order_id, $order );
	}
}
