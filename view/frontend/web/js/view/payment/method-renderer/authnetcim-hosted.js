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

define(
    [
        'ko',
        'jquery',
        'ParadoxLabs_TokenBase/js/view/payment/method-renderer/cc',
        'Magento_Ui/js/modal/alert',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/payment/additional-validators',
        'mage/translate'
    ],
    function (ko, $, Component, alert, quote, additionalValidators) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'ParadoxLabs_Authnetcim/payment/hosted',
                iframeInitialized: false,
                processingSave: false
            },

            initVars: function() {
                var config=window.checkoutConfig.payment[this.index];

                this.canSaveCard     = config ? config.canSaveCard : false;
                this.forceSaveCard   = config ? config.forceSaveCard : false;
                this.defaultSaveCard = config ? config.defaultSaveCard : false;
                this.storedCards     = ko.observableArray(config ? config.storedCards : null);
                this.save            = config ? config.canSaveCard && config.defaultSaveCard : false;
                this.selectedCard    = config ? config.selectedCard : '';
                this.requireCcv      = config ? config.requireCcv : false;
                this.paramUrl        = config ? config.paramUrl : null;
                this.newCardUrl      = config ? config.newCardUrl : null;
                this.logoImage       = config ? config.logoImage : false;
            },

            /**
             * @override
             */
            initObservable: function () {
                this.initVars();
                this._super()
                    .observe([
                        'billingAddressLine',
                        'transactionId',
                        'communicatorActive'
                    ]);

                this.bindCommunicator();

                quote.billingAddress.subscribe(this.syncBillingAddress.bind(this));
                quote.paymentMethod.subscribe(this.syncBillingAddress.bind(this));
                quote.totals.subscribe(this.syncBillingAddress.bind(this))
                this.billingAddressLine.subscribe(this.initHostedForm.bind(this));
                this.selectedCard.subscribe(this.checkReinitHostedForm.bind(this));

                this.showIframe = ko.computed(function() {
                    return (this.selectedCard() === null || this.selectedCard() === undefined)
                           && (this.transactionId() === null || this.transactionId() === undefined)
                           && quote.billingAddress() !== null;
                }, this);

                this.showSaveOption = ko.computed(function() {
                    if (this.canSaveCard !== true
                        || this.selectedCard() === null
                        || this.selectedCard() === undefined) {
                        return false;
                    }

                    var cards = this.storedCards();
                    for (var key in cards) {
                        if (cards[key].id === this.selectedCard()) {
                            return cards[key].new;
                        }
                    }

                    return false;
                }, this);

                this.useVault = ko.computed(function() {
                    return this.storedCards().length > 0;
                }, this);

                // Revalidate checkout agreements for hosted form upon any changes in that area
                $('#' + this.getCode() + '-agreements').on(
                    'click change',
                    'input textarea select',
                    this.checkReinitHostedForm.bind(this)
                );

                return this;
            },

            /**
             * Track billing address changes
             */
            syncBillingAddress: function() {
                // Don't progess until the iframe has rendered, we're the active payment method, we have a billing addr.
                if ($('#' + this.getCode() + '_iframe').length === 0
                    || quote.paymentMethod() === null
                    || quote.paymentMethod().method !== this.getCode()
                    || this.selectedCard()
                    || quote.billingAddress() === null) {
                    return;
                }

                this.billingAddressLine(this.getAddressLine(quote.billingAddress()) + '|' + quote.totals().grand_total);
            },

            /**
             * Reload the payment form if circumstances require
             */
            checkReinitHostedForm: function() {
                if (this.iframeInitialized === false
                    && (this.selectedCard() === null || this.selectedCard() === undefined)
                    && additionalValidators.validate() === true) {
                    // The initialized flag is to debounce and ensure we don't reinit unless absolutely necessary.
                    this.iframeInitialized = true;
                    this.initHostedForm();
                }

                // Ensure place order button does not appear when iframe is visible (EG One-Step Checkouts)
                var placeOrderButton = $('#' + this.getCode() + '-submit');
                if (this.iframeInitialized && !this.selectedCard()) {
                    placeOrderButton.css('visibility', 'hidden');
                } else {
                    placeOrderButton.css('visibility', 'visible');
                }
            },

            /**
             * Reload the payment form when it's expired
             */
            reloadExpiredHostedForm: function() {
                if (this.iframeInitialized === true) {
                    // If form has expired (15 minutes), and is still being displayed, force reload it.
                    this.initHostedForm();
                }
            },

            /**
             * Clear and reload the payment form
             */
            initHostedForm: function() {
                // Clear and spinner the CC form while we load new params
                $('#' + this.getCode() + '_iframe').prop('src', 'about:blank')
                    .trigger('processStart');

                $.post({
                    url: this.paramUrl,
                    dataType: 'json',
                    data: this.getFormParams(),
                    global: false,
                    success: this.loadForm.bind(this),
                    error: this.handleAjaxError.bind(this)
                });
            },

            /**
             * Post data to iframe to load the hosted payment form
             * @param data
             */
            loadForm: function(data) {
                var iframe = $('#' + this.getCode() + '_iframe');
                iframe[0].contentWindow.name = iframe.attr('name');

                var form = document.createElement('form');
                form.target = iframe.attr('name');
                form.method = 'post';
                form.action = data.iframeAction;

                for (var key in data.iframeParams) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = data.iframeParams[key];
                    form.appendChild(input);
                }

                document.body.appendChild(form);
                form.submit();

                // Reload the hosted form when it expires
                setTimeout(this.reloadExpiredHostedForm.bind(this), 15*60*1000);

                // Verify communicator connected
                this.communicatorActive(false);
                setTimeout(this.checkCommunicator.bind(this), 20*1000);

                // There's an awkward break between 400-750px; set max width to avoid scrolling.
                if (iframe.width() > 400 && iframe.width() < 750) {
                    iframe.css('max-width', '400px');
                }

                iframe.trigger('processStop');
            },

            /**
             * Display error message when AJAX request fails
             * @param jqXHR
             * @param status
             * @param error
             */
            handleAjaxError: function(jqXHR, status, error) {
                var message = $.mage.__('A server error occurred. Please try again.');

                try {
                    var responseJson = JSON.parse(jqXHR.responseText);
                    if (responseJson.message !== undefined) {
                        message = responseJson.message;
                    }
                } catch (error) {}

                $('#' + this.getCode() + '_iframe').trigger('processStop');
                this.processingSave = false;
                this.transactionId(null);

                try {
                    alert({
                        title: $.mage.__('Error'),
                        content: message
                    });
                } catch (error) {
                    // Fall back to standard alert if jq widget hasn't initialized yet
                    window.alert(message);
                }
            },

            /**
             * Listen for messages from the payment form iframe
             */
            bindCommunicator: function() {
                window.addEventListener(
                    'message',
                    this.handleCommunication.bind(this),
                    false
                );
            },

            /**
             * Throw an error if the communicator has not connected after 30 seconds (bad)
             */
            checkCommunicator: function() {
                if (this.communicatorActive()
                    || !this.showIframe()
                    || this.iframeInitialized === false
                    || $('#' + this.getCode() + '_iframe').is(':visible') === false) {
                    return;
                }

                var message = $.mage.__('Payment gateway failed to connect. Please reload and try again. If the problem'
                                    + ' continues, please seek support.');

                console.error('No message received from communicator.', message);

                try {
                    alert({
                        title: $.mage.__('Error'),
                        content: message
                    });
                } catch (error) {
                    // Fall back to standard alert if jq widget hasn't initialized yet
                    window.alert(message);
                }
            },

            /**
             * Validate and process a message from the payment form
             * @param event
             */
            handleCommunication: function(event) {
                if (!event.data
                    || !event.data.action
                    || $('#' + this.getCode() + '_iframe').is(':visible') === false) {
                    return;
                }

                if (typeof location.origin === 'undefined') {
                    location.origin = location.protocol + '//' + location.host;
                }

                if (event.origin !== location.origin) {
                    console.error('Ignored untrusted message from ' + event.origin);
                    return;
                }

                this.communicatorActive(true);

                switch (event.data.action) {
                    case 'cancel':
                        this.handleCancel(event.data);
                        break;
                    case "transactResponse":
                        this.handleResponse(JSON.parse(event.data.response));
                        break;
                    case 'successfulSave':
                        this.handleSave(event.data);
                        break;
                    case 'resizeWindow':
                        var height = Math.ceil(parseFloat(event.data.height)) + 80;
                        $('#' + this.getCode() + '_iframe').height(height + 'px');
                        break;
                }
            },

            /**
             * Reinitialize the form when canceled
             * @param response
             */
            handleCancel: function(response) {
                this.initHostedForm();
            },

            /**
             * Process payment transaction result (place the order)
             * @param response
             */
            handleResponse: function(response) {
                if (response.createPaymentProfileResponse !== undefined
                    && response.createPaymentProfileResponse.success === 'true') {
                    this.save(true);
                } else {
                    this.save(false);
                }

                this.transactionId(response.transId);

                this.placeOrder();

                this.iframeInitialized = false;
            },

            /**
             * Fetch new card details upon payment form completion
             * @param event
             */
            handleSave: function(event) {
                if (this.processingSave) {
                    console.log('Ignored duplicate handleSave');
                    return;
                }

                this.processingSave = true;
                $('#' + this.getCode() + '_iframe').trigger('processStart');

                $.post({
                    url: this.newCardUrl,
                    dataType: 'json',
                    data: this.getFormParams(),
                    global: false,
                    success: this.addAndSelectCard.bind(this),
                    error: this.handleAjaxError.bind(this)
                });
            },

            /**
             * Add and select new card on the UI after completing the payment form
             * @param data
             */
            addAndSelectCard: function(data) {
                $('#' + this.getCode() + '_iframe').trigger('processStop');

                if (data.card.method !== this.getCode()) {
                    return;
                }

                this.storedCards.push(data.card);
                this.selectedCard(data.card.id);
                this.iframeInitialized = false;
                this.processingSave = false;

                if (this.hasVerification()) {
                    $('#' + this.getCode() + '-cc-cid').trigger('focus');
                }
            },

            /**
             * Get stringified address from address object
             * @param address
             * @returns {string|null}
             */
            getAddressLine: function(address) {
                if (address === null) {
                    return null;
                }

                if (typeof address.street === 'string') {
                    address.street.split("\n");
                }

                return address.firstname + ' '
                       + address.lastname + ', '
                       + address.street.join(' ') + ', '
                       + address.city + ', '
                       + address.region + ' '
                       + address.postcode + ', '
                       + address.countryId + ' '
                       + address.telephone;
            },

            /**
             * Get AJAX request parameters from form input
             * @returns {{}}
             */
            getFormParams: function() {
                var billingAddress = _.pick(
                    quote.billingAddress(),
                    [
                        'firstname',
                        'lastname',
                        'company',
                        'street',
                        'city',
                        'regionCode',
                        'regionId',
                        'region',
                        'postcode',
                        'countryId',
                        'telephone'
                    ]
                );

                if (quote.guestEmail !== undefined && quote.guestEmail !== null) {
                    billingAddress.email = quote.guestEmail;
                }

                return {
                    'billing': billingAddress,
                    'method': this.getCode(),
                    'guest_email': quote.guestEmail !== undefined ? quote.guestEmail : null,
                    'form_key': this.getFormKey()
                }
            },

            /**
             * Get session form key
             * @returns {*|string|jQuery}
             */
            getFormKey: function() {
                return $('input[name="form_key"]').val();
            },

            /**
             * Check for CVV requirement
             * @returns {*}
             */
            hasVerification: function () {
                return this.requireCcv();
            },

            /**
             * Validate the form before order placement
             * @returns {boolean}
             */
            validate: function () {
                if (this.transactionId()) {
                    return true;
                }

                return this._super();
            },

            /**
             * Allow order placement
             */
            checkPlaceOrderAllowed: function () {
                if (this.transactionId && this.transactionId()) {
                    return true;
                }

                return this._super();
            },

            /**
             * @override
             */
            getData: function () {
                return {
                    'method': this.item.method,
                    additional_data: {
                        'save': this.save(),
                        'cc_cid': this.creditCardVerificationNumber(),
                        'card_id': this.selectedCard(),
                        'transaction_id': this.transactionId()
                    }
                };
            },

            /**
             * @override
             */
            handleFailedOrder: function (response) {
                this.transactionId(null);
                this.iframeInitialized = false;
                this.checkReinitHostedForm();

                this._super(response);
            },
        });
    }
);
