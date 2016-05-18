define(
    [
        'ko',
        'ParadoxLabs_TokenBase/js/view/payment/method-renderer/ach'
    ],
    function (ko, Component) {
        'use strict';
        var config=window.checkoutConfig.payment.authnetcim_ach;
        return Component.extend({
            defaults: {
                save: config ? config.canSaveCard : false,
                selectedCard: config ? config.selectedCard : '',
                storedCards: config ? config.storedCards : {},
                achAccountTypes: config ? config.achAccountTypes : {},
                logoImage: config ? config.logoImage : false,
                achImage: config ? config.achImage : false
            },
            initVars: function() {
                this.canSaveCard     = config ? config.canSaveCard : false;
                this.forceSaveCard   = config ? config.forceSaveCard : false;
                this.defaultSaveCard = config ? config.defaultSaveCard : false;
            },
            getCode: function () {
                return 'authnetcim_ach';
            }
        });
    }
);
