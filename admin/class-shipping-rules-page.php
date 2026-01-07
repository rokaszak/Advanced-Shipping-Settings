<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page for configuring shipping method rules.
 */
class Shipping_Rules_Page {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Render the shipping rules admin page.
	 */
	public function render(): void {
		if ( ! empty( $_POST['ass_save_rules'] ) ) {
			$this->save_rules();
		}

		$shipping_methods = $this->get_available_shipping_methods();
		$categories       = $this->get_all_product_categories();
		$current_rules    = Settings_Manager::instance()->get_shipping_rules();

		?>
		<div class="wrap ass-admin-page">
			<h1><?php esc_html_e( 'Shipping Rules', 'advanced-shipping-settings' ); ?></h1>
			
			<div class="ass-rules-layout">
				<form method="post" id="ass-rules-form">
					<?php wp_nonce_field( 'ass_save_rules', 'ass_rules_nonce' ); ?>
					
					<div class="ass-main-content">
						<div class="ass-methods-list">
							<?php foreach ( $shipping_methods as $method_id => $method ) : 
								$rule = $current_rules[ $method_id ] ?? [ 'type' => 'asap' ];
								?>
								<div class="ass-method-card" data-method-id="<?php echo esc_attr( $method_id ); ?>">
									<div class="ass-method-header">
										<h3><?php echo esc_html( $method['title'] ); ?> <span class="method-id">(<?php echo esc_html( $method_id ); ?>)</span></h3>
										
										<div class="ass-method-type-toggle">
											<label>
												<input type="radio" name="rules[<?php echo esc_attr( $method_id ); ?>][type]" value="asap" <?php checked( $rule['type'], 'asap' ); ?> class="ass-type-toggle">
												<?php esc_html_e( 'ASAP', 'advanced-shipping-settings' ); ?>
											</label>
											<label>
												<input type="radio" name="rules[<?php echo esc_attr( $method_id ); ?>][type]" value="by_date" <?php checked( $rule['type'], 'by_date' ); ?> class="ass-type-toggle">
												<?php esc_html_e( 'BY DATE', 'advanced-shipping-settings' ); ?>
											</label>
										</div>
									</div>

									<div class="ass-settings-panes">
										<!-- ASAP Pane -->
										<div class="ass-pane ass-pane-asap <?php echo 'asap' === $rule['type'] ? '' : 'hidden'; ?>">
											<div class="ass-field">
												<label><?php esc_html_e( 'Sending Days:', 'advanced-shipping-settings' ); ?> <?php echo \ASS\ass_help_tip( __( 'Select days when packages are sent out.', 'advanced-shipping-settings' ) ); ?></label>
												<div class="ass-days-grid">
													<?php 
													$days = [ 1 => 'Mo', 2 => 'Tu', 3 => 'We', 4 => 'Th', 5 => 'Fr', 6 => 'Sa', 7 => 'Su' ];
													$selected_days = $rule['sending_days'] ?? [];
													foreach ( $days as $num => $label ) : ?>
														<label><input type="checkbox" name="rules[<?php echo esc_attr( $method_id ); ?>][sending_days][]" value="<?php echo $num; ?>" <?php checked( in_array( $num, $selected_days ) ); ?>> <?php echo $label; ?></label>
													<?php endforeach; ?>
												</div>
											</div>
											<div class="ass-field">
												<label><?php esc_html_e( 'Max ship time (work days):', 'advanced-shipping-settings' ); ?> <?php echo \ASS\ass_help_tip( __( 'How many working days it takes to deliver after sending (Mon-Fri).', 'advanced-shipping-settings' ) ); ?></label>
												<input type="number" name="rules[<?php echo esc_attr( $method_id ); ?>][max_ship_days]" value="<?php echo esc_attr( $rule['max_ship_days'] ?? 0 ); ?>" min="0" class="small-text">
											</div>
											<div class="ass-field">
												<label><?php esc_html_e( 'Categories:', 'advanced-shipping-settings' ); ?> <?php echo \ASS\ass_help_tip( __( 'Drag and drop categories here.', 'advanced-shipping-settings' ) ); ?></label>
												<div class="ass-category-dropzone sortable-list" data-type="asap" data-method-id="<?php echo esc_attr( $method_id ); ?>">
													<?php 
													$saved_cats = $rule['categories'] ?? [];
													foreach ( $saved_cats as $cat_id ) : 
														$term = get_term( $cat_id, 'product_cat' );
														if ( ! $term || is_wp_error( $term ) ) continue;
														?>
														<div class="ass-cat-pill" data-id="<?php echo esc_attr( $cat_id ); ?>">
															<?php echo esc_html( $term->name ); ?>
															<input type="hidden" name="rules[<?php echo esc_attr( $method_id ); ?>][categories][]" value="<?php echo esc_attr( $cat_id ); ?>">
															<span class="remove-cat">×</span>
														</div>
													<?php endforeach; ?>
												</div>
											</div>
										</div>

										<!-- BY DATE Pane -->
										<div class="ass-pane ass-pane-by_date <?php echo 'by_date' === $rule['type'] ? '' : 'hidden'; ?>">
											<div class="ass-dates-repeater" data-method-id="<?php echo esc_attr( $method_id ); ?>">
												<div class="ass-dates-container">
													<?php 
													$saved_dates = $rule['dates'] ?? [];
													foreach ( $saved_dates as $index => $date_info ) : ?>
														<div class="ass-date-row" data-index="<?php echo $index; ?>">
															<div class="ass-date-fields">
																<div class="ass-input-group">
																	<label><?php esc_html_e( 'Reservation Date:', 'advanced-shipping-settings' ); ?></label>
																	<input type="date" name="rules[<?php echo esc_attr( $method_id ); ?>][dates][<?php echo $index; ?>][date]" value="<?php echo esc_attr( $date_info['date'] ); ?>" required>
																</div>
																<div class="ass-input-group">
																	<label><?php esc_html_e( 'Label:', 'advanced-shipping-settings' ); ?></label>
																	<input type="text" name="rules[<?php echo esc_attr( $method_id ); ?>][dates][<?php echo $index; ?>][label]" value="<?php echo esc_attr( $date_info['label'] ); ?>" placeholder="e.g. Sausio 9 d.">
																</div>
																<div class="ass-input-group">
																	<label><?php esc_html_e( 'Show Until:', 'advanced-shipping-settings' ); ?> <?php echo \ASS\ass_help_tip( __( 'Optional: Hide this date as soon as this date is reached.', 'advanced-shipping-settings' ) ); ?></label>
																	<input type="date" name="rules[<?php echo esc_attr( $method_id ); ?>][dates][<?php echo $index; ?>][show_until]" value="<?php echo esc_attr( $date_info['show_until'] ?? '' ); ?>">
																</div>
																<button type="button" class="button remove-date-row"><?php esc_html_e( 'Remove Date', 'advanced-shipping-settings' ); ?></button>
															</div>
															<div class="ass-field">
																<label><?php esc_html_e( 'Categories for this date:', 'advanced-shipping-settings' ); ?></label>
																<div class="ass-category-dropzone sortable-list" data-type="by_date" data-method-id="<?php echo esc_attr( $method_id ); ?>">
																	<?php 
																	$date_cats = $date_info['categories'] ?? [];
																	foreach ( $date_cats as $cat_id ) : 
																		$term = get_term( $cat_id, 'product_cat' );
																		if ( ! $term || is_wp_error( $term ) ) continue;
																		?>
																		<div class="ass-cat-pill" data-id="<?php echo esc_attr( $cat_id ); ?>">
																			<?php echo esc_html( $term->name ); ?>
																			<input type="hidden" name="rules[<?php echo esc_attr( $method_id ); ?>][dates][<?php echo $index; ?>][categories][]" value="<?php echo esc_attr( $cat_id ); ?>">
																			<span class="remove-cat">×</span>
																		</div>
																	<?php endforeach; ?>
																</div>
															</div>
														</div>
													<?php endforeach; ?>
												</div>
												<button type="button" class="button button-secondary add-date-row"><?php esc_html_e( 'Add Reservation Date', 'advanced-shipping-settings' ); ?></button>
											</div>
										</div>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="ass-sidebar">
						<div class="ass-sidebar-inner">
							<h3><?php esc_html_e( 'Product Categories', 'advanced-shipping-settings' ); ?></h3>
							<p class="description"><?php esc_html_e( 'Drag categories to shipping methods or specific dates.', 'advanced-shipping-settings' ); ?></p>
							<div class="ass-category-source sortable-list">
								<?php foreach ( $categories as $cat ) : ?>
									<div class="ass-cat-pill" data-id="<?php echo esc_attr( $cat->term_id ); ?>">
										<?php echo esc_html( $cat->name ); ?>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					</div>

					<div class="ass-footer-save">
						<input type="submit" name="ass_save_rules" class="button button-primary button-large" value="<?php esc_attr_e( 'Save All Rules', 'advanced-shipping-settings' ); ?>">
					</div>
				</form>
			</div>
		</div>

		<!-- Template for new date rows -->
		<script type="text/template" id="ass-date-row-template">
			<div class="ass-date-row" data-index="{index}">
				<div class="ass-date-fields">
					<div class="ass-input-group">
						<label><?php esc_html_e( 'Reservation Date:', 'advanced-shipping-settings' ); ?></label>
						<input type="date" name="rules[{method_id}][dates][{index}][date]" required>
					</div>
					<div class="ass-input-group">
						<label><?php esc_html_e( 'Label:', 'advanced-shipping-settings' ); ?></label>
						<input type="text" name="rules[{method_id}][dates][{index}][label]" placeholder="e.g. Sausio 9 d.">
					</div>
					<div class="ass-input-group">
						<label><?php esc_html_e( 'Show Until:', 'advanced-shipping-settings' ); ?> <?php echo \ASS\ass_help_tip( __( 'Optional: Hide this date as soon as this date is reached.', 'advanced-shipping-settings' ) ); ?></label>
						<input type="date" name="rules[{method_id}][dates][{index}][show_until]">
					</div>
					<button type="button" class="button remove-date-row"><?php esc_html_e( 'Remove Date', 'advanced-shipping-settings' ); ?></button>
				</div>
				<div class="ass-field">
					<label><?php esc_html_e( 'Categories for this date:', 'advanced-shipping-settings' ); ?></label>
					<div class="ass-category-dropzone sortable-list" data-type="by_date" data-method-id="{method_id}">
					</div>
				</div>
			</div>
		</script>
		<?php
	}

	/**
	 * Get all active WooCommerce shipping methods across all zones.
	 * Uses WC()->shipping()->load_shipping_methods() to get all registered shipping method classes.
	 */
	private function get_available_shipping_methods(): array {
		$methods = [];
		
		$shipping_method_classes = WC()->shipping()->load_shipping_methods();
		
		$zones = \WC_Shipping_Zones::get_zones();
		$rest_of_world = \WC_Shipping_Zones::get_zone( 0 );
		$zones[] = [
			'zone_id'   => 0,
			'zone_name' => $rest_of_world->get_zone_name(),
			'shipping_methods' => $rest_of_world->get_shipping_methods(),
		];

		$method_instances = [];
		foreach ( $zones as $zone ) {
			$zone_name = $zone['zone_name'];
			foreach ( $zone['shipping_methods'] as $instance ) {
				if ( ! $instance->is_enabled() ) continue;
				
				$method_id = $instance->id;
				$instance_id = $instance->get_instance_id();
				$full_id = $method_id . ':' . $instance_id;
				
				if ( ! isset( $method_instances[ $method_id ] ) ) {
					$method_instances[ $method_id ] = [];
				}
				
				$method_instances[ $method_id ][] = [
					'instance' => $instance,
					'zone_name' => $zone_name,
					'full_id' => $full_id,
				];
			}
		}

		foreach ( $shipping_method_classes as $method_class ) {
			if ( ! is_object( $method_class ) ) {
				continue;
			}
			
			$method_id = $method_class->id ?? '';
			if ( empty( $method_id ) ) {
				continue;
			}
			
			if ( isset( $method_instances[ $method_id ] ) ) {
				foreach ( $method_instances[ $method_id ] as $instance_data ) {
					$instance = $instance_data['instance'];
					$zone_name = $instance_data['zone_name'];
					$full_id = $instance_data['full_id'];
					
					$methods[ $full_id ] = [
						'title' => $zone_name . ' - ' . $instance->get_title(),
						'id'    => $full_id,
					];
				}
			}
		}

		return $methods;
	}

	/**
	 * Get all product categories.
	 */
	private function get_all_product_categories(): array {
		return get_terms( [
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
		] );
	}

	/**
	 * Save shipping rules from POST.
	 */
	private function save_rules(): void {
		if ( ! isset( $_POST['ass_rules_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['ass_rules_nonce'] ), 'ass_save_rules' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$raw_rules = isset( $_POST['rules'] ) ? (array) $_POST['rules'] : [];
		$sanitized_rules = [];

		foreach ( $raw_rules as $method_id => $data ) {
			$type = sanitize_text_field( $data['type'] ?? 'asap' );
			$rule = [ 'type' => $type ];

			if ( 'asap' === $type ) {
				$rule['sending_days']  = isset( $data['sending_days'] ) ? array_map( 'absint', (array) $data['sending_days'] ) : [];
				$rule['max_ship_days'] = absint( $data['max_ship_days'] ?? 0 );
				$rule['categories']    = isset( $data['categories'] ) ? array_map( 'absint', (array) $data['categories'] ) : [];
			} else {
				$dates = isset( $data['dates'] ) ? (array) $data['dates'] : [];
				$sanitized_dates = [];
				foreach ( $dates as $date_info ) {
					if ( empty( $date_info['date'] ) ) continue;
					
					$sanitized_dates[] = [
						'date'       => sanitize_text_field( $date_info['date'] ),
						'label'      => sanitize_text_field( $date_info['label'] ?? '' ),
						'show_until' => sanitize_text_field( $date_info['show_until'] ?? '' ),
						'categories' => isset( $date_info['categories'] ) ? array_map( 'absint', (array) $date_info['categories'] ) : [],
					];
				}
				// Sort dates chronologically.
				usort( $sanitized_dates, function( $a, $b ) {
					return strcmp( $a['date'], $b['date'] );
				} );
				$rule['dates'] = $sanitized_dates;
			}

			$sanitized_rules[ sanitize_text_field( $method_id ) ] = $rule;
		}

		Settings_Manager::instance()->save_shipping_rules( $sanitized_rules );

		add_action( 'admin_notices', function() {
			echo '<div class="updated"><p>' . esc_html__( 'Shipping rules saved.', 'advanced-shipping-settings' ) . '</p></div>';
		} );
	}
}

