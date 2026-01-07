jQuery(document).ready(function($) {
    /**
     * Checkout Date Selector Logic
     */

    // WooCommerce triggers 'updated_checkout' when shipping methods change.
    // Note: No default selection - user must explicitly select a reservation date.
    $(document.body).on('updated_checkout', function() {
        // Selection preservation logic can be added here if needed in the future
    });
});

