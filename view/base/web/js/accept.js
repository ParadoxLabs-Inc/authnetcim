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

/**
 * Hook the given payment form onto Authorize.Net's Accept.js service.
 *
 * Accept.js swaps CC details with a nonce from Authorize.Net on-the-fly,
 * obviating the need to route that info through the web server.
 *
 * This class provides a bunch of logic to make that happen.
 */

/*jshint jquery:true*/
define([
    'jquery',
    'ko',
    'Magento_Ui/js/modal/alert',
    'mage/translate',
    'mage/validation',
    'domReady!'
    // NB: using jQuery rather than $ to avoid conflict on admin order form.
], function(jQuery, ko, alert) {
    'use strict';

    jQuery.widget('mage.authnetcimAcceptjs', {
        options: {
            apiLoginId: '',
            clientKey: '',
            method: '',
            formSelector: '',
            submitSelector: '',
            cardSelector: '',
            sandbox: false,
            validateForm: true
        },

        fields: [
            '-cc-type',
            '-cc-number',
            '-cc-exp-month',
            '-cc-exp-year',
            '-cc-cid'
        ],

        protectedFields: [
            '-cc-number'
        ],

        // Change certain error responses to be more useful.
        errorMap: {
            'E_WC_10': 'API credentials invalid. If you are an administrator, please correct the API Login ID.',
            'E_WC_19': 'API credentials invalid. If you are an administrator, please correct the API Login ID and'
                       + ' Client Key.',
            'E_WC_21': 'API credentials invalid. If you are an administrator, please correct the API Login ID and'
                       + ' Client Key.'
        },

        alreadyProcessing: false,

        /**
         * Initialize Accept.js interface
         */
        _create: function() {
            // If we aren't configured, stop here, do nothing.
            if (this.options.method === '' || this.options.apiLoginId === '' || this.options.clientKey === '') {
                return;
            }

            this.form = this.options.formSelector ? jQuery(this.options.formSelector) : this.element;

            this.isSubmitActionAllowed = ko.observable(null);
            this.acceptJsKey = ko.observable(null);
            this.acceptJsValue = ko.observable(null);
            this.creditCardLast4 = ko.observable(null);
            this.creditCardBin = ko.observable(null);
            this.creditCardType = ko.observable(null);

            if (this.options.sandbox) {
                require(['authorizeNetAcceptjsSandbox'],
                    this._bind.bind(this)
                );
            } else {
                require(['authorizeNetAcceptjs'],
                    this._bind.bind(this)
                );
            }
        },

        /**
         * Hook onto elements of the current page, if we can
         */
        _bind: function () {
            // Initialize Accept.js callback
            // Global callback and param is necessary for the vendor JS library.
            window[this.options.method + '_acceptJs_callback'] = this.handlePaymentResponse.bind(this);
            window.isReady = true;

            // Bind listeners
            if (jQuery('#tokenbase-wrapper').length > 0) {
                this.form.on('tokenbaseSave', this.handleFormSubmit.bind(this));
                this.form.on('tokenbaseFailure', this.handlePaymentResponseError.bind(this));
            } else if (typeof order === 'object') {
                if (this.isActivePaymentMethod()) {
                    this.form.off('submitOrder');
                }

                this.form.on('submitOrder', this.handleFormSubmit.bind(this));
                this.form.on('changePaymentMethod', this.handleFormPaymentChange.bind(this));
            } else {
                this.form.on('submit', this.handleFormSubmit.bind(this));
            }

            if (this.element) {
                this.element.on(
                    'input change keyup paste',
                    'input:not([type=hidden]), select',
                    this.onFieldChange.bind(this)
                );
                this.form.on(
                    'change',
                    'input[name="payment[method]"]',
                    this.onFieldChange.bind(this)
                );
            }

            this.isSubmitActionAllowed.subscribe(function (isSubmitActionAllowed) {
                var button = jQuery(this.options.submitSelector);

                if (isSubmitActionAllowed) {
                    button.removeClass('disabled').prop('disabled', false);
                } else {
                    button.addClass('disabled').prop('disabled', true);
                }
            }.bind(this));

            this.acceptJsKey.subscribe(function (acceptJsKey) {
                this.element.find('#' + this.options.method + '-acceptjs-key').val(acceptJsKey).trigger('change');
            }.bind(this));

            this.acceptJsValue.subscribe(function (acceptJsValue) {
                this.element.find('#' + this.options.method + '-acceptjs-value').val(acceptJsValue).trigger('change');
            }.bind(this));

            this.creditCardLast4.subscribe(function (creditCardLast4) {
                this.element.find('#' + this.options.method + '-cc-last4').val(creditCardLast4).trigger('change');
            }.bind(this));

            if (this.element.find('#' + this.options.method + '-cc-bin').length > 0) {
                this.creditCardBin.subscribe(function (creditCardBin) {
                    this.element.find('#' + this.options.method + '-cc-bin').val(creditCardBin).trigger('change');
                }.bind(this));
            }

            this.onFieldChange();

            // Disable server-side validation
            if (typeof window.order != 'undefined' && typeof window.order.addExcludedPaymentMethod == 'function') {
                window.order.addExcludedPaymentMethod(this.options.method);
            }
        },

        isActivePaymentMethod: function() {
            // Check the selected method.
            if (this.element) {
                if (typeof window.checkoutConfig !== 'undefined'
                    && typeof window.checkoutConfig.selectedPaymentMethod !== 'undefined'
                    && window.checkoutConfig.selectedPaymentMethod === this.options.method) {
                    return true;
                }

                if (typeof this.form !== 'undefined'
                    && this.form.find('[name="payment[method]"]:checked').val() === this.options.method) {
                    return true;
                }

                if (typeof this.form !== 'undefined'
                    && this.form.find('[name="method"]').val() === this.options.method) {
                    return true;
                }
            }

            return false;
        },

        selectedCard: function () {
            if (this.options.cardSelector !== '') {
                return this.element.find(this.options.cardSelector).val();
            }

            return '';
        },

        handleFormSubmit: function(event) {
            if (this.isActivePaymentMethod()) {
                if (this.selectedCard() || this.acceptJsValue()) {
                    this.form.trigger('realOrder');

                    return;
                }

                if (event) {
                    event.preventDefault();
                    event.stopPropagation();
                }

                // PDL#1918599 -- Be sure the validation is initialised, even though it will be 99.9% of the time.
                this.form.validation();
                if (this.validate() && this.options.validateForm && this.form.validation('isValid')) {
                    if (this.alreadyProcessing !== true) {
                        this.startLoadWaiting();
                        this.sendPaymentInfo();
                    }
                } else {
                    this.stopLoadWaiting();
                }

                this.form.data('preventSave', true);
            } else {
                this.form.trigger('realOrder');
            }
        },

        handleFormPaymentChange: function(event, method) {
            if (method === this.options.method) {
                // If we've switched to this method, rebind the submitOrder event (for admin ordering) to enforce
                // proper order of operations.
                this.form.off('submitOrder').on('submitOrder', this.handleFormSubmit.bind(this));
            }
        },

        onFieldChange: function () {
            if (this.isActivePaymentMethod()) {
                if (this.selectedCard()) {
                    this.isSubmitActionAllowed(true);
                    return;
                }

                var haveAllFields = true;
                jQuery(this.fields).each(function (i, elemIndex) {
                    var field = this.element.find('#' + this.options.method + elemIndex);

                    if (typeof field !== 'undefined' && field.length > 0 && elemIndex !== '-cc-type') {
                        // If we're missing a value or find a masked one, not valid for sending.
                        if (jQuery(field).val().length < 1 || jQuery(field).val().indexOf('XX') >= 0) {
                            haveAllFields = false;
                        } else if (elemIndex === '-cc-cid' && jQuery(field).val().length < 3) {
                            haveAllFields = false;
                        }
                    }
                }.bind(this));

                // Update isSubmitActionAllowed state
                this.isSubmitActionAllowed(haveAllFields);
            } else {
                this.isSubmitActionAllowed(true);
            }
        },

        /**
         * Validate payment form fields before submit
         */
        validate: function () {
            if (this.selectedCard()) {
                return true;
            }

            return jQuery.validator.validateSingleElement('#' + this.options.method + '-cc-number')
                   && jQuery.validator.validateSingleElement('#' + this.options.method + '-cc-exp-month')
                   && jQuery.validator.validateSingleElement('#' + this.options.method + '-cc-cid');
        },

        /**
         * Send payment info via Accept.js
         */
        sendPaymentInfo: function () {
            var form = this.element;
            var paymentData = {
                cardData: {
                    cardNumber: this.getCcNumber(),
                    month: form.find('#' + this.options.method + '-cc-exp-month').val(),
                    year: form.find('#' + this.options.method + '-cc-exp-year').val(),
                    cardCode: ''
                },
                authData: {
                    clientKey: this.options.clientKey,
                    apiLoginID: this.options.apiLoginId
                }
            };

            if (form.find('#' + this.options.method + '-cc-cid').length > 0) {
                paymentData['cardData']['cardCode'] = form.find('#' + this.options.method + '-cc-cid').val();
            }

            Accept.dispatchData(
                paymentData,
                this.options.method + '_acceptJs_callback'
            );
        },

        /**
         * Handle Accept.js response
         */
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
            this.creditCardLast4(null);
            this.creditCardBin(null);

            if (response.messages) {
                var messages = [];
                for (var i = 0; i < response.messages.message.length; i++) {
                    var errorText = response.messages.message[i].text;
                    if (typeof this.errorMap[response.messages.message[i].code] !== 'undefined') {
                        errorText = this.errorMap[response.messages.message[i].code];
                    }

                    messages.push(
                        jQuery.mage.__('Payment Error: %1 <small><em>(%2)</em></small>')
                            .replace('%1', jQuery.mage.__(errorText))
                            .replace('%2', response.messages.message[i].code)
                    );
                }

                this.stopLoadWaiting(messages.join("\n"));
            } else {
                this.stopLoadWaiting();
            }
        },

        handlePaymentResponseSuccess: function (response) {
            var cc_no = this.getCcNumber();

            // Set data
            this.acceptJsKey(response.opaqueData.dataDescriptor);
            this.acceptJsValue(response.opaqueData.dataValue);
            this.creditCardLast4(cc_no.substring(cc_no.length - 4));
            this.creditCardBin(cc_no.substring(0, 6));

            // Remove fields from request
            jQuery(this.protectedFields).each(function (i, elemIndex) {
                if (typeof this.element.find('#' + this.options.method + elemIndex) != 'undefined') {
                    this.element.find('#' + this.options.method + elemIndex).attr('name', '');
                }
            }.bind(this));

            // Submit form
            this.stopLoadWaiting();

            if (jQuery('#tokenbase-wrapper').length > 0) {
                this.form.trigger('submit');
            } else {
                jQuery(this.options.submitSelector).first().trigger('click');
            }
        },

        getCcNumber: function() {
            return this.element.find('#' + this.options.method + '-cc-number').val().replace(/\D/g,'');
        },

        /**
         * Show the spinner effect on the CC fields while loading.
         */
        startLoadWaiting: function () {
            this.alreadyProcessing = true;
            this.isSubmitActionAllowed(false);
            this.form.trigger('processStart');
        },

        /**
         * Remove the spinner effect on the CC fields.
         */
        stopLoadWaiting: function (error) {
            this.alreadyProcessing = false;
            this.onFieldChange();
            this.form.trigger('processStop');

            if (error) {
                alert({content:error});
            }
        }
    });

    return jQuery.mage.authnetcimAcceptjs;
});
