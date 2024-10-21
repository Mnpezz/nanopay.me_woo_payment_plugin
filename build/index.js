const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;
const { decodeEntities } = window.wp.htmlEntities;
const { __ } = window.wp.i18n;

const settings = getSetting('nanopay_data', {});

const label = decodeEntities(settings.title) || __('NanoPay', 'nanopay');

const Content = () => {
    return decodeEntities(settings.description || '');
};

const NanoPayPaymentMethod = {
    name: 'nanopay',
    label: label,
    content: Object(window.wp.element.createElement)(Content, null),
    edit: Object(window.wp.element.createElement)(Content, null),
    canMakePayment: () => true,
    ariaLabel: label,
    supports: {
        features: settings.supports,
    },
    paymentMethodId: 'nanopay',
    
    // Add this method to handle the payment
    processPayment: (paymentData, context) => {
        return new Promise((resolve, reject) => {
            // This will trigger the process_payment method in your PHP gateway class
            context.onSubmit();
        });
    },
};

registerPaymentMethod(NanoPayPaymentMethod);
