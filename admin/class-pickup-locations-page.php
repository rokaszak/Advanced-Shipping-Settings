<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page for managing pickup locations.
 */
class Pickup_Locations_Page {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Render the pickup locations admin page.
	 */
	public function render(): void {
		if ( ! empty( $_POST['ass_save_pickup_locations'] ) ) {
			$this->save_locations();
		}

		$locations = Settings_Manager::instance()->get_pickup_locations();

		?>
		<div class="wrap ass-admin-page">
			<h1><?php esc_html_e( 'Pickup Locations', 'advanced-shipping-settings' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Create custom free shipping methods for pickup locations. After creating a location, you must manually add it to your Shipping Zones in WooCommerce settings.', 'advanced-shipping-settings' ); ?>
			</p>
			
			<form method="post" id="ass-pickup-locations-form">
				<?php wp_nonce_field( 'ass_save_pickup_locations', 'ass_pickup_locations_nonce' ); ?>
				
				<div class="ass-pickup-locations-repeater">
					<table class="widefat ass-pickup-locations-table">
						<thead>
							<tr>
								<th style="width: 30%;"><?php esc_html_e( 'Location Name', 'advanced-shipping-settings' ); ?></th>
								<th style="width: 30%;"><?php esc_html_e( 'Method ID (slug)', 'advanced-shipping-settings' ); ?></th>
								<th style="width: 30%;"><?php esc_html_e( 'Logo/Icon', 'advanced-shipping-settings' ); ?></th>
								<th style="width: 10%;"><?php esc_html_e( 'Actions', 'advanced-shipping-settings' ); ?></th>
							</tr>
						</thead>
						<tbody class="ass-pickup-locations-container">
							<?php foreach ( $locations as $index => $location ) : ?>
								<tr class="ass-pickup-location-row">
									<td>
										<input type="text" name="locations[<?php echo $index; ?>][name]" value="<?php echo esc_attr( $location['name'] ?? '' ); ?>" class="wide-text" required placeholder="e.g. Store Pickup - Downtown">
									</td>
									<td>
										<input type="text" name="locations[<?php echo $index; ?>][method_id]" value="<?php echo esc_attr( $location['method_id'] ?? '' ); ?>" class="wide-text ass-method-id-input" required placeholder="e.g. pickup_downtown">
										<p class="description"><?php esc_html_e( 'Must be unique and alphanumeric (use underscores).', 'advanced-shipping-settings' ); ?></p>
									</td>
									<td>
										<div class="ass-image-picker">
											<div class="ass-image-preview">
												<?php 
												$image_id = $location['image_id'] ?? '';
												$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
												if ( $image_url ) : ?>
													<img src="<?php echo esc_url( $image_url ); ?>" style="max-width: 50px; height: auto; display: block; margin-bottom: 5px;">
												<?php endif; ?>
											</div>
											<input type="hidden" name="locations[<?php echo $index; ?>][image_id]" value="<?php echo esc_attr( $image_id ); ?>" class="ass-image-id">
											<button type="button" class="button ass-upload-button"><?php echo $image_id ? esc_html__( 'Change', 'advanced-shipping-settings' ) : esc_html__( 'Select', 'advanced-shipping-settings' ); ?></button>
											<button type="button" class="button ass-remove-image-button <?php echo $image_id ? '' : 'hidden'; ?>"><?php esc_html_e( 'Remove', 'advanced-shipping-settings' ); ?></button>
										</div>
									</td>
									<td>
										<button type="button" class="button remove-pickup-row">×</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p>
						<button type="button" class="button button-secondary add-pickup-row"><?php esc_html_e( 'Add Pickup Location', 'advanced-shipping-settings' ); ?></button>
					</p>
				</div>

				<p class="submit">
					<input type="submit" name="ass_save_pickup_locations" class="button button-primary button-large" value="<?php esc_attr_e( 'Save Pickup Locations', 'advanced-shipping-settings' ); ?>">
				</p>
			</form>
		</div>

		<!-- Template for new pickup rows -->
		<script type="text/template" id="ass-pickup-location-row-template">
			<tr class="ass-pickup-location-row">
				<td>
					<input type="text" name="locations[{index}][name]" class="wide-text" required placeholder="e.g. Store Pickup - Downtown">
				</td>
				<td>
					<input type="text" name="locations[{index}][method_id]" class="wide-text ass-method-id-input" required placeholder="e.g. pickup_downtown">
					<p class="description"><?php esc_html_e( 'Must be unique and alphanumeric (use underscores).', 'advanced-shipping-settings' ); ?></p>
				</td>
				<td>
					<div class="ass-image-picker">
						<div class="ass-image-preview"></div>
						<input type="hidden" name="locations[{index}][image_id]" class="ass-image-id">
						<button type="button" class="button ass-upload-button"><?php esc_html_e( 'Select', 'advanced-shipping-settings' ); ?></button>
						<button type="button" class="button ass-remove-image-button hidden"><?php esc_html_e( 'Remove', 'advanced-shipping-settings' ); ?></button>
					</div>
				</td>
				<td>
					<button type="button" class="button remove-pickup-row">×</button>
				</td>
			</tr>
		</script>
		<?php
	}

	/**
	 * Save pickup locations from POST.
	 */
	private function save_locations(): void {
		if ( ! isset( $_POST['ass_pickup_locations_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['ass_pickup_locations_nonce'] ), 'ass_save_pickup_locations' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$raw_locations = isset( $_POST['locations'] ) ? (array) $_POST['locations'] : [];
		$sanitized_locations = [];

		foreach ( $raw_locations as $location ) {
			if ( empty( $location['name'] ) || empty( $location['method_id'] ) ) {
				continue;
			}

			$sanitized_locations[] = [
				'name'      => sanitize_text_field( $location['name'] ),
				'method_id' => sanitize_key( $location['method_id'] ),
				'image_id'  => absint( $location['image_id'] ?? 0 ),
			];
		}

		Settings_Manager::instance()->save_pickup_locations( $sanitized_locations );

		add_action( 'admin_notices', function() {
			echo '<div class="updated"><p>' . esc_html__( 'Pickup locations saved.', 'advanced-shipping-settings' ) . '</p></div>';
		} );
	}
}

