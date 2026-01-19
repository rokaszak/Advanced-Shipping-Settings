jQuery(document).ready(function($) {
    function initValidation() {
        var $shippingInfo = $('.ass-checkout-shipping-info');
        if (!$shippingInfo.length) return;
        
        var $dateInputs = $('input[name="deliver_by_date"]');
        
        function checkError() {
            var $dateInputs = $('input[name="deliver_by_date"]');
            if (!$dateInputs.length) {
                $shippingInfo.removeClass('ass-invalid');
                return;
            }
            
            var hasError = $('.woocommerce-error li[data-id="deliver_by_date"]').length > 0;
            if (hasError) {
                $shippingInfo.addClass('ass-invalid');
            } else {
                $shippingInfo.removeClass('ass-invalid');
            }
        }
        
        $dateInputs.off('change.ass-validation').on('change.ass-validation', function() {
            $shippingInfo.removeClass('ass-invalid');
        });
        
        var bodyObserver = new MutationObserver(function() {
            checkError();
        });
        
        bodyObserver.observe(document.body, { childList: true, subtree: true });
        checkError();
    }
    
    initValidation();
    $(document.body).on('updated_checkout checkout_error', initValidation);
});

