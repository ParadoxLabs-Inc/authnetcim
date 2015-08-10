/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
define(
    [
        'ko',
        'Magento_Payment/js/view/payment/cc-form'
    ],
    function (ko, Component) {
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
                selectedCard: config ? config.selectedCard : '',
                storedCards: config ? config.storedCards : {},
                availableCardTypes: config ? config.availableCardTypes : {},
                creditCardExpMonth: config ? config.creditCardExpMonth : null,
                creditCardExpYear: config ? config.creditCardExpYear : null,
                logoImage: config ? config.logoImage : false
            },
            initVars: function() {
                this.canSaveCard    = config ? config.canSaveCard : false;
                this.forceSaveCard  = config ? config.forceSaveCard : false;
                this.requireCcv     = config ? config.requireCcv : false;
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
                        'storedCards',
                        'requireCcv'
                    ]);

                this.isCcFormShown = ko.computed(function () {
                    return !this.useVault()
                        || this.selectedCard() === undefined
                        || this.selectedCard() == '';
                }, this);

                this.isCcvShown = ko.computed(function () {
                    return this.requireCcv()
                        || !this.useVault()
                        || this.selectedCard() === undefined
                        || this.selectedCard() == '';
                }, this);

                return this;
            },
            /**
             * @override
             */
            getData: function () {
                return {
                    'method': this.item.method,
                    'cc_type': this.selectedCardType() != '' ? this.selectedCardType() : this.creditCardType(),
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
            forceSaveCard: function() {
                return this.forceSaveCard;
            },
            isCcDetectionEnabled: function() {
                return true;
            },
            getStoredCards: function() {
                return this.storedCards();
            },
            getLogoImage: function() {
                return this.logoImage;
            }
        });
    }
);
