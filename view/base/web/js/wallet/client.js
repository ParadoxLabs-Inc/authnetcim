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


/**
 * Framework-agnostic Apple Pay / Google Pay client for the Authorize.net CIM wallet payment method.
 *
 * Usable as an AMD module (Luma) or a plain script exposing window.AuthnetcimWalletClient (Hyvä, headless).
 *
 * Flow: isAvailable() once per page (also preloads the wallet SDK), renderButton(), then pay() from the button click.
 * pay() MUST be called synchronously inside the click handler: both wallets require a user gesture to open.
 *
 * pay(order, placeOrder) resolves after placeOrder(walletData) resolves; placeOrder receives
 * {wallet_type, wallet_token, card_display} to submit as payment additional_data.
 */
(function (root, factory) {
    'use strict';

    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else {
        root.AuthnetcimWalletClient = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var scriptPromises = {};

    /**
     * Load an external script once.
     *
     * @param {String} url
     * @returns {Promise}
     */
    function loadScript(url) {
        if (!scriptPromises[url]) {
            scriptPromises[url] = new Promise(function (resolve, reject) {
                var script = document.createElement('script');

                script.src = url;
                script.async = true;
                // No crossOrigin attribute: pay.google.com does not send CORS headers, so a CORS-mode
                // script request fails to load.
                script.onload = resolve;
                script.onerror = function () {
                    delete scriptPromises[url];
                    reject(new Error('Unable to load ' + url));
                };
                document.head.appendChild(script);
            });
        }

        return scriptPromises[url];
    }

    /**
     * Base64-encode a string that may contain non-Latin1 characters.
     *
     * @param {String} value
     * @returns {String}
     */
    function base64(value) {
        return window.btoa(unescape(encodeURIComponent(value)));
    }

    /**
     * Format an amount as a wallet price string.
     *
     * @param {Number|String} amount
     * @returns {String}
     */
    function formatAmount(amount) {
        return (Math.round(parseFloat(amount) * 100) / 100).toFixed(2);
    }

    /**
     * Error thrown when the customer dismisses the wallet sheet. Callers should not show it as an error.
     *
     * @param {String} message
     * @constructor
     */
    function CanceledError(message) {
        this.name = 'CanceledError';
        this.message = message || 'Canceled';
        this.canceled = true;
    }

    CanceledError.prototype = Object.create(Error.prototype);

    /**
     * Apple Pay client.
     *
     * @param {Object} config checkoutConfig.payment.authnetcim_wallet
     * @param {Object} options {formKey: String}
     * @constructor
     */
    function ApplePay(config, options) {
        this.config = config;
        this.options = options || {};
    }

    ApplePay.prototype.type = 'applepay';

    /**
     * @returns {Promise<Boolean>}
     */
    ApplePay.prototype.isAvailable = function () {
        var settings = this.config.applePay;

        if (!settings || !window.isSecureContext) {
            return Promise.resolve(false);
        }

        return loadScript(settings.sdkUrl).then(function () {
            return typeof window.ApplePaySession !== 'undefined'
                && window.ApplePaySession.supportsVersion(3)
                && window.ApplePaySession.canMakePayments();
        }).catch(function () {
            return false;
        });
    };

    /**
     * @param {HTMLElement} container
     * @param {Function} onClick
     * @returns {HTMLElement}
     */
    ApplePay.prototype.renderButton = function (container, onClick) {
        var settings = this.config.applePay,
            button = document.createElement('apple-pay-button');

        button.setAttribute('buttonstyle', settings.buttonStyle || 'black');
        button.setAttribute('type', settings.buttonType || 'plain');
        button.setAttribute('locale', document.documentElement.lang || 'en-US');
        button.addEventListener('click', onClick);
        container.appendChild(button);

        return button;
    };

    /**
     * Open the Apple Pay sheet and place the order with the resulting token.
     *
     * @param {Object} order {amount, currencyCode?, countryCode?}
     * @param {Function} placeOrder function(walletData): Promise
     * @returns {Promise}
     */
    ApplePay.prototype.pay = function (order, placeOrder) {
        var self = this,
            settings = this.config.applePay,
            session;

        return new Promise(function (resolve, reject) {
            session = new window.ApplePaySession(3, {
                countryCode: order.countryCode || self.config.countryCode,
                currencyCode: order.currencyCode || self.config.currencyCode,
                supportedNetworks: settings.supportedNetworks,
                merchantCapabilities: settings.merchantCapabilities,
                total: {
                    label: settings.displayName,
                    amount: formatAmount(order.amount),
                    type: 'final'
                }
            });

            session.onvalidatemerchant = function (event) {
                self.validateMerchant(event.validationURL).then(function (merchantSession) {
                    session.completeMerchantValidation(merchantSession);
                }).catch(function (error) {
                    session.abort();
                    reject(error);
                });
            };

            session.onpaymentauthorized = function (event) {
                var token = event.payment.token,
                    walletData = {
                        wallet_type: 'applepay',
                        wallet_token: base64(JSON.stringify(token.paymentData)),
                        card_display: token.paymentMethod && token.paymentMethod.displayName
                            ? token.paymentMethod.displayName
                            : ''
                    };

                Promise.resolve().then(function () {
                    return placeOrder(walletData);
                }).then(function (result) {
                    session.completePayment({status: window.ApplePaySession.STATUS_SUCCESS});
                    resolve(result);
                }).catch(function (error) {
                    session.completePayment({status: window.ApplePaySession.STATUS_FAILURE});
                    reject(error);
                });
            };

            session.oncancel = function () {
                reject(new CanceledError());
            };

            session.begin();
        });
    };

    /**
     * Get a merchant session from the server.
     *
     * @param {String} validationUrl
     * @returns {Promise<Object>}
     */
    ApplePay.prototype.validateMerchant = function (validationUrl) {
        var body;

        // Headless/GraphQL storefronts can supply their own merchant validation call.
        if (typeof this.options.validateMerchant === 'function') {
            return this.options.validateMerchant(validationUrl);
        }

        body = new URLSearchParams();
        body.append('validationUrl', validationUrl);
        body.append('form_key', this.options.formKey || '');

        return fetch(this.config.applePay.validateUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body
        }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok) {
                    throw new Error(data && data.message ? data.message : 'Apple Pay is unavailable right now.');
                }

                return data;
            });
        });
    };

    /**
     * Google Pay client.
     *
     * @param {Object} config checkoutConfig.payment.authnetcim_wallet
     * @param {Object} options
     * @constructor
     */
    function GooglePay(config, options) {
        this.config = config;
        this.options = options || {};
        this.client = null;
    }

    GooglePay.prototype.type = 'googlepay';

    /**
     * @returns {Object}
     */
    GooglePay.prototype.getCardPaymentMethod = function () {
        var settings = this.config.googlePay;

        return {
            type: 'CARD',
            parameters: {
                allowedAuthMethods: settings.allowedAuthMethods,
                allowedCardNetworks: settings.allowedCardNetworks
            },
            tokenizationSpecification: {
                type: 'PAYMENT_GATEWAY',
                parameters: {
                    gateway: settings.gateway,
                    gatewayMerchantId: settings.gatewayMerchantId
                }
            }
        };
    };

    /**
     * @returns {Promise<Boolean>}
     */
    GooglePay.prototype.isAvailable = function () {
        var self = this,
            settings = this.config.googlePay;

        if (!settings) {
            return Promise.resolve(false);
        }

        return loadScript(settings.sdkUrl).then(function () {
            self.client = new window.google.payments.api.PaymentsClient({
                environment: settings.environment
            });

            return self.client.isReadyToPay({
                apiVersion: 2,
                apiVersionMinor: 0,
                allowedPaymentMethods: [self.getCardPaymentMethod()]
            });
        }).then(function (response) {
            return !!(response && response.result);
        }).catch(function () {
            return false;
        });
    };

    /**
     * @param {HTMLElement} container
     * @param {Function} onClick
     * @returns {HTMLElement}
     */
    GooglePay.prototype.renderButton = function (container, onClick) {
        var settings = this.config.googlePay,
            button = this.client.createButton({
                onClick: onClick,
                buttonColor: settings.buttonColor || 'default',
                buttonType: settings.buttonType || 'plain',
                buttonSizeMode: 'fill',
                buttonLocale: (document.documentElement.lang || 'en').substring(0, 2),
                allowedPaymentMethods: [this.getCardPaymentMethod()]
            });

        container.appendChild(button);

        return button;
    };

    /**
     * Open the Google Pay sheet and place the order with the resulting token.
     *
     * @param {Object} order {amount, currencyCode?, countryCode?}
     * @param {Function} placeOrder function(walletData): Promise
     * @returns {Promise}
     */
    GooglePay.prototype.pay = function (order, placeOrder) {
        var settings = this.config.googlePay,
            request = {
                apiVersion: 2,
                apiVersionMinor: 0,
                allowedPaymentMethods: [this.getCardPaymentMethod()],
                merchantInfo: {
                    merchantName: settings.merchantName
                },
                transactionInfo: {
                    totalPriceStatus: 'FINAL',
                    totalPrice: formatAmount(order.amount),
                    currencyCode: order.currencyCode || this.config.currencyCode,
                    countryCode: order.countryCode || this.config.countryCode
                }
            };

        if (settings.merchantId) {
            request.merchantInfo.merchantId = settings.merchantId;
        }

        return this.client.loadPaymentData(request).catch(function (error) {
            if (error && error.statusCode === 'CANCELED') {
                throw new CanceledError();
            }

            throw error;
        }).then(function (paymentData) {
            var method = paymentData.paymentMethodData;

            return placeOrder({
                wallet_type: 'googlepay',
                wallet_token: base64(method.tokenizationData.token),
                card_display: method.description || ''
            });
        });
    };

    /**
     * Create clients for the wallets enabled in config.
     *
     * @param {Object} config
     * @param {Object} options
     * @returns {Array}
     */
    function createClients(config, options) {
        var clients = [];

        if (!config || !config.wallets) {
            return clients;
        }

        if (config.wallets.indexOf('applepay') !== -1) {
            clients.push(new ApplePay(config, options));
        }

        if (config.wallets.indexOf('googlepay') !== -1) {
            clients.push(new GooglePay(config, options));
        }

        return clients;
    }

    /**
     * Resolve with the clients usable on this device, in configured order.
     *
     * @param {Object} config
     * @param {Object} options
     * @returns {Promise<Array>}
     */
    function getAvailableClients(config, options) {
        var clients = createClients(config, options);

        return Promise.all(clients.map(function (client) {
            return client.isAvailable();
        })).then(function (results) {
            return clients.filter(function (client, index) {
                return results[index] === true;
            });
        });
    }

    return {
        ApplePay: ApplePay,
        GooglePay: GooglePay,
        CanceledError: CanceledError,
        createClients: createClients,
        getAvailableClients: getAvailableClients,
        formatAmount: formatAmount
    };
}));
