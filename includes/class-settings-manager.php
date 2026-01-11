<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle all settings storage and retrieval.
 */
class Settings_Manager {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Get shipping rules configuration.
	 */
	public function get_shipping_rules(): array {
		return get_option( 'ass_shipping_rules', [] );
	}

	/**
	 * Save shipping rules configuration.
	 */
	public function save_shipping_rules( array $rules ): bool {
		return update_option( 'ass_shipping_rules', $rules );
	}

	/**
	 * Get general plugin settings (translations, holidays).
	 */
	public function get_plugin_settings(): array {
		return get_option( 'ass_plugin_settings', [] );
	}

	/**
	 * Save general plugin settings.
	 */
	public function save_plugin_settings( array $settings ): bool {
		return update_option( 'ass_plugin_settings', $settings );
	}

	/**
	 * Get holiday dates from settings.
	 */
	public function get_holiday_dates(): array {
		$settings = $this->get_plugin_settings();
		return $settings['holiday_dates'] ?? [];
	}

	/**
	 * Get hidden shipping methods from settings.
	 */
	public function get_hidden_shipping_methods(): array {
		$settings = $this->get_plugin_settings();
		return $settings['hidden_methods'] ?? [];
	}

	/**
	 * Get a specific translation or return the default.
	 */
	public function get_translation( string $key, string $default = '' ): string {
		$settings = $this->get_plugin_settings();
		$translations = $settings['translations'] ?? [];
		return ! empty( $translations[ $key ] ) ? $translations[ $key ] : $default;
	}

	/**
	 * Get custom display names for shipping methods.
	 */
	public function get_method_display_names(): array {
		$settings = $this->get_plugin_settings();
		return $settings['method_display_names'] ?? [];
	}

	/**
	 * Get custom images for shipping methods.
	 */
	public function get_method_images(): array {
		$settings = $this->get_plugin_settings();
		return $settings['method_images'] ?? [];
	}

	/**
	 * Get pickup locations configuration.
	 */
	public function get_pickup_locations(): array {
		return get_option( 'ass_pickup_locations', [] );
	}

	/**
	 * Save pickup locations configuration.
	 */
	public function save_pickup_locations( array $locations ): bool {
		return update_option( 'ass_pickup_locations', $locations );
	}
}

