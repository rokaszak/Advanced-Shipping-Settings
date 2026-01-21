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
	 * Get order delivery text for emails and customer order details.
	 */
	public function get_order_delivery_text(): string {
		return $this->get_translation( 'order_delivery_text', 'We plan to ship to deliver by' );
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
	 * Check if delivery date disclaimer should be shown.
	 */
	public function should_show_delivery_disclaimer(): bool {
		$settings = $this->get_plugin_settings();
		return ! empty( $settings['show_delivery_disclaimer'] );
	}

	/**
	 * Get delivery date disclaimer text.
	 */
	public function get_delivery_disclaimer_text(): string {
		$settings = $this->get_plugin_settings();
		return $settings['delivery_disclaimer_text'] ?? 'About shown delivery times';
	}

	/**
	 * Get delivery date disclaimer URL.
	 */
	public function get_delivery_disclaimer_url(): string {
		$settings = $this->get_plugin_settings();
		return $settings['delivery_disclaimer_url'] ?? '';
	}

	/**
	 * Get cart no shipping message.
	 */
	public function get_cart_no_shipping_message(): string {
		return $this->get_translation( 'cart_no_shipping_message', 'The items in your cart do not have matching shipping methods and/or delivery times.' );
	}

	/**
	 * Get checkout no shipping message.
	 */
	public function get_checkout_no_shipping_message(): string {
		return $this->get_translation( 'checkout_no_shipping_message', 'No delivery methods found for your cart contents. The items in your cart do not have matching shipping methods and/or delivery times. Please contact us if you require assistance.' );
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

	/**
	 * Get free shipping widget settings.
	 */
	public function get_widget_settings(): array {
		$settings = $this->get_plugin_settings();
		$widget_settings = $settings['free_shipping_widget'] ?? [];
		
		// Return with defaults
		return [
			'enabled' => ! empty( $widget_settings['enabled'] ),
			'display_in_cart' => ! empty( $widget_settings['display_in_cart'] ),
			'use_pre_discount' => ! empty( $widget_settings['use_pre_discount'] ),
			'hide_no_threshold' => ! empty( $widget_settings['hide_no_threshold'] ),
			'hide_already_free' => ! empty( $widget_settings['hide_already_free'] ),
			'texts' => [
				'title' => $widget_settings['texts']['title'] ?? 'Nemokamo pristatymo progresas',
				'progress_template' => $widget_settings['texts']['progress_template'] ?? 'Iki nemokamo pristatymo trūksta {remaining} (iš {threshold}).',
				'already_free' => $widget_settings['texts']['already_free'] ?? 'Nemokamas pristatymas jau pritaikytas!',
				'no_threshold' => $widget_settings['texts']['no_threshold'] ?? 'Šiam pristatymo būdui nemokamo siuntimo akcija nėra taikoma',
				'no_method' => $widget_settings['texts']['no_method'] ?? 'Pasirinkite pristatymo būdą.',
			],
		];
	}

	/**
	 * Save free shipping widget settings.
	 */
	public function save_widget_settings( array $widget_settings ): bool {
		$settings = $this->get_plugin_settings();
		$settings['free_shipping_widget'] = $widget_settings;
		return $this->save_plugin_settings( $settings );
	}
}

