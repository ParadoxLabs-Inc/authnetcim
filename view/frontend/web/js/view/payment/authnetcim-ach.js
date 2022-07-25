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
        if (window.checkoutConfig.payment.authnetcim_ach.formType !== 'hosted') {
            formComponent = 'ParadoxLabs_Authnetcim/js/view/payment/method-renderer/authnetcim-ach';
        }

        rendererList.push(
            {
                type: 'authnetcim_ach',
                component: formComponent
            }
        );
        /** Add view logic here if needed */
        return Component.extend({});
    }
);
