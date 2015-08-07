/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
define(
    [
        'ko',
        'Magento_Payment/js/view/payment/cc-form',
        'Magento_Checkout/js/action/set-payment-information',
        'Magento_Checkout/js/model/quote',
        'underscore',
        'jquery'
    ],
    function (ko, Component, setPaymentInformationAction, quote, _, $) {
        'use strict';
        var config=window.checkoutConfig.payment.authnetcim;
        return Component.extend({
            isShowLegend: function() {
                return true;
            },
            isActive: function() {
                return true;
            },
            defaults: {
                template: 'ParadoxLabs_Authnetcim/payment/authnetcim',
                isCcFormShown: true,
                save: config ? config.canSaveCard : false,
                paymentMethodNonce: null,
                selectedCard: config ? config.selectedCard : '',
                storedCards: config ? config.storedCards : {},
                availableCardTypes: config ? config.availableCardTypes : {},
                creditCardExpMonth: config ? config.creditCardExpMonth : null,
                creditCardExpYear: config ? config.creditCardExpYear : null
            },
            initVars: function() {
                this.clientToken = config ? config.clientToken : '';
                this.canSaveCard = config ? config.canSaveCard : false;
                this.isPaymentProcessing = null;
            },
            /**
             * @override
             */
            initObservable: function () {
                this.initVars();
                this._super()
                    .observe([
                        'selectedCard',
                        'save',
                        'storedCards'
                    ]);
                this.isCcFormShown = ko.computed(function () {
                    return !this.useVault()
                        || this.selectedCard() === undefined ||
                        this.selectedCard() == '';
                }, this);

                return this;
            },
            /**
             * @override
             */
            getData: function () {
                return {
                    'method': this.item.method,
                    'cc_type': this.creditCardType(),
                    'cc_exp_year': this.creditCardExpYear(),
                    'cc_exp_month': this.creditCardExpMonth(),
                    'cc_number': this.creditCardNumber(),
                    additional_data: {
                        'save': this.save(),
                        'cc_cid': this.creditCardVerificationNumber(),
                        'card_id': this.selectedCard()
                    }
                };
            },
            getCode: function () {
                return 'authnetcim';
            },
            useVault: function() {
                return this.getStoredCards().length > 0;
            },
            isCcDetectionEnabled: function() {
                return true;
            },
            getStoredCards: function() {
                return this.storedCards();
            }
        });
    }
);
