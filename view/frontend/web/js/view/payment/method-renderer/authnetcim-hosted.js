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
        var config=window.checkoutConfig.payment.authnetcim;
        return Component.extend({
            defaults: {
                template: 'ParadoxLabs_Authnetcim/payment/hosted',
                save: config ? config.canSaveCard && config.defaultSaveCard : false,
                selectedCard: config ? config.selectedCard : '',
                storedCards: config ? config.storedCards : {},
                logoImage: config ? config.logoImage : false,
                iframeInitialized: false,
                processingSave: false
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
                        'billingAddressLine'
                    ]);

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

                this.storedCards = ko.observableArray(config.storedCards);

                this.useVault = ko.computed(function() {
                    return this.storedCards().length > 0;
                }, this);

                return this;
            },

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

            checkReinitHostedForm: function() {
                if (this.iframeInitialized === false
                    && (this.selectedCard() === null || this.selectedCard() === undefined)
                    && this.storedCards().length > 0) {
                    // The initialized flag is to debounce and ensure we don't reinit unless absolutely necessary.
                    this.iframeInitialized = true;
                    this.initHostedForm();
                }
            },

            reloadExpiredHostedForm: function() {
                if (this.iframeInitialized === true) {
                    // If form has expired (15 minutes), and is still being displayed, force reload it.
                    this.initHostedForm();
                }
            },

            initHostedForm: function() {
                this.bindCommunicator();

                // Clear and spinner the CC form while we load new params
                $('#' + this.getCode() + '_iframe').prop('src', 'about:blank')
                    .trigger('processStart');

                $.post({
                    url: config.paramUrl,
                    dataType: 'json',
                    data: this.getFormParams(),
                    global: false,
                    success: this.loadForm.bind(this),
                    error: this.handleAjaxError.bind(this)
                });
            },

            loadForm: function(data) {
                var form = document.createElement('form');
                form.target = this.getCode() + '_iframe';
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

                setTimeout(this.reloadExpiredHostedForm.bind(this), 15*60*1000);

                $('#' + this.getCode() + '_iframe').trigger('processStop');
            },

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

            bindCommunicator: function() {
                window.addEventListener(
                    'message',
                    this.handleCommunication.bind(this),
                    false
                );
            },

            handleCommunication: function(event) {
                if (typeof location.origin === 'undefined') {
                    location.origin = location.protocol + '//' + location.host;
                }

                if (event.origin !== location.origin || !event.data || !event.data.action) {
                    console.error('Ignored untrusted message from ' + event.origin);
                    return;
                }

                switch (event.data.action) {
                    case 'cancel':
                        this.handleCancel(event.data);
                        break;
                    case 'successfulSave':
                        this.handleSave(event.data);
                        break;
                    case 'resizeWindow':
                        var height = Math.ceil(parseFloat(event.data.height));
                        $('#' + this.getCode() + '_iframe').height(height + 'px');
                        break;
                }
            },

            handleCancel: function(response) {
                this.initHostedForm();
            },

            handleSave: function(event) {
                if (this.processingSave) {
                    console.log('Ignored duplicate handleSave');
                    return;
                }

                this.processingSave = true;
                $('#' + this.getCode() + '_iframe').trigger('processStart');

                $.post({
                    url: config.newCardUrl,
                    dataType: 'json',
                    data: this.getFormParams(),
                    global: false,
                    success: this.addAndSelectCard.bind(this),
                    error: this.handleAjaxError.bind(this)
                });
            },

            addAndSelectCard: function(data) {
                $('#' + this.getCode() + '_iframe').trigger('processStop');

                this.storedCards.push(data.card);
                this.selectedCard(data.card.id);
                this.iframeInitialized = false;
                this.processingSave = false;
            },

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
                    'source': 'checkout',
                    'method': this.getCode(),
                    'guest_email': quote.guestEmail !== undefined ? quote.guestEmail : null,
                    'form_key': this.getFormKey()
                }
            },

            getFormKey: function() {
                return $('input[name="form_key"]').val();
            },

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
