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
	 * Get a specific translation or return the default.
	 */
	public function get_translation( string $key, string $default = '' ): string {
		$settings = $this->get_plugin_settings();
		$translations = $settings['translations'] ?? [];
		return ! empty( $translations[ $key ] ) ? $translations[ $key ] : $default;
	}
}

