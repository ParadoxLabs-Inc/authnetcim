/**
 * Copyright © 2015-present ParadoxLabs, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * Need help? Try our knowledgebase and support system:
 * @link https://support.paradoxlabs.com
 */


/*browser:true*/
/*global define*/
define(
    [
        'jquery',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Checkout/js/action/redirect-on-success',
        'Magento_Checkout/js/action/select-payment-method',
        'Magento_Checkout/js/checkout-data',
        'mage/translate',
        'ParadoxLabs_Authnetcim/js/wallet/client',
        'mage/cookies'
    ],
    function (
        $,
        Component,
        quote,
        additionalValidators,
        redirectOnSuccessAction,
        selectPaymentMethodAction,
        checkoutData,
        $t,
        walletClient
    ) {
        'use strict';

        var config = window.checkoutConfig.payment.authnetcim_wallet || {};

        return Component.extend({
            defaults: {
                template: 'ParadoxLabs_Authnetcim/payment/wallet',
                clients: [],
                isWalletAvailable: false,
                walletData: null,
                buttonContainer: null
            },

            /**
             * @returns {Object}
             */
            initObservable: function () {
                this._super()
                    .observe([
                        'clients',
                        'isWalletAvailable'
                    ]);

                return this;
            },

            /**
             * Detect which enabled wallets this browser can use. The method stays hidden until one is found.
             *
             * @returns {Object}
             */
            initialize: function () {
                var self = this;

                this._super();

                walletClient.getAvailableClients(config, {formKey: $.mage.cookies.get('form_key')})
                    .then(function (clients) {
                        self.clients(clients);
                        self.isWalletAvailable(clients.length > 0);

                        if (clients.length === 0 && quote.paymentMethod()
                            && quote.paymentMethod().method === self.getCode()) {
                            selectPaymentMethodAction(null);
                            checkoutData.setSelectedPaymentMethod(null);
                        }
                    });

                this.clients.subscribe(this.drawButtons, this);

                return this;
            },

            /**
             * Keep a reference to the button container once rendered.
             *
             * @param {HTMLElement} element
             */
            initButtons: function (element) {
                this.buttonContainer = element;
                this.drawButtons();
            },

            /**
             * Render a button for each available wallet.
             */
            drawButtons: function () {
                var self = this;

                if (!this.buttonContainer) {
                    return;
                }

                this.buttonContainer.innerHTML = '';

                this.clients().forEach(function (client) {
                    var wrapper = document.createElement('div');

                    wrapper.className = 'authnetcim-wallet-button authnetcim-wallet-button-' + client.type;
                    self.buttonContainer.appendChild(wrapper);
                    client.renderButton(wrapper, self.onWalletClick.bind(self, client));
                });
            },

            /**
             * Wallet button click: validate checkout, then open the wallet sheet and place the order from it.
             *
             * Everything up to client.pay() must stay synchronous: the wallet sheet requires the click gesture.
             *
             * @param {Object} client
             * @param {Event} event
             */
            onWalletClick: function (client, event) {
                var self = this,
                    totals = quote.totals(),
                    amount = totals ? parseFloat(totals.base_grand_total) : 0;

                if (event && typeof event.preventDefault === 'function') {
                    event.preventDefault();
                }

                if (this.isChecked() !== this.getCode()) {
                    this.selectPaymentMethod();
                }

                if (!this.validate()
                    || !additionalValidators.validate()
                    || this.isPlaceOrderActionAllowed() !== true) {
                    return;
                }

                // Never open a wallet sheet for an unknown total (the customer would authorize 0.00).
                if (!(amount > 0)) {
                    this.messageContainer.addErrorMessage({
                        message: $t('Unable to determine the order total. Please reload and try again.')
                    });

                    return;
                }

                this.isPlaceOrderActionAllowed(false);

                client.pay(
                    {
                        amount: amount
                    },
                    this.placeWalletOrder.bind(this)
                ).then(function () {
                    self.afterPlaceOrder();

                    if (self.redirectAfterPlaceOrder) {
                        redirectOnSuccessAction.execute();
                    }
                }).catch(function (error) {
                    self.handleError(error);
                }).then(function () {
                    self.walletData = null;
                    self.isPlaceOrderActionAllowed(true);
                });
            },

            /**
             * Place the order with the wallet token.
             *
             * @param {Object} walletData
             * @returns {Promise}
             */
            placeWalletOrder: function (walletData) {
                var self = this;

                this.walletData = walletData;

                return new Promise(function (resolve, reject) {
                    self.getPlaceOrderDeferredObject()
                        .done(resolve)
                        .fail(function () {
                            var error = new Error('');

                            // placeOrderAction already added the server's error to the message container.
                            error.handled = true;
                            reject(error);
                        });
                });
            },

            /**
             * @param {Error} error
             */
            handleError: function (error) {
                if (error && (error.canceled || error.handled)) {
                    return;
                }

                this.messageContainer.addErrorMessage({
                    message: error && error.message ? error.message : $t(
                        'Your wallet payment could not be completed. Please try again or choose another payment method.'
                    )
                });
            },

            /**
             * @returns {Object}
             */
            getData: function () {
                return {
                    'method': this.getCode(),
                    'additional_data': this.walletData ? {
                        'wallet_type': this.walletData.wallet_type,
                        'wallet_token': this.walletData.wallet_token,
                        'card_display': this.walletData.card_display
                    } : null
                };
            }
        });
    }
);
