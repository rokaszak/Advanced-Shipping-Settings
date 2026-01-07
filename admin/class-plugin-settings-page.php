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
						<th><label><?php esc_html_e( 'ASAP Prefix:', 'advanced-shipping-settings' ); ?></label></th>
						<td>
							<input type="text" name="settings[translations][asap_prefix]" value="<?php echo esc_attr( $translations['asap_prefix'] ?? 'Pristatymas ne vėliau kaip' ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Shown before calculated ASAP date (e.g. "Delivery no later than").', 'advanced-shipping-settings' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Reservation Prompt:', 'advanced-shipping-settings' ); ?></label></th>
						<td>
							<input type="text" name="settings[translations][reservation_prompt]" value="<?php echo esc_attr( $translations['reservation_prompt'] ?? 'Select a reservation date:' ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Shown above date selection radio buttons on checkout.', 'advanced-shipping-settings' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Shortcode Label:', 'advanced-shipping-settings' ); ?></label></th>
						<td>
							<input type="text" name="settings[translations][shortcode_rezervuoti]" value="<?php echo esc_attr( $translations['shortcode_rezervuoti'] ?? 'Rezervuoti galite:' ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Shown in product page for BY DATE methods (e.g. "Available to reserve:").', 'advanced-shipping-settings' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Date Required Error:', 'advanced-shipping-settings' ); ?></label></th>
						<td>
							<input type="text" name="settings[translations][error_date_required]" value="<?php echo esc_attr( $translations['error_date_required'] ?? 'Prašome pasirinkti rezervacijos datą.' ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Validation error if no date is selected during checkout.', 'advanced-shipping-settings' ); ?></p>
						</td>
					</tr>
				</table>

				<hr>

				<h2 class="title"><?php esc_html_e( 'Holiday Dates', 'advanced-shipping-settings' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Non-working days that will be excluded from ASAP delivery calculations (Mon-Fri).', 'advanced-shipping-settings' ); ?> <?php echo \ASS\ass_help_tip( __( 'Add national holidays or other non-working days.', 'advanced-shipping-settings' ) ); ?></p>
				
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
			'translations'  => [],
			'holiday_dates' => [],
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

		Settings_Manager::instance()->save_plugin_settings( $sanitized_settings );

		add_action( 'admin_notices', function() {
			echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'advanced-shipping-settings' ) . '</p></div>';
		} );
	}
}

