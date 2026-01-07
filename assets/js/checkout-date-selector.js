jQuery(document).ready(function($) {
    /**
     * Checkout Date Selector Logic
     */

    // WooCommerce triggers 'updated_checkout' when shipping methods change.
    $(document.body).on('updated_checkout', function() {
        // If there are reservation dates and none is selected, select the first one.
        var $dates = $('input[name="reservation_date"]');
        if ($dates.length > 0 && !$dates.is(':checked')) {
            $dates.first().prop('checked', true);
        }
    });

    // Ensure selection is preserved if checkout fragments update.
    $(document.body).on('checkout_error', function() {
        var $dates = $('input[name="reservation_date"]');
        if ($dates.length > 0 && !$dates.is(':checked')) {
             // Maybe user tried to submit without selection (though required attribute should catch it).
        }
    });
});

