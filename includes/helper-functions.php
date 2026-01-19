<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate a help tooltip icon with custom class to avoid WooCommerce conflicts.
 *
 * @param string $tip The tooltip text.
 * @param bool   $allow_html Allow HTML in tooltip (default: false).
 * @return string HTML for the tooltip icon.
 */
function ass_help_tip( string $tip, bool $allow_html = false ): string {
	if ( empty( $tip ) ) {
		return '';
	}

	$tip = esc_attr( $tip );
	
	if ( $allow_html ) {
		$tip = wp_kses_post( $tip );
	}

	return sprintf(
		'<span class="ass-help-tip" data-tip="%s" aria-label="%s"></span>',
		$tip,
		$tip
	);
}

/**
 * Get the current shipping rate and rule from session/packages.
 * Reusable helper to avoid code duplication.
 *
 * @return array|null Array with 'rate', 'rule', and 'package' keys, or null if not found.
 */
function ass_get_current_shipping_rate_and_rule(): ?array {
	$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
	if ( empty( $chosen_methods ) ) {
		return null;
	}

	$chosen_method = $chosen_methods[0];
	
	$packages = WC()->shipping()->get_packages();
	if ( empty( $packages ) ) {
		return null;
	}

	$rate = null;
	$package = null;
	foreach ( $packages as $pkg ) {
		if ( ! empty( $pkg['rates'][ $chosen_method ] ) ) {
			$rate = $pkg['rates'][ $chosen_method ];
			$package = $pkg;
			break;
		}
	}

	if ( ! $rate ) {
		return null;
	}

	$method_id = apply_filters( 'ass_shipping_method_id', $rate->method_id, $rate );
	$rules = Settings_Manager::instance()->get_shipping_rules();
	$rule = $rules[ $method_id ] ?? null;

	if ( ! $rule ) {
		return null;
	}

	return [
		'rate' => $rate,
		'rule' => $rule,
		'package' => $package,
	];
}
