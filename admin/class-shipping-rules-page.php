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


	private function get_tag_pill_palette(): array {
		return [
			'#1F77B4', '#FF7F0E', '#2CA02C', '#D62728', '#9467BD',
			'#8C564B', '#E377C2', '#7F7F7F', '#BCBD22', '#17BECF',
			'#AEC7E8', '#FFBB78', '#98DF8A', '#FF9896', '#C5B0D5',
			'#C49C94', '#F7B6D2', '#C7C7C7', '#DBDB8D', '#9EDAE5',
		];
	}


	public function render(): void {
		if ( ! empty( $_POST['ass_save_rules'] ) ) {
			$this->save_rules();
		}

		$shipping_methods = $this->get_available_shipping_methods();
		$tags             = $this->get_all_product_tags();
		$current_rules    = Settings_Manager::instance()->get_shipping_rules();
		$palette          = $this->get_tag_pill_palette();

		?>
		<div class="wrap ass-admin-page">
			<style type="text/css">
			<?php
			foreach ( $tags as $tag ) {
				$tid   = (int) $tag->term_id;
				$color = $palette[ $tid % 20 ];
				echo '.ass-tag-color-' . $tid . ' { background-color: ' . esc_attr( $color ) . "; }\n";
			}
			?>
			</style>
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
												<label><?php esc_html_e( 'Tags:', 'advanced-shipping-settings' ); ?> <?php echo \ASS\ass_help_tip( __( 'Drag and drop tags here for normal ASAP shipping calculation.', 'advanced-shipping-settings' ) ); ?></label>
												<div class="ass-tag-dropzone sortable-list" data-type="asap" data-method-id="<?php echo esc_attr( $method_id ); ?>">
													<?php
													$saved_tags = $rule['tags'] ?? [];
													foreach ( $saved_tags as $tag_id ) :
														$term = get_term( $tag_id, 'product_tag' );
														if ( ! $term || is_wp_error( $term ) ) continue;
														?>
														<div class="ass-tag-pill ass-tag-color-<?php echo (int) $tag_id; ?>" data-id="<?php echo esc_attr( $tag_id ); ?>">
															<?php echo esc_html( $term->name ); ?>
															<input type="hidden" name="rules[<?php echo esc_attr( $method_id ); ?>][tags][]" value="<?php echo esc_attr( $tag_id ); ?>">
															<span class="remove-tag">×</span>
														</div>
													<?php endforeach; ?>
												</div>
											</div>

											<div class="ass-field">
												<label><?php esc_html_e( 'Priority Sending Days:', 'advanced-shipping-settings' ); ?> <?php echo \ASS\ass_help_tip( __( 'Add specific dates that override normal sending days for selected tags. If a cart contains items matching a priority day, the LATEST matching priority date (or normal date) will be used as the send-out date.', 'advanced-shipping-settings' ) ); ?></label>
												<div class="ass-priority-days-repeater" data-method-id="<?php echo esc_attr( $method_id ); ?>">
													<div class="ass-priority-days-container">
														<?php 
														$priority_days = $rule['priority_days'] ?? [];
														foreach ( $priority_days as $p_index => $p_day ) :
															$p_date_info = [ 'date' => $p_day['date'], 'show_until' => '' ];
															$p_row_hidden = ! Shipping_Filter::instance()->is_date_visible( $p_date_info );
															?>
															<div class="ass-priority-day-row<?php echo $p_row_hidden ? ' ass-row-not-shown' : ''; ?>" data-index="<?php echo $p_index; ?>">
																<div class="ass-priority-day-fields">
																	<div class="ass-input-group">
																		<label><?php esc_html_e( 'Date:', 'advanced-shipping-settings' ); ?></label>
																		<input type="date" name="rules[<?php echo esc_attr( $method_id ); ?>][priority_days][<?php echo $p_index; ?>][date]" value="<?php echo esc_attr( $p_day['date'] ); ?>" required>
																	</div>
																	<div class="ass-input-group">
																		<label><?php esc_html_e( 'Label:', 'advanced-shipping-settings' ); ?></label>
																		<input type="text" name="rules[<?php echo esc_attr( $method_id ); ?>][priority_days][<?php echo $p_index; ?>][label]" value="<?php echo esc_attr( $p_day['label'] ?? '' ); ?>" placeholder="e.g. Christmas Reservation">
																	</div>
																	<button type="button" class="button remove-priority-day-row"><?php esc_html_e( 'Remove Date', 'advanced-shipping-settings' ); ?></button>
																</div>
																<div class="ass-field">
																	<label><?php esc_html_e( 'Tags for this priority day:', 'advanced-shipping-settings' ); ?></label>
																	<div class="ass-tag-dropzone sortable-list" data-type="priority_day" data-method-id="<?php echo esc_attr( $method_id ); ?>">
																		<?php
																		$p_tags = $p_day['tags'] ?? [];
																		foreach ( $p_tags as $tag_id ) :
																			$term = get_term( $tag_id, 'product_tag' );
																			if ( ! $term || is_wp_error( $term ) ) continue;
																			?>
																			<div class="ass-tag-pill ass-tag-color-<?php echo (int) $tag_id; ?>" data-id="<?php echo esc_attr( $tag_id ); ?>">
																				<?php echo esc_html( $term->name ); ?>
																				<input type="hidden" name="rules[<?php echo esc_attr( $method_id ); ?>][priority_days][<?php echo $p_index; ?>][tags][]" value="<?php echo esc_attr( $tag_id ); ?>">
																				<span class="remove-tag">×</span>
																			</div>
																		<?php endforeach; ?>
																	</div>
																</div>
																<label class="ass-row-visibility-label"><?php
																if ( $p_row_hidden ) {
																	echo esc_html__( 'This date is not shown to clients.', 'advanced-shipping-settings' );
																} else {
																	$p_last_visible = ( new \DateTime( $p_day['date'] ) )->modify( '-1 day' )->format( 'Y-m-d' );
																	echo sprintf( esc_html__( 'This date is shown to clients until: %s 23:59', 'advanced-shipping-settings' ), esc_html( $p_last_visible ) );
																}
																?></label>
															</div>
														<?php endforeach; ?>
													</div>
													<button type="button" class="button button-secondary add-priority-day-row"><?php esc_html_e( 'Add Priority Day', 'advanced-shipping-settings' ); ?></button>
												</div>
											</div>
										</div>

										<!-- BY DATE Pane -->
										<div class="ass-pane ass-pane-by_date <?php echo 'by_date' === $rule['type'] ? '' : 'hidden'; ?>">
											<div class="ass-dates-repeater" data-method-id="<?php echo esc_attr( $method_id ); ?>">
												<div class="ass-dates-container">
													<?php 
													$saved_dates = $rule['dates'] ?? [];
													foreach ( $saved_dates as $index => $date_info ) :
														$date_row_hidden = ! Shipping_Filter::instance()->is_date_visible( $date_info );
														?>
														<div class="ass-date-row<?php echo $date_row_hidden ? ' ass-row-not-shown' : ''; ?>" data-index="<?php echo $index; ?>">
															<div class="ass-date-fields">
																<div class="ass-input-group">
																	<label><?php esc_html_e( 'Reservation Date:', 'advanced-shipping-settings' ); ?></label>
																	<input type="date" name="rules[<?php echo esc_attr( $method_id ); ?>][dates][<?php echo $index; ?>][date]" value="<?php echo esc_attr( $date_info['date'] ); ?>" required>
																</div>
																<div class="ass-input-group">
																	<label><?php esc_html_e( 'Label:', 'advanced-shipping-settings' ); ?></label>
																	<input type="text" name="rules[<?php echo esc_attr( $method_id ); ?>][dates][<?php echo $index; ?>][label]" value="<?php echo esc_attr( $date_info['label'] ); ?>" placeholder="e.g. January 9">
																</div>
																<div class="ass-input-group">
																	<label><?php esc_html_e( 'Show Until:', 'advanced-shipping-settings' ); ?> <?php echo \ASS\ass_help_tip( __( 'Optional. If left empty, this date is hidden as soon as the reservation date above is reached (at 23:59:59). If set, this date is also hidden when the Show Until date is reached (at 00:00:00). Useful to stop showing a date earlier than the reservation date.', 'advanced-shipping-settings' ) ); ?></label>
																	<input type="date" name="rules[<?php echo esc_attr( $method_id ); ?>][dates][<?php echo $index; ?>][show_until]" value="<?php echo esc_attr( $date_info['show_until'] ?? '' ); ?>">
																</div>
																<button type="button" class="button remove-date-row"><?php esc_html_e( 'Remove Date', 'advanced-shipping-settings' ); ?></button>
															</div>
															<div class="ass-field">
																<label><?php esc_html_e( 'Tags for this date:', 'advanced-shipping-settings' ); ?></label>
																<div class="ass-tag-dropzone sortable-list" data-type="by_date" data-method-id="<?php echo esc_attr( $method_id ); ?>">
																	<?php
																	$date_tags = $date_info['tags'] ?? [];
																	foreach ( $date_tags as $tag_id ) :
																		$term = get_term( $tag_id, 'product_tag' );
																		if ( ! $term || is_wp_error( $term ) ) continue;
																		?>
																		<div class="ass-tag-pill ass-tag-color-<?php echo (int) $tag_id; ?>" data-id="<?php echo esc_attr( $tag_id ); ?>">
																			<?php echo esc_html( $term->name ); ?>
																			<input type="hidden" name="rules[<?php echo esc_attr( $method_id ); ?>][dates][<?php echo $index; ?>][tags][]" value="<?php echo esc_attr( $tag_id ); ?>">
																			<span class="remove-tag">×</span>
																		</div>
																	<?php endforeach; ?>
																</div>
															</div>
															<label class="ass-row-visibility-label"><?php
																if ( $date_row_hidden ) {
																	echo esc_html__( 'This date is not shown to clients.', 'advanced-shipping-settings' );
																} else {
																	$cutoff = $date_info['date'];
																	if ( ! empty( $date_info['show_until'] ) && $date_info['show_until'] < $cutoff ) {
																		$cutoff = $date_info['show_until'];
																	}
																	$last_visible = ( new \DateTime( $cutoff ) )->modify( '-1 day' )->format( 'Y-m-d' );
																	echo sprintf( esc_html__( 'This date is shown to clients until: %s 23:59', 'advanced-shipping-settings' ), esc_html( $last_visible ) );
																}
																?></label>
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
							<h3><?php esc_html_e( 'Product Tags', 'advanced-shipping-settings' ); ?></h3>
							<p class="description"><?php esc_html_e( 'Drag tags to shipping methods or specific dates.', 'advanced-shipping-settings' ); ?></p>
							<div class="ass-tag-source sortable-list">
								<?php foreach ( $tags as $tag ) : ?>
									<div class="ass-tag-pill ass-tag-color-<?php echo (int) $tag->term_id; ?>" data-id="<?php echo esc_attr( $tag->term_id ); ?>">
										<?php echo esc_html( $tag->name ); ?>
									</div>
								<?php endforeach; ?>
							</div>
							<div class="ass-sidebar-save">
								<input type="submit" name="ass_save_rules" id="ass-save-rules-btn" class="button button-primary button-large" value="<?php esc_attr_e( 'Save All Rules', 'advanced-shipping-settings' ); ?>" disabled>
							</div>
						</div>
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
						<input type="text" name="rules[{method_id}][dates][{index}][label]" placeholder="e.g. January 9">
					</div>
					<div class="ass-input-group">
						<label><?php esc_html_e( 'Show Until:', 'advanced-shipping-settings' ); ?> <?php echo \ASS\ass_help_tip( __( 'Optional. If left empty, this date is hidden as soon as the reservation date above is reached (at 23:59:59). If set, this date is also hidden when the Show Until date is reached (at 00:00:00). Useful to stop showing a date earlier than the reservation date.', 'advanced-shipping-settings' ) ); ?></label>
						<input type="date" name="rules[{method_id}][dates][{index}][show_until]">
					</div>
					<button type="button" class="button remove-date-row"><?php esc_html_e( 'Remove Date', 'advanced-shipping-settings' ); ?></button>
				</div>
				<div class="ass-field">
					<label><?php esc_html_e( 'Tags for this date:', 'advanced-shipping-settings' ); ?></label>
					<div class="ass-tag-dropzone sortable-list" data-type="by_date" data-method-id="{method_id}">
					</div>
				</div>
				<label class="ass-row-visibility-label"><?php esc_html_e( 'Save to see visibility.', 'advanced-shipping-settings' ); ?></label>
			</div>
		</script>

		<!-- Template for new priority day rows -->
		<script type="text/template" id="ass-priority-day-row-template">
			<div class="ass-priority-day-row" data-index="{index}">
				<div class="ass-priority-day-fields">
					<div class="ass-input-group">
						<label><?php esc_html_e( 'Date:', 'advanced-shipping-settings' ); ?></label>
						<input type="date" name="rules[{method_id}][priority_days][{index}][date]" required>
					</div>
					<div class="ass-input-group">
						<label><?php esc_html_e( 'Label:', 'advanced-shipping-settings' ); ?></label>
						<input type="text" name="rules[{method_id}][priority_days][{index}][label]" placeholder="e.g. Christmas Reservation">
					</div>
					<button type="button" class="button remove-priority-day-row"><?php esc_html_e( 'Remove Date', 'advanced-shipping-settings' ); ?></button>
				</div>
				<div class="ass-field">
					<label><?php esc_html_e( 'Tags for this priority day:', 'advanced-shipping-settings' ); ?></label>
					<div class="ass-tag-dropzone sortable-list" data-type="priority_day" data-method-id="{method_id}">
					</div>
				</div>
				<label class="ass-row-visibility-label"><?php esc_html_e( 'Save to see visibility.', 'advanced-shipping-settings' ); ?></label>
			</div>
		</script>
		<?php
	}

	/**
	 * Get all WooCommerce shipping method types (WPFactory pattern).
	 * Uses WC()->shipping()->load_shipping_methods() and stores by method_id only.
	 * Filters out hidden methods.
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

		// Filter out hidden methods
		$hidden_methods = Settings_Manager::instance()->get_hidden_shipping_methods();
		if ( ! empty( $hidden_methods ) ) {
			$methods = array_diff_key( $methods, array_flip( $hidden_methods ) );
		}

		return $methods;
	}

	/**
	 * Get all product tags.
	 */
	private function get_all_product_tags(): array {
		return get_terms( [
			'taxonomy'   => 'product_tag',
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
				$rule['tags']          = isset( $data['tags'] ) ? array_map( 'absint', (array) $data['tags'] ) : [];

				$priority_days = isset( $data['priority_days'] ) ? (array) $data['priority_days'] : [];
				$sanitized_priority = [];
				foreach ( $priority_days as $p_day ) {
					if ( empty( $p_day['date'] ) ) continue;
					$sanitized_priority[] = [
						'date'  => sanitize_text_field( $p_day['date'] ),
						'label' => sanitize_text_field( $p_day['label'] ?? '' ),
						'tags'  => isset( $p_day['tags'] ) ? array_map( 'absint', (array) $p_day['tags'] ) : [],
					];
				}
				$rule['priority_days'] = $sanitized_priority;
			} else {
				$dates = isset( $data['dates'] ) ? (array) $data['dates'] : [];
				$sanitized_dates = [];
				foreach ( $dates as $date_info ) {
					if ( empty( $date_info['date'] ) ) continue;

					$sanitized_dates[] = [
						'date'      => sanitize_text_field( $date_info['date'] ),
						'label'     => sanitize_text_field( $date_info['label'] ?? '' ),
						'show_until' => sanitize_text_field( $date_info['show_until'] ?? '' ),
						'tags'      => isset( $date_info['tags'] ) ? array_map( 'absint', (array) $date_info['tags'] ) : [],
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

