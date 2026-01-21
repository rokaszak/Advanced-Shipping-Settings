<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Widgets admin page for free shipping widget settings.
 */
class Widgets_Page {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Render the widgets admin page.
	 */
	public function render(): void {
		if ( ! empty( $_POST['ass_save_widget_settings'] ) ) {
			$this->save_settings();
		}

		$settings = Settings_Manager::instance()->get_widget_settings();

		?>
		<div class="wrap ass-admin-page">
			<h1><?php esc_html_e( 'Widgets', 'advanced-shipping-settings' ); ?></h1>
			
			<form method="post" id="ass-widgets-form">
				<?php wp_nonce_field( 'ass_save_widget_settings', 'ass_widget_settings_nonce' ); ?>
				
				<h2 class="title"><?php esc_html_e( 'Free Shipping Widget', 'advanced-shipping-settings' ); ?></h2>
				
				<table class="form-table">
					<tr>
						<th><label><?php esc_html_e( 'Shortcode:', 'advanced-shipping-settings' ); ?> <?php echo \ASS\ass_help_tip( __( 'Copy this shortcode and paste it anywhere you want to display the free shipping progress widget.', 'advanced-shipping-settings' ) ); ?></label></th>
						<td>
							<input type="text" value="[ass_free_shipping_widget]" readonly class="regular-text" id="ass-widget-shortcode">
							<button type="button" class="button" onclick="document.getElementById('ass-widget-shortcode').select(); document.execCommand('copy'); this.textContent='<?php esc_attr_e( 'Copied!', 'advanced-shipping-settings' ); ?>'; setTimeout(() => this.textContent='<?php esc_attr_e( 'Copy', 'advanced-shipping-settings' ); ?>', 2000);"><?php esc_html_e( 'Copy', 'advanced-shipping-settings' ); ?></button>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Enable Widget:', 'advanced-shipping-settings' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" name="widget_settings[enabled]" value="1" <?php checked( $settings['enabled'], true ); ?>>
								<?php esc_html_e( 'Enable free shipping widget', 'advanced-shipping-settings' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Display in Cart:', 'advanced-shipping-settings' ); ?> <?php echo \ASS\ass_help_tip( __( 'Automatically display the widget on the cart page before the order total. You can still use the shortcode manually on other pages.', 'advanced-shipping-settings' ) ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" name="widget_settings[display_in_cart]" value="1" <?php checked( $settings['display_in_cart'], true ); ?>>
								<?php esc_html_e( 'Automatically display widget on cart page', 'advanced-shipping-settings' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<hr>

				<h3 class="title"><?php esc_html_e( 'Widget Text', 'advanced-shipping-settings' ); ?></h3>
				<table class="form-table">
					<tr>
						<th><label><?php esc_html_e( 'Widget Title:', 'advanced-shipping-settings' ); ?></label></th>
						<td>
							<input type="text" name="widget_settings[texts][title]" value="<?php echo esc_attr( $settings['texts']['title'] ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Title displayed at the top of the widget (e.g. "Nemokamo pristatymo progresas").', 'advanced-shipping-settings' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Progress Message Template:', 'advanced-shipping-settings' ); ?></label></th>
						<td>
							<input type="text" name="widget_settings[texts][progress_template]" value="<?php echo esc_attr( $settings['texts']['progress_template'] ); ?>" class="large-text">
							<p class="description"><?php esc_html_e( 'Use {remaining} for remaining amount and {threshold} for threshold amount (e.g. "Iki nemokamo pristatymo trūksta {remaining} (iš {threshold}).").', 'advanced-shipping-settings' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Already Free Message:', 'advanced-shipping-settings' ); ?></label></th>
						<td>
							<input type="text" name="widget_settings[texts][already_free]" value="<?php echo esc_attr( $settings['texts']['already_free'] ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Message shown when cart total reaches the threshold (e.g. "Nemokamas pristatymas jau pritaikytas!").', 'advanced-shipping-settings' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'No Threshold Message:', 'advanced-shipping-settings' ); ?></label></th>
						<td>
							<input type="text" name="widget_settings[texts][no_threshold]" value="<?php echo esc_attr( $settings['texts']['no_threshold'] ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Message shown when shipping method has no free shipping threshold set.', 'advanced-shipping-settings' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'No Method Selected Message:', 'advanced-shipping-settings' ); ?></label></th>
						<td>
							<input type="text" name="widget_settings[texts][no_method]" value="<?php echo esc_attr( $settings['texts']['no_method'] ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Message shown when no shipping method is selected.', 'advanced-shipping-settings' ); ?></p>
						</td>
					</tr>
				</table>

				<hr>

				<h3 class="title"><?php esc_html_e( 'Widget Behavior', 'advanced-shipping-settings' ); ?></h3>
				<table class="form-table">
					<tr>
						<th><label><?php esc_html_e( 'Use Pre-Discount Total:', 'advanced-shipping-settings' ); ?> <?php echo \ASS\ass_help_tip( __( 'If enabled, widget calculates progress using cart subtotal before discounts. If disabled (default), uses cart total after discounts.', 'advanced-shipping-settings' ) ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" name="widget_settings[use_pre_discount]" value="1" <?php checked( $settings['use_pre_discount'], true ); ?>>
								<?php esc_html_e( 'Use pre-discount total for threshold calculation', 'advanced-shipping-settings' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'When No Threshold Set:', 'advanced-shipping-settings' ); ?></label></th>
						<td>
							<label>
								<input type="radio" name="widget_settings[hide_no_threshold]" value="1" <?php checked( $settings['hide_no_threshold'], true ); ?>>
								<?php esc_html_e( 'Hide widget', 'advanced-shipping-settings' ); ?>
							</label><br>
							<label>
								<input type="radio" name="widget_settings[hide_no_threshold]" value="0" <?php checked( $settings['hide_no_threshold'], false ); ?>>
								<?php esc_html_e( 'Show message', 'advanced-shipping-settings' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'When Shipping Already Free:', 'advanced-shipping-settings' ); ?></label></th>
						<td>
							<label>
								<input type="radio" name="widget_settings[hide_already_free]" value="1" <?php checked( $settings['hide_already_free'], true ); ?>>
								<?php esc_html_e( 'Hide widget', 'advanced-shipping-settings' ); ?>
							</label><br>
							<label>
								<input type="radio" name="widget_settings[hide_already_free]" value="0" <?php checked( $settings['hide_already_free'], false ); ?>>
								<?php esc_html_e( 'Show message', 'advanced-shipping-settings' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<p class="submit">
					<input type="submit" name="ass_save_widget_settings" class="button button-primary button-large" value="<?php esc_attr_e( 'Save Settings', 'advanced-shipping-settings' ); ?>">
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Save widget settings from POST.
	 */
	private function save_settings(): void {
		if ( ! isset( $_POST['ass_widget_settings_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['ass_widget_settings_nonce'] ), 'ass_save_widget_settings' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$raw_settings = isset( $_POST['widget_settings'] ) ? (array) $_POST['widget_settings'] : [];
		$sanitized_settings = [
			'enabled' => ! empty( $raw_settings['enabled'] ),
			'display_in_cart' => ! empty( $raw_settings['display_in_cart'] ),
			'use_pre_discount' => ! empty( $raw_settings['use_pre_discount'] ),
			'hide_no_threshold' => ! empty( $raw_settings['hide_no_threshold'] ),
			'hide_already_free' => ! empty( $raw_settings['hide_already_free'] ),
			'texts' => [
				'title' => sanitize_text_field( $raw_settings['texts']['title'] ?? 'Nemokamo pristatymo progresas' ),
				'progress_template' => sanitize_text_field( $raw_settings['texts']['progress_template'] ?? 'Iki nemokamo pristatymo trūksta {remaining} (iš {threshold}).' ),
				'already_free' => sanitize_text_field( $raw_settings['texts']['already_free'] ?? 'Nemokamas pristatymas jau pritaikytas!' ),
				'no_threshold' => sanitize_text_field( $raw_settings['texts']['no_threshold'] ?? 'Šiam pristatymo būdui nemokamo siuntimo akcija nėra taikoma' ),
				'no_method' => sanitize_text_field( $raw_settings['texts']['no_method'] ?? 'Pasirinkite pristatymo būdą.' ),
			],
		];

		Settings_Manager::instance()->save_widget_settings( $sanitized_settings );

		add_action( 'admin_notices', function() {
			echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'advanced-shipping-settings' ) . '</p></div>';
		} );
	}
}
