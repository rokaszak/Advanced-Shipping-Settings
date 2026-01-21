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

/**
 * Get free shipping threshold for a shipping rate.
 * Uses WooCommerce APIs to get threshold from method instance settings.
 *
 * @param WC_Shipping_Rate $rate The shipping rate object.
 */
function ass_get_shipping_method_threshold( $rate ): float {
	error_log('ASS: ass_get_shipping_method_threshold called');
	if ( ! $rate || ! is_a( $rate, 'WC_Shipping_Rate' ) ) {
		error_log('ASS: Rate is invalid or not WC_Shipping_Rate');
		return 0.0;
	}

	// Get method instance using WooCommerce API
	if ( ! $rate->instance_id ) {
		error_log('ASS: No instance_id found');
		// Check Omniva settings even without instance_id (Omniva uses global settings)
		$method_id = $rate->method_id ?? '';
		if ( preg_match( '/omniva/i', $method_id ) ) {
			error_log('ASS: Omniva method detected without instance_id, checking Omniva settings');
			$omniva_threshold = ass_get_omniva_threshold( $rate );
			if ( $omniva_threshold > 0 ) {
				return (float) apply_filters( 'ass_shipping_method_threshold', $omniva_threshold, $rate, null );
			}
		}
		error_log('ASS: No instance_id and not Omniva, returning early');
		// Allow filter for custom methods without instance_id
		return (float) apply_filters( 'ass_shipping_method_threshold', 0.0, $rate );
	}

	error_log('ASS: Instance ID exists: ' . $rate->instance_id);
	$method = \WC_Shipping_Zones::get_shipping_method( $rate->instance_id );
	if ( ! $method || ! is_a( $method, 'WC_Shipping_Method' ) ) {
		error_log('ASS: Method not found or invalid for instance_id: ' . $rate->instance_id);
		error_log('ASS: Method object: ' . print_r($method, true));
		return 0.0;
	}
	error_log('ASS: Method found: ' . get_class($method));

	// Check standard WooCommerce option keys
	// Get instance settings safely (some custom methods may not have get_instance_settings())
	$instance_settings = [];
	if ( method_exists( $method, 'get_instance_settings' ) ) {
		$instance_settings = $method->get_instance_settings();
	} elseif ( property_exists( $method, 'instance_settings' ) && is_array( $method->instance_settings ) ) {
		$instance_settings = $method->instance_settings;
	}

	$threshold_keys = [
		'min_amount',
		'min_amount_for_free_shipping',
		'free_shipping_min_amount',
		'free_shipping_min_total',
		'free_delivery_threshold',
		'fs_min_amount',
	];

	// If we have instance_settings array, check it directly
	if ( ! empty( $instance_settings ) && is_array( $instance_settings ) ) {
		foreach ( $threshold_keys as $key ) {
			// Check if key exists in settings to avoid undefined array key warnings
			if ( isset( $instance_settings[ $key ] ) && $instance_settings[ $key ] !== '' && $instance_settings[ $key ] !== null ) {
				$threshold = (float) wc_format_decimal( $instance_settings[ $key ] );
				if ( $threshold > 0 ) {
					// Allow filter for custom threshold logic
					return (float) apply_filters( 'ass_shipping_method_threshold', $threshold, $rate, $method );
				}
			}
		}
	} else {
		foreach ( $threshold_keys as $key ) {
			if ( method_exists( $method, 'get_instance_option' ) ) {
				$value = @$method->get_instance_option( $key, '' );
				if ( $value !== '' && $value !== null ) {
					$threshold = (float) wc_format_decimal( $value );
					if ( $threshold > 0 ) {
						return (float) apply_filters( 'ass_shipping_method_threshold', $threshold, $rate, $method );
					}
				}
			}
		}
	}

	// Check Omniva settings (minimal support without special handling)
	$method_id = $rate->method_id ?? '';
	error_log('ASS: Rate ID: ' . $rate->get_id());
	error_log('ASS: Method ID: ' . $method_id);
	error_log('ASS: Instance ID: ' . ($rate->instance_id ?? 'N/A'));
	error_log('ASS: Rate object: ' . print_r($rate, true));
	if ( preg_match( '/omniva/i', $method_id ) ) {
		$omniva_threshold = ass_get_omniva_threshold( $rate );
		if ( $omniva_threshold > 0 ) {
			return (float) apply_filters( 'ass_shipping_method_threshold', $omniva_threshold, $rate, $method );
		}
	}

	// Allow filter for custom methods
	return (float) apply_filters( 'ass_shipping_method_threshold', 0.0, $rate, $method );
}

/**
 * Get Omniva free shipping threshold from their settings.
 * Minimal implementation - reads from their standard option structure.
 *
 * @param WC_Shipping_Rate $rate The shipping rate object.
 */
function ass_get_omniva_threshold( $rate ): float {
    // Get Omniva settings option
    $omniva_settings = get_option( 'woocommerce_omnivalt_settings', [] );
    if ( empty( $omniva_settings ) || ! is_array( $omniva_settings ) ) {
        return 0.0;
    }
    
    // Extract method type from rate ID (e.g., "omnivalt_pt" -> "pt")
    $rate_id = $rate->get_id();
    if ( preg_match( '/omniva(?:lt|valt)?[_:]([a-z]+)/i', $rate_id, $matches ) ) {
        $method_type = strtolower( $matches[1] );
    } else {
        return 0.0;
    }
    
    // Get customer's shipping country
    $country = '';
    if ( WC()->customer ) {
        $country = strtoupper( WC()->customer->get_shipping_country() );
    }
    if ( empty( $country ) ) {
        $country = 'LT'; // Default fallback
    }
    
    // Helper function to extract threshold from decoded JSON
    $extract_threshold = function( $prices_json, $method_type ) {
        if ( ! is_array( $prices_json ) ) {
            return 0.0;
        }
        
        $free_from_key = $method_type . '_free_from';
        $enable_key    = $method_type . '_enable_free_from';
        
        // Check if threshold value exists
        if ( ! isset( $prices_json[ $free_from_key ] ) || $prices_json[ $free_from_key ] === '' ) {
            return 0.0;
        }
        
        $threshold = (float) wc_format_decimal( $prices_json[ $free_from_key ] );
        if ( $threshold <= 0 ) {
            return 0.0;
        }
        
        // CRITICAL: If enable flag exists, check it. If it doesn't exist, assume enabled.
        if ( isset( $prices_json[ $enable_key ] ) ) {
            // Enable flag exists - check if it's truthy
            if ( empty( $prices_json[ $enable_key ] ) || $prices_json[ $enable_key ] === '0' ) {
                return 0.0;
            }
        }
        // If enable flag doesn't exist (like for courier "c"), threshold is valid
        
        return $threshold;
    };
    
    // Try country-specific settings first (e.g., prices_LT)
    $country_key = 'prices_' . $country;
    if ( isset( $omniva_settings[ $country_key ] ) && is_string( $omniva_settings[ $country_key ] ) ) {
        $prices_json = json_decode( $omniva_settings[ $country_key ], true );
        $threshold = $extract_threshold( $prices_json, $method_type );
        if ( $threshold > 0 ) {
            return $threshold;
        }
    }
    
    // Fallback: try other country settings
    foreach ( $omniva_settings as $key => $value ) {
        if ( strpos( $key, 'prices_' ) === 0 && is_string( $value ) ) {
            $prices_json = json_decode( $value, true );
            $threshold = $extract_threshold( $prices_json, $method_type );
            if ( $threshold > 0 ) {
                return $threshold;
            }
        }
    }
    
    return 0.0;
}
