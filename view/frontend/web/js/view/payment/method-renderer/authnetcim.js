define(
    [
        'ko',
        'ParadoxLabs_TokenBase/js/view/payment/method-renderer/cc'
    ],
    function (ko, Component) {
        'use strict';
        var config=window.checkoutConfig.payment.authnetcim;
        return Component.extend({
            defaults: {
                save: config ? config.canSaveCard : false,
                selectedCard: config ? config.selectedCard : '',
                storedCards: config ? config.storedCards : {},
                availableCardTypes: config ? config.availableCardTypes : {},
                creditCardExpMonth: config ? config.creditCardExpMonth : null,
                creditCardExpYear: config ? config.creditCardExpYear : null,
                logoImage: config ? config.logoImage : false
            },
            initVars: function() {
                this.canSaveCard     = config ? config.canSaveCard : false;
                this.forceSaveCard   = config ? config.forceSaveCard : false;
                this.defaultSaveCard = config ? config.defaultSaveCard : false;
                this.requireCcv      = config ? config.requireCcv : false;
            },
            getCode: function () {
                return 'authnetcim';
            }
        });
    }
);
