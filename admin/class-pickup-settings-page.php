<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pickup_Settings_Page {

	const SLUG       = 'advanced-shipping-settings-pickup-sms';
	const CAPABILITY = 'manage_options';
	const NONCE      = 'ass_save_pickup_settings';
	const TEST_NONCE = 'ass_pickup_test_sms';

	private static $instance = null;

	private $notices = [];

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_ass_pickup_test_sms', [ $this, 'ajax_test_sms' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::SLUG !== $page ) {
			return;
		}

		wp_enqueue_style( 'ass-pickup-admin', ASS_URL . 'admin/css/pickup-admin.css', [], ASS_VERSION );
		wp_enqueue_script( 'ass-pickup-settings', ASS_URL . 'admin/js/pickup-settings.js', [ 'jquery' ], ASS_VERSION, true );
		wp_localize_script(
			'ass-pickup-settings',
			'assPickupSettings',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::TEST_NONCE ),
				'i18n'    => [
					'sending' => __( 'Sending…', 'advanced-shipping-settings' ),
					'failed'  => __( 'The request failed.', 'advanced-shipping-settings' ),
				],
			]
		);
	}

	public function ajax_test_sms(): void {
		check_ajax_referer( self::TEST_NONCE, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to do this.', 'advanced-shipping-settings' ) ], 403 );
		}

		$number = isset( $_POST['number'] ) ? sanitize_text_field( wp_unslash( $_POST['number'] ) ) : '';
		$result = Pickup_Sms::send_test( $number );

		if ( ! empty( $result['accepted'] ) ) {
			wp_send_json_success( [ 'message' => __( 'BulkGate accepted the test SMS.', 'advanced-shipping-settings' ) ] );
		}

		wp_send_json_error( [ 'message' => sprintf( __( 'Test SMS failed: %s', 'advanced-shipping-settings' ), (string) ( $result['error'] ?? '' ) ) ] );
	}

	private function save(): void {
		if ( ! isset( $_POST['ass_pickup_settings_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ass_pickup_settings_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$raw     = isset( $_POST['pickup'] ) && is_array( $_POST['pickup'] ) ? wp_unslash( $_POST['pickup'] ) : [];
		$current = Pickup_Sms::get_settings();

		$settings = [
			'sms_enabled'     => ! empty( $raw['sms_enabled'] ),
			'bulkgate_app_id' => isset( $raw['bulkgate_app_id'] ) ? preg_replace( '/\D+/', '', (string) $raw['bulkgate_app_id'] ) : '',
			'bulkgate_token'  => $current['bulkgate_token'],
			'sms_sender'      => Pickup_Sms::sanitize_sender( $raw['sms_sender'] ?? '' ),
			'template_code'   => Pickup_Sms::sanitize_template( $raw['template_code'] ?? '' ),
			'template_plain'  => Pickup_Sms::sanitize_template( $raw['template_plain'] ?? '' ),
		];

		$removed   = false;
		$new_token = isset( $raw['bulkgate_token'] ) ? trim( sanitize_text_field( (string) $raw['bulkgate_token'] ) ) : '';

		if ( ! empty( $_POST['ass_remove_bulkgate_token'] ) ) {
			$removed                    = '' !== $current['bulkgate_token'];
			$settings['bulkgate_token'] = '';
		} elseif ( '' === $current['bulkgate_token'] && '' !== $new_token ) {
			$encrypted = Pickup_Sms::encrypt( $new_token );
			if ( '' === $encrypted ) {
				$this->notices[] = [ 'error', __( 'The token could not be encrypted on this server and was not saved. Define ASS_BULKGATE_APPLICATION_TOKEN in wp-config.php instead.', 'advanced-shipping-settings' ) ];
			} else {
				$settings['bulkgate_token'] = $encrypted;
			}
		}

		Pickup_Sms::save_settings( $settings );
		$this->notices[] = [ 'success', $removed ? __( 'The saved token was removed. You can enter a new one now.', 'advanced-shipping-settings' ) : __( 'Pickup & SMS settings saved.', 'advanced-shipping-settings' ) ];
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'advanced-shipping-settings' ) );
		}

		if ( ! empty( $_POST['ass_save_pickup_settings'] ) || ! empty( $_POST['ass_remove_bulkgate_token'] ) ) {
			$this->save();
		}

		$settings    = Pickup_Sms::get_settings();
		$credentials = Pickup_Sms::credentials();
		$variables   = Pickup_Sms::variable_labels();
		$api_base    = rest_url( Pickup_Rest_Api::REST_NAMESPACE );

		if ( $credentials['token_unreadable'] ) {
			$this->notices[] = [ 'error', __( 'The saved BulkGate token can no longer be decrypted (the WordPress security keys changed). Remove it and enter the token again.', 'advanced-shipping-settings' ) ];
		}
		?>
		<div class="wrap ass-admin-page ass-pickup-settings">
			<h1><?php esc_html_e( 'Pickup & SMS', 'advanced-shipping-settings' ); ?></h1>

			<?php foreach ( $this->notices as $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> inline"><p><?php echo esc_html( $notice[1] ); ?></p></div>
			<?php endforeach; ?>

			<form method="post" autocomplete="off">
				<?php wp_nonce_field( self::NONCE, 'ass_pickup_settings_nonce' ); ?>

				<h2 class="title"><?php esc_html_e( 'BulkGate SMS', 'advanced-shipping-settings' ); ?></h2>
				<p class="description"><?php esc_html_e( 'A "Ready for pickup" SMS is only sent after staff confirm it in the SMS dialog. Create an "Advanced API" application in the BulkGate portal (Modules & APIs) to get the ID and token.', 'advanced-shipping-settings' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'SMS sending', 'advanced-shipping-settings' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="pickup[sms_enabled]" value="1" <?php checked( $settings['sms_enabled'] ); ?>>
								<?php esc_html_e( 'Enable pickup SMS', 'advanced-shipping-settings' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ass-bulkgate-app-id"><?php esc_html_e( 'Application ID', 'advanced-shipping-settings' ); ?></label></th>
						<td>
							<?php if ( $credentials['id_from_constant'] ) : ?>
								<code>ASS_BULKGATE_APPLICATION_ID</code>
								<p class="description"><?php esc_html_e( 'Defined in wp-config.php.', 'advanced-shipping-settings' ); ?></p>
							<?php else : ?>
								<input type="text" inputmode="numeric" id="ass-bulkgate-app-id" name="pickup[bulkgate_app_id]" value="<?php echo esc_attr( $settings['bulkgate_app_id'] ); ?>" class="regular-text" autocomplete="off">
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ass-bulkgate-token"><?php esc_html_e( 'Application token', 'advanced-shipping-settings' ); ?></label></th>
						<td>
							<?php if ( $credentials['token_from_constant'] ) : ?>
								<code>ASS_BULKGATE_APPLICATION_TOKEN</code>
								<p class="description"><?php esc_html_e( 'Defined in wp-config.php.', 'advanced-shipping-settings' ); ?></p>
							<?php elseif ( $credentials['token_stored'] ) : ?>
								<input type="text" id="ass-bulkgate-token" value="<?php echo esc_attr( str_repeat( '•', 32 ) ); ?>" class="regular-text" disabled="disabled" autocomplete="off">
								<button type="submit" name="ass_remove_bulkgate_token" value="1" class="button" id="ass-bulkgate-token-remove" data-confirm="<?php esc_attr_e( 'Remove the saved BulkGate token? SMS sending stops until a new token is saved.', 'advanced-shipping-settings' ); ?>"><?php esc_html_e( 'Remove token', 'advanced-shipping-settings' ); ?></button>
								<p class="description"><?php esc_html_e( 'A token is saved. It is stored encrypted and cannot be shown. To change it, remove it first and then enter the new one.', 'advanced-shipping-settings' ); ?></p>
							<?php else : ?>
								<input type="password" id="ass-bulkgate-token" name="pickup[bulkgate_token]" value="" class="regular-text" autocomplete="new-password">
								<p class="description"><?php esc_html_e( 'Stored encrypted in the database and never shown again.', 'advanced-shipping-settings' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ass-sms-sender"><?php esc_html_e( 'Sender name', 'advanced-shipping-settings' ); ?></label></th>
						<td>
							<input type="text" id="ass-sms-sender" name="pickup[sms_sender]" value="<?php echo esc_attr( $settings['sms_sender'] ); ?>" class="regular-text" maxlength="11">
							<p class="description"><?php esc_html_e( 'Up to 11 letters or digits, shown as the SMS sender. The name may need approval in the BulkGate portal. Leave empty to use the BulkGate system number.', 'advanced-shipping-settings' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ass-sms-test-number"><?php esc_html_e( 'Send a test SMS', 'advanced-shipping-settings' ); ?></label></th>
						<td>
							<input type="text" id="ass-sms-test-number" value="" class="regular-text" placeholder="+3706…" autocomplete="off">
							<button type="button" class="button" id="ass-sms-test-send"><?php esc_html_e( 'Send test', 'advanced-shipping-settings' ); ?></button>
							<span id="ass-sms-test-result" class="ass-sms-test-result" aria-live="polite"></span>
							<p class="description"><?php esc_html_e( 'Uses the saved credentials. Save the settings first.', 'advanced-shipping-settings' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'SMS templates', 'advanced-shipping-settings' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Staff can still adjust the text in the SMS dialog before sending. Available variables:', 'advanced-shipping-settings' ); ?>
					<?php foreach ( $variables as $key => $label ) : ?>
						<code title="<?php echo esc_attr( $label ); ?>">{<?php echo esc_html( $key ); ?>}</code>
					<?php endforeach; ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ass-template-code"><?php esc_html_e( 'Orders with a pickup code', 'advanced-shipping-settings' ); ?></label></th>
						<td>
							<textarea id="ass-template-code" name="pickup[template_code]" rows="3" class="large-text ass-sms-template"><?php echo esc_textarea( $settings['template_code'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Used for your own pickup locations.', 'advanced-shipping-settings' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ass-template-plain"><?php esc_html_e( 'Orders without a pickup code', 'advanced-shipping-settings' ); ?></label></th>
						<td>
							<textarea id="ass-template-plain" name="pickup[template_plain]" rows="3" class="large-text ass-sms-template"><?php echo esc_textarea( $settings['template_plain'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Used for every other shipping method, for example bus stations.', 'advanced-shipping-settings' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="description"><?php esc_html_e( 'Accented letters are sent without accents (ė → e), so a message of up to 160 characters stays one SMS.', 'advanced-shipping-settings' ); ?></p>

				<p class="submit">
					<input type="submit" name="ass_save_pickup_settings" class="button button-primary button-large" value="<?php esc_attr_e( 'Save Settings', 'advanced-shipping-settings' ); ?>">
				</p>
			</form>

			<hr>

			<h2 class="title"><?php esc_html_e( 'Pickup API', 'advanced-shipping-settings' ); ?></h2>
			<p><?php esc_html_e( 'External systems use these endpoints with a WooCommerce REST API key (WooCommerce → Settings → Advanced → REST API).', 'advanced-shipping-settings' ); ?></p>
			<table class="widefat striped ass-pickup-api-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Request', 'advanced-shipping-settings' ); ?></th>
						<th><?php esc_html_e( 'Key permission', 'advanced-shipping-settings' ); ?></th>
						<th><?php esc_html_e( 'What it does', 'advanced-shipping-settings' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><code>GET <?php echo esc_html( $api_base ); ?>/orders/code/{code}</code></td>
						<td><?php esc_html_e( 'Read', 'advanced-shipping-settings' ); ?></td>
						<td><?php esc_html_e( 'Returns the order that is ready for pickup with this code, including items and pictures.', 'advanced-shipping-settings' ); ?></td>
					</tr>
					<tr>
						<td><code>POST <?php echo esc_html( $api_base ); ?>/orders/code/{code}/complete</code></td>
						<td><?php esc_html_e( 'Write', 'advanced-shipping-settings' ); ?></td>
						<td><?php esc_html_e( 'Completes the ready-for-pickup order with this code.', 'advanced-shipping-settings' ); ?></td>
					</tr>
					<tr>
						<td><code>GET <?php echo esc_html( $api_base ); ?>/orders/{order_id}/status</code></td>
						<td><?php esc_html_e( 'Read', 'advanced-shipping-settings' ); ?></td>
						<td><?php esc_html_e( 'Explains whether an order is ready for pickup and, if not, why. Never returns the pickup code.', 'advanced-shipping-settings' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}
}
