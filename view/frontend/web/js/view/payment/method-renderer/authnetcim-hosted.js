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
        'Magento_Ui/js/modal/alert',
        'Magento_Checkout/js/model/quote',
        'mage/translate'
    ],
    function (ko, $, Component, alert, quote) {
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
                this.logoImage       = config ? config.logoImage : false;
            },

            /**
             * @override
             */
            initObservable: function () {
                this.initVars();
                this._super()
                    .observe([
                        'billingAddressLine'
                    ]);

                this.bindCommunicator();

                quote.billingAddress.subscribe(this.syncBillingAddress.bind(this));
                quote.paymentMethod.subscribe(this.syncBillingAddress.bind(this));
                this.billingAddressLine.subscribe(this.initHostedForm.bind(this));
                this.selectedCard.subscribe(this.checkReinitHostedForm.bind(this));

                this.showIframe = ko.computed(function() {
                    return (this.selectedCard() === null || this.selectedCard() === undefined)
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

                this.billingAddressLine(this.getAddressLine(quote.billingAddress()));
            },

            /**
             * Reload the payment form if circumstances require
             */
            checkReinitHostedForm: function() {
                if (this.iframeInitialized === false
                    && (this.selectedCard() === null || this.selectedCard() === undefined)
                    && this.storedCards().length > 0) {
                    // The initialized flag is to debounce and ensure we don't reinit unless absolutely necessary.
                    this.iframeInitialized = true;
                    this.initHostedForm();
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

                switch (event.data.action) {
                    case 'cancel':
                        this.handleCancel(event.data);
                        break;
                    case 'successfulSave':
                        break;
                    case 'resizeWindow':
                        var height = Math.ceil(parseFloat(event.data.height));
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
             * @override
             */
            getData: function () {
                return {
                    'method': this.item.method,
                    additional_data: {
                        'save': this.save(),
                        'cc_cid': this.creditCardVerificationNumber(),
                        'card_id': this.selectedCard()
                    }
                };
            }
        });
    }
);
