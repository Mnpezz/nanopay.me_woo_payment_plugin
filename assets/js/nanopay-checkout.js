jQuery(function($) {
    $('form.checkout').on('checkout_place_order_nanopay', function() {
        // Allow the form submission to proceed
        return true;
    });

    $(document.body).on('payment_method_selected', function() {
        if ($('input[name="payment_method"]:checked').val() === 'nanopay') {
            // Add any specific behavior for when NanoPay is selected
        }
    });

    $(document.body).on('checkout_error', function() {
        // Handle checkout errors
    });
});
