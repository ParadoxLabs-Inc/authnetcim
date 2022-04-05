/**
 * Paradox Labs, Inc.
 * http://www.paradoxlabs.com
 * 717-431-3330
 *
 * Need help? Open a ticket in our support system:
 *  http://support.paradoxlabs.com
 *
 * @author      Ryan Hoerr <support@paradoxlabs.com>
 * @license     http://store.paradoxlabs.com/license.html
 */

define(
    [
        'ko',
        'jquery',
        'ParadoxLabs_TokenBase/js/view/payment/method-renderer/cc',
        'mage/translate'
    ],
    function (ko, $, Component) {
        'use strict';
        var config=window.checkoutConfig.payment.authnetcim;
        return Component.extend({
            defaults: {
                template: 'ParadoxLabs_Authnetcim/payment/cc',
                save: config ? config.canSaveCard && config.defaultSaveCard : false,
                selectedCard: config ? config.selectedCard : '',
                storedCards: config ? config.storedCards : {},
                creditCardExpMonth: config ? config.creditCardExpMonth : null,
                creditCardExpYear: config ? config.creditCardExpYear : null,
                logoImage: config ? config.logoImage : false,
                apiLoginId: config ? config.apiLoginId : '',
                clientKey: config ? config.clientKey : '',
                sandbox: config ? config.sandbox : false,
                canStoreBin: config ? config.canStoreBin : false,
            },

            // Change certain error responses to be more useful.
            errorMap: {
                'E_WC_10': 'API credentials invalid. If you are an administrator, please correct the API Login ID.',
                'E_WC_19': 'API credentials invalid. If you are an administrator, please correct the API Login ID and'
                           + ' Client Key.',
                'E_WC_21': 'API credentials invalid. If you are an administrator, please correct the API Login ID and'
                           + ' Client Key.'
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
                        'creditCardBin',
                        'canStoreBin'
                    ]);

                this.placeOrderFailure.subscribe(this.clearToken.bind(this));

                if (this.useAcceptJs()) {
                    if (this.sandbox) {
                        require(
                            ['authorizeNetAcceptjsSandbox'],
                            this.initAcceptJs.bind(this)
                        );
                    } else {
                        require(
                            ['authorizeNetAcceptjs'],
                            this.initAcceptJs.bind(this)
                        );
                    }
                }

                return this;
            },

            initAcceptJs: function() {
                window[this.item.method + '_acceptJs_callback'] = this.handlePaymentResponse.bind(this);
                window.isReady = true;
            },

            clearToken: function (placeOrderFailure) {
                if (placeOrderFailure === true) {
                    this.acceptJsKey(null);
                    this.acceptJsValue(null);
                }
            },

            /**
             * @override
             */
            getData: function () {
                var paymentData = this._super();

                if (this.useAcceptJs()) {
                    delete paymentData.additional_data.cc_number;

                    $.extend(
                        true,
                        paymentData,
                        {
                            'additional_data': {
                                'acceptjs_key': this.acceptJsKey(),
                                'acceptjs_value': this.acceptJsValue(),
                                'cc_last4': this.creditCardLast4(),
                                'cc_bin': this.creditCardBin()
                            }
                        }
                    )
                }

                return paymentData;
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
            },

            handlePaymentResponse: function (response) {
                if (response.messages.resultCode === 'Error') {
                    this.handlePaymentResponseError(response);
                } else {
                    this.handlePaymentResponseSuccess(response);
                }
            },

            handlePaymentResponseError: function (response) {
                this.acceptJsKey(null);
                this.acceptJsValue(null);

                var messages = [];
                for (var i = 0; i < response.messages.message.length; i++) {
                    var errorText = response.messages.message[i].text;
                    if (typeof this.errorMap[response.messages.message[i].code] !== 'undefined') {
                        errorText = this.errorMap[response.messages.message[i].code];
                    }

                    messages.push(
                        $.mage.__('Payment Error: %1 <small><em>(%2)</em></small>')
                            .replace('%1', $.mage.__(errorText))
                            .replace('%2', response.messages.message[i].code)
                    );
                }

                this.isTokenizing(false);

                this.handleFailedOrder({
                    responseText: JSON.stringify({
                        message: messages.join("\n")
                    })
                });
            },

            handlePaymentResponseSuccess: function (response) {
                this.acceptJsKey(response.opaqueData.dataDescriptor);
                this.acceptJsValue(response.opaqueData.dataValue);

                this.isTokenizing(false);

                this.placeOrder();
            },

            placeOrder: function (data, event) {
                this.isPlaceOrderActionAllowed(false);

                if (this.selectedCard() || this.acceptJsValue() || !this.useAcceptJs()) {
                    return this._super(data, event);
                } else {
                    this.isTokenizing(true);

                    var cc_no = this.creditCardNumber().replace(/\D/g,'');
                    this.creditCardLast4(cc_no.substring(cc_no.length - 4));
                    if (this.canStoreBin) {
                        this.creditCardBin(cc_no.substring(0, 6));
                    }

                    var paymentData = {
                        cardData: {
                            cardNumber: cc_no,
                            month: this.creditCardExpMonth(),
                            year: this.creditCardExpYear(),
                            cardCode: this.creditCardVerificationNumber() ? this.creditCardVerificationNumber() : ''
                        },
                        authData: {
                            clientKey: this.clientKey,
                            apiLoginID: this.apiLoginId
                        }
                    };

                    Accept.dispatchData(
                        paymentData,
                        this.item.method + '_acceptJs_callback'
                    );
                }

                return false;
            }
        });
    }
);
