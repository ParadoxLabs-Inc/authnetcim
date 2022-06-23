/*browser:true*/
/*global define*/
define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';

        var formComponent = 'ParadoxLabs_Authnetcim/js/view/payment/method-renderer/authnetcim-hosted';
        if (window.checkoutConfig.payment.authnetcim.clientKey.length > 0) {
            formComponent = 'ParadoxLabs_Authnetcim/js/view/payment/method-renderer/authnetcim';
        }

        rendererList.push(
            {
                type: 'authnetcim',
                component: formComponent
            }
        );
        /** Add view logic here if needed */
        return Component.extend({});
    }
);
