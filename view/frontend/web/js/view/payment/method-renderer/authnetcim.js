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
                template: 'ParadoxLabs_Authnetcim/payment/cc',
                save: config ? config.canSaveCard : false,
                selectedCard: config ? config.selectedCard : '',
                storedCards: config ? config.storedCards : {},
                availableCardTypes: config ? config.availableCardTypes : {},
                creditCardExpMonth: config ? config.creditCardExpMonth : null,
                creditCardExpYear: config ? config.creditCardExpYear : null,
                logoImage: config ? config.logoImage : false,
                apiLoginId: config ? config.apiLoginId : '',
                clientKey: config ? config.clientKey : '',
                sandbox: config ? config.sandbox : false,
                acceptJsKey: '',
                acceptJsValue: '',
                creditCardLast4: '',
                canStoreBin: config ? config.canStoreBin : false,
                creditCardBin: ''
            },
            initVars: function() {
                this.canSaveCard     = config ? config.canSaveCard : false;
                this.forceSaveCard   = config ? config.forceSaveCard : false;
                this.defaultSaveCard = config ? config.defaultSaveCard : false;
                this.requireCcv      = config ? config.requireCcv : false;
            },
            /**
             * @override
             */
            initObservable: function () {
                this.initVars();
                this._super()
                    .observe([
                        'acceptJsKey',
                        'acceptJsValue',
                        'creditCardLast4',
                        'canStoreBin',
                        'creditCardBin'
                    ]);

                return this;
            },
            /**
             * @override
             */
            getData: function () {
                if (this.useAcceptJs()) {
                    return {
                        'method': this.item.method,
                        'additional_data': {
                            'save': this.save(),
                            'acceptjs_key': this.acceptJsKey(),
                            'acceptjs_value': this.acceptJsValue(),
                            'cc_type': this.selectedCardType() != '' ? this.selectedCardType() : this.creditCardType(),
                            'cc_exp_year': this.creditCardExpYear(),
                            'cc_exp_month': this.creditCardExpMonth(),
                            'cc_cid': this.creditCardVerificationNumber(),
                            'cc_last4': this.creditCardLast4(),
                            'cc_bin': this.creditCardBin(),
                            'card_id': this.selectedCard()
                        }
                    }
                }

                return this._super();
            },
            getCode: function () {
                return 'authnetcim';
            },
            getApiLoginId: function () {
                return this.apiLoginId !== null ? this.apiLoginId : '';
            },
            getClientKey: function () {
                return this.clientKey !== null ? this.clientKey : '';
            },
            getSandbox: function () {
                return this.sandbox !== null ? this.sandbox : false;
            },
            useAcceptJs: function () {
                return this.getApiLoginId().length > 0 && this.getClientKey().length > 0;
            }
        });
    }
);
