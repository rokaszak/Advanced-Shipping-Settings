<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings for translations and holiday dates.
 */
class Plugin_Settings_Page {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Render the plugin settings page.
	 */
	public function render(): void {
		if ( ! empty( $_POST['ass_save_settings'] ) ) {
			$this->save_settings();
		}

		$settings = Settings_Manager::instance()->get_plugin_settings();
		$translations = $settings['translations'] ?? [];
		$holidays = $settings['holiday_dates'] ?? [];

		?>
		<div class="wrap ass-admin-page">
			<h1><?php esc_html_e( 'Plugin Settings', 'advanced-shipping-settings' ); ?></h1>
			
			<form method="post" id="ass-settings-form">
				<?php wp_nonce_field( 'ass_save_settings', 'ass_settings_nonce' ); ?>
				
				<h2 class="title"><?php esc_html_e( 'Translations', 'advanced-shipping-settings' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label><?php esc_html_e( 'ASAP Prefix:', 'advanced-shipping-settings' ); ?> <?php echo \ASS\ass_help_tip( __( 'Shown before calculated ASAP date (e.g. "Delivery no later than").', 'advanced-shipping-settings' ) ); ?></label></th>
						<td>
							<input type="text" name="settings[translations][asap_prefix]" value="<?php echo esc_attr( $translations['asap_prefix'] ?? 'Delivery no later than' ); ?>" class="regular-text">
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Reservation Prompt:', 'advanced-shipping-settings' ); ?> <?php echo \ASS\ass_help_tip( __( 'Shown above date selection radio buttons on checkout.', 'advanced-shipping-settings' ) ); ?></label></th>
						<td>
							<input type="text" name="settings[translations][reservation_prompt]" value="<?php echo esc_attr( $translations['reservation_prompt'] ?? 'Select a reservation date:' ); ?>" class="regular-text">
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Shortcode Label:', 'advanced-shipping-settings' ); ?> <?php echo \ASS\ass_help_tip( __( 'Shown in product page for BY DATE methods (e.g. "Available to reserve:").', 'advanced-shipping-settings' ) ); ?></label></th>
						<td>
							<input type="text" name="settings[translations][shortcode_rezervuoti]" value="<?php echo esc_attr( $translations['shortcode_rezervuoti'] ?? 'Available to reserve:' ); ?>" class="regular-text">
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Date Required Error:', 'advanced-shipping-settings' ); ?> <?php echo \ASS\ass_help_tip( __( 'Validation error if no date is selected during checkout.', 'advanced-shipping-settings' ) ); ?></label></th>
						<td>
							<input type="text" name="settings[translations][error_date_required]" value="<?php echo esc_attr( $translations['error_date_required'] ?? 'Please select a reservation date.' ); ?>" class="regular-text">
						</td>
					</tr>
				</table>

				<h3 class="title"><?php esc_html_e( 'Day Names', 'advanced-shipping-settings' ); ?> <?php echo \ASS\ass_help_tip( __( 'Day names used in ASAP date formatting (e.g. "Monday, 2026-01-14").', 'advanced-shipping-settings' ) ); ?></h3>
				<table class="form-table">
					<tr>
						<th><label><?php esc_html_e( 'Monday:', 'advanced-shipping-settings' ); ?></label></th>
						<td>
							<input type="text" name="settings[translations][day_monday]" value="<?php echo esc_attr( $translations['day_monday'] ?? 'Monday' ); ?>" class="regular-text">
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Tuesday:', 'advanced-shipping-settings' ); ?></label></th>
						<td>
							<input type="text" name="settings[translations][day_tuesday]" value="<?php echo esc_attr( $translations['day_tuesday'] ?? 'Tuesday' ); ?>" class="regular-text">
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Wednesday:', 'advanced-shipping-settings' ); ?></label></th>
						<td>
							<input type="text" name="settings[translations][day_wednesday]" value="<?php echo esc_attr( $translations['day_wednesday'] ?? 'Wednesday' ); ?>" class="regular-text">
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Thursday:', 'advanced-shipping-settings' ); ?></label></th>
						<td>
							<input type="text" name="settings[translations][day_thursday]" value="<?php echo esc_attr( $translations['day_thursday'] ?? 'Thursday' ); ?>" class="regular-text">
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Friday:', 'advanced-shipping-settings' ); ?></label></th>
						<td>
							<input type="text" name="settings[translations][day_friday]" value="<?php echo esc_attr( $translations['day_friday'] ?? 'Friday' ); ?>" class="regular-text">
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Saturday:', 'advanced-shipping-settings' ); ?></label></th>
						<td>
							<input type="text" name="settings[translations][day_saturday]" value="<?php echo esc_attr( $translations['day_saturday'] ?? 'Saturday' ); ?>" class="regular-text">
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Sunday:', 'advanced-shipping-settings' ); ?></label></th>
						<td>
							<input type="text" name="settings[translations][day_sunday]" value="<?php echo esc_attr( $translations['day_sunday'] ?? 'Sunday' ); ?>" class="regular-text">
						</td>
					</tr>
				</table>

				<hr>

				<h2 class="title"><?php esc_html_e( 'Checkout Display', 'advanced-shipping-settings' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label><?php esc_html_e( 'Display Location:', 'advanced-shipping-settings' ); ?> <?php echo \ASS\ass_help_tip( __( 'Choose where the ASAP/reservation date information appears on the checkout page.', 'advanced-shipping-settings' ) ); ?></label></th>
						<td>
							<?php
							$display_location = $settings['display_location'] ?? 'billing';
							?>
							<select name="settings[display_location]">
								<option value="billing" <?php selected( $display_location, 'billing' ); ?>><?php esc_html_e( 'Top of customer info', 'advanced-shipping-settings' ); ?></option>
								<option value="shipping" <?php selected( $display_location, 'shipping' ); ?>><?php esc_html_e( 'Top of shipping info', 'advanced-shipping-settings' ); ?></option>
								<option value="order_review" <?php selected( $display_location, 'order_review' ); ?>><?php esc_html_e( 'Top of order review', 'advanced-shipping-settings' ); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<hr>

				<h2 class="title"><?php esc_html_e( 'Holiday Dates', 'advanced-shipping-settings' ); ?> <?php echo \ASS\ass_help_tip( __( 'Non-working days that will be excluded from ASAP delivery calculations (Mon-Fri). Add national holidays or other non-working days.', 'advanced-shipping-settings' ) ); ?></h2>

				<div class="ass-holidays-repeater">
					<div class="ass-holidays-container">
						<?php foreach ( $holidays as $index => $holiday ) : ?>
							<div class="ass-holiday-row">
								<input type="date" name="settings[holiday_dates][<?php echo $index; ?>][date]" value="<?php echo esc_attr( $holiday['date'] ?? '' ); ?>" required>
								<input type="text" name="settings[holiday_dates][<?php echo $index; ?>][label]" value="<?php echo esc_attr( $holiday['label'] ?? '' ); ?>" placeholder="Label (e.g. Christmas)" class="regular-text">
								<button type="button" class="button remove-holiday-row">×</button>
							</div>
						<?php endforeach; ?>
					</div>
					<button type="button" class="button button-secondary add-holiday-row"><?php esc_html_e( 'Add Holiday', 'advanced-shipping-settings' ); ?></button>
				</div>

				<hr>

				<h2 class="title"><?php esc_html_e( 'Hidden Shipping Methods', 'advanced-shipping-settings' ); ?> <?php echo \ASS\ass_help_tip( __( 'Select shipping methods to hide from the Shipping Rules page. Hidden methods will not be configurable and their existing rules will be removed. Use this to simplify the shipping rules interface by hiding methods you don\'t need to configure.', 'advanced-shipping-settings' ) ); ?></h2>

				<div class="ass-hidden-methods-section">
					<?php
					$shipping_methods = $this->get_available_shipping_methods();
					$hidden_methods = $settings['hidden_methods'] ?? [];

					if ( empty( $shipping_methods ) ) {
						echo '<p>' . esc_html__( 'No shipping methods found.', 'advanced-shipping-settings' ) . '</p>';
					} else {
						foreach ( $shipping_methods as $method_id => $method ) {
							?>
							<label class="ass-method-checkbox">
								<input type="checkbox" name="settings[hidden_methods][]" value="<?php echo esc_attr( $method_id ); ?>" <?php checked( in_array( $method_id, $hidden_methods ) ); ?>>
								<?php echo esc_html( $method['title'] ); ?> <code>(<?php echo esc_html( $method_id ); ?>)</code>
							</label>
							<?php
						}
					}
					?>
				</div>

				<p class="submit">
					<input type="submit" name="ass_save_settings" class="button button-primary button-large" value="<?php esc_attr_e( 'Save Settings', 'advanced-shipping-settings' ); ?>">
				</p>
			</form>
		</div>

		<!-- Template for new holiday rows -->
		<script type="text/template" id="ass-holiday-row-template">
			<div class="ass-holiday-row">
				<input type="date" name="settings[holiday_dates][{index}][date]" required>
				<input type="text" name="settings[holiday_dates][{index}][label]" placeholder="Label" class="regular-text">
				<button type="button" class="button remove-holiday-row">×</button>
			</div>
		</script>
		<?php
	}

	/**
	 * Get all WooCommerce shipping method types.
	 */
	private function get_available_shipping_methods(): array {
		$methods = [];

		$shipping_method_classes = WC()->shipping()->load_shipping_methods();

		foreach ( $shipping_method_classes as $method_class ) {
			if ( ! is_object( $method_class ) ) {
				continue;
			}

			$method_id = $method_class->id ?? '';
			if ( empty( $method_id ) ) {
				continue;
			}

			$methods[ $method_id ] = [
				'title' => $method_class->get_method_title(),
				'id'    => $method_id,
			];
		}

		return $methods;
	}

	/**
	 * Save plugin settings from POST.
	 */
	private function save_settings(): void {
		if ( ! isset( $_POST['ass_settings_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['ass_settings_nonce'] ), 'ass_save_settings' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$raw_settings = isset( $_POST['settings'] ) ? (array) $_POST['settings'] : [];
		$sanitized_settings = [
			'translations'     => [],
			'holiday_dates'    => [],
			'hidden_methods'   => [],
			'display_location' => 'billing',
		];

		if ( isset( $raw_settings['translations'] ) ) {
			foreach ( $raw_settings['translations'] as $key => $value ) {
				$sanitized_settings['translations'][ sanitize_key( $key ) ] = sanitize_text_field( $value );
			}
		}

		if ( isset( $raw_settings['holiday_dates'] ) ) {
			foreach ( $raw_settings['holiday_dates'] as $holiday ) {
				if ( empty( $holiday['date'] ) ) continue;
				$sanitized_settings['holiday_dates'][] = [
					'date'  => sanitize_text_field( $holiday['date'] ),
					'label' => sanitize_text_field( $holiday['label'] ?? '' ),
				];
			}
		}

		if ( isset( $raw_settings['hidden_methods'] ) ) {
			$sanitized_settings['hidden_methods'] = array_map( 'sanitize_text_field', (array) $raw_settings['hidden_methods'] );
		}

		if ( isset( $raw_settings['display_location'] ) ) {
			$allowed_locations = [ 'billing', 'shipping', 'order_review' ];
			$location = sanitize_text_field( $raw_settings['display_location'] );
			$sanitized_settings['display_location'] = in_array( $location, $allowed_locations, true ) ? $location : 'billing';
		}

		Settings_Manager::instance()->save_plugin_settings( $sanitized_settings );

		// Clean up shipping rules for hidden methods
		$this->cleanup_hidden_method_rules( $sanitized_settings['hidden_methods'] );

		add_action( 'admin_notices', function() {
			echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'advanced-shipping-settings' ) . '</p></div>';
		} );
	}

	/**
	 * Clean up shipping rules for hidden methods.
	 */
	private function cleanup_hidden_method_rules( array $hidden_methods ): void {
		if ( empty( $hidden_methods ) ) {
			return;
		}

		$current_rules = Settings_Manager::instance()->get_shipping_rules();

		// Remove rules for hidden methods
		foreach ( $hidden_methods as $method_id ) {
			unset( $current_rules[ $method_id ] );
		}

		Settings_Manager::instance()->save_shipping_rules( $current_rules );
	}
}

