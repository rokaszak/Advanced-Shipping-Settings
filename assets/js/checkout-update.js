/**
 * Checkout update JavaScript for Advanced Shipping Settings
 *
 * Triggers `update_checkout` when relevant fields change.
 * Modeled after WPFactory's alg-wc-cs-update-checkout.js
 *
 * @version 1.0.0
 * @since   1.0.0
 */

jQuery( document ).ready( function() {
	/**
	 * Triggers `update_checkout` when relevant fields change.
	 *
	 * Currently no specific selectors are defined, but this can be extended
	 * for fields that should trigger shipping method re-validation.
	 */
	if ( typeof ass_checkout_update !== 'undefined' && ass_checkout_update.selectors ) {
		jQuery( 'body' ).on( 'change input', ass_checkout_update.selectors, function() {
			jQuery( 'body' ).trigger( 'update_checkout' );
		} );
	}
} );
