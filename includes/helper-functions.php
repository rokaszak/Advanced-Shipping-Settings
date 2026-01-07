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

