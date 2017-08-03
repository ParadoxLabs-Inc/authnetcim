/**
 * Paradox Labs, Inc.
 * http://www.paradoxlabs.com
 * 717-431-3330
 *
 * Need help? Open a ticket in our support system:
 *  http://support.paradoxlabs.com
 *
 * @category    ParadoxLabs
 * @package     AuthorizeNetCim
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
    "jquery",
    "mage/translate"
], function($) {
    "use strict";

    $.widget('mage.authnetcimAcceptjs', {
        options: {
            apiLoginId: '',
            clientKey: '',
            method: '',
            submitSelector: '',
            sandbox: false,
            processingSelector: '',
            processingOpacity: 0.5,
            messageFadeDelay: 1500,
            nonceRefreshDelay: 60000
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

        tmpHaveAllFields: null,
        tmpConcat: null,
        nonceConcat: null,
        timeout: null,

        /**
         * Initialize Accept.js interface
         */
        _create: function() {
            this.hasError = false;

            // If we aren't configured, stop here, do nothing.
            if (this.options.method == '' || this.options.apiLoginId == '' || this.options.clientKey == '') {
                return;
            }

            if (this.options.sandbox) {
                require(['authorizeNetAcceptjsSandbox'],
                    function() {
                        this.bind();
                    }.bind(this)
                );
            } else {
                require(['authorizeNetAcceptjs'],
                    function() {
                        this.bind();
                    }.bind(this)
                );
            }
        },

        /**
         * Hook onto elements of the current page, if we can
         */
        bind: function () {
            // Global callback and param is necessary for the vendor JS library.
            window['acceptJs_' + this.options.method + '_callback'] = function(response) {
                this.handlePaymentResponse(response);
            }.bind(this);

            window.isReady = true;

            if (this.element) {
                this.element.on(
                    'input change keyup',
                    'input:not([type=hidden]), select',
                    this.onFieldChange.bind(this)
                );
            }

            // Disable server-side validation
            if (typeof window.order != 'undefined' && typeof window.order.addExcludedPaymentMethod == 'function') {
                window.order.addExcludedPaymentMethod(this.options.method);
            }
        },

        /**
         * Check validity, request accept.js nonce if everything checks out
         */
        onFieldChange: function () {
            if (this.isValidForAcceptjs()) {
                this.tmpHaveAllFields = true;

                $(this.fields).each(function (i, elemIndex) {
                    var field = $(this.element).find('#' + this.options.method + elemIndex);

                    if (typeof field != 'undefined' && field.length > 0 && elemIndex != '-cc-type') {
                        // If we're missing a value or find a masked one, not valid for sending.
                        if ($(field).val().length < 1 || $(field).val().indexOf('XX') >= 0) {
                            this.tmpHaveAllFields = false;
                        }
                        else if (elemIndex == '-cc-cid' && $(field).val().length < 3) {
                            this.tmpHaveAllFields = false;
                        }
                    }
                }.bind(this));

                // If all fields are filled in, the form validates, and something has changed, request a nonce.
                if (this.tmpHaveAllFields === true && this.validate() && this.concatFields() != this.nonceConcat) {
                    this.nonceConcat = this.tmpConcat;

                    this.sendPaymentInfo();

                    // Refresh periodically to avoid 15-minute token expiration, and try to play nice with checkout errors.
                    if (this.timeout !== null) {
                        clearTimeout(this.timeout);
                    }

                    this.timeout = setTimeout(
                        function () {
                            this.nonceConcat = null;
                            this.onFieldChange();
                        }.bind(this),
                        this.options.nonceRefreshDelay
                    );
                }
            }
        },

        /**
         * Check whether Accept.js applies in the current situation
         */
        isValidForAcceptjs: function () {
            // In admin order process, check selected method.
            if (this.element && ( typeof window.order == 'undefined' || typeof window.order.paymentMethod == 'undefined' || window.order.paymentMethod == this.options.method )) {
                if ($(this.element).find('#' + this.options.method + '-cc-number').val() != '') {
                    return true;
                }
            }

            return false;
        },

        /**
         * Validate payment form fields before submit
         *
         * This isn't strictly necessary at this point. Accept.js has card validation built in.
         */
        validate: function () {
            return true;
        },

        /**
         * Send payment info via Accept.js
         */
        sendPaymentInfo: function () {
            this.startLoadWaiting();

            var form = $(this.element);
            var paymentData = {
                cardData: {
                    cardNumber: form.find('#' + this.options.method + '-cc-number').val().replace(/\D/g, ''),
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

            // Vendor JS library creates itself as a global object; we have to call it as such.
            Accept.dispatchData(
                paymentData,
                'acceptJs_' + this.options.method + '_callback'
            );
        },

        /**
         * Handle Accept.js response
         */
        handlePaymentResponse: function (response) {
            if (response.messages.resultCode === 'Error') {
                this.hasError = true;

                var messages = '';
                for (var i = 0; i < response.messages.message.length; i++) {
                    if (i > 0) {
                        messages += "\n";
                    }

                    messages += $.mage.__(response.messages.message[i].text + ' (' + response.messages.message[i].code + ')');
                }

                this.stopLoadWaiting(messages);
            }
            else {
                var cc_no = $(this.element).find('#' + this.options.method + '-cc-number').val();

                // Set data
                $(this.element).find('#' + this.options.method + '-acceptjs-key').val(response.opaqueData.dataDescriptor).trigger('change');
                $(this.element).find('#' + this.options.method + '-acceptjs-value').val(response.opaqueData.dataValue).trigger('change');
                $(this.element).find('#' + this.options.method + '-cc-last4').val(cc_no.substring(cc_no.length - 4)).trigger('change');

                var binField = $(this.element).find('#' + this.options.method + '-cc-bin');
                if (binField.length > 0) {
                    binField.val(cc_no.substring(0, 6)).trigger('change');
                }

                // Remove fields from request
                $(this.protectedFields).each(function (i, elemIndex) {
                    if (typeof $(this.element).find('#' + this.options.method + elemIndex) != 'undefined') {
                        $(this.element).find('#' + this.options.method + elemIndex).attr('name', '');
                    }
                }.bind(this));

                this.stopLoadWaiting(false);
            }
        },

        /**
         * Show the spinner effect on the CC fields while loading.
         */
        startLoadWaiting: function () {
            try {
                // Field opacity
                $(this.fields).each(function (i, elemIndex) {
                    var field = $(this.element).find('#' + this.options.method + elemIndex);

                    if (typeof field != 'undefined' && field.length > 0 && this.options.processingSelector != '') {
                        $(field).closest(this.options.processingSelector).css({
                            opacity: this.options.processingOpacity
                        });
                    }
                }.bind(this));

                // Spinner / messages
                $(this.element).find('#' + this.options.method + '-processing').addClass('_active').removeClass('no-display _hidden');
                $(this.element).find('#' + this.options.method + '-complete').addClass('no-display _hidden').removeClass('_active');
                $(this.element).find('#' + this.options.method + '-failed').addClass('no-display _hidden').removeClass('_active');
                $(this.element).find('#' + this.options.method + '-failed').find('.error-text').html('');

                // Button disable
                if (this.options.submitSelector && $(this.options.submitSelector).length > 0) {
                    $(this.options.submitSelector).addClass('disabled').prop('disabled', true);
                }
            } catch (error) {
                // do nothing on load-waiting error.
            }
        },

        /**
         * Remove the spinner effect on the CC fields.
         */
        stopLoadWaiting: function (error) {
            try {
                // Field opacity
                $(this.fields).each(function (i, elemIndex) {
                    var field = $(this.element).find('#' + this.options.method + elemIndex);

                    if (typeof field != 'undefined' && field.length > 0 && this.options.processingSelector != '') {
                        $(field).closest(this.options.processingSelector).css({opacity: ''});
                    }
                }.bind(this));

                // Spinner / messages
                $(this.element).find('#' + this.options.method + '-processing').addClass('no-display _hidden').removeClass('_active');

                if (error == false) {
                    $(this.element).find('#' + this.options.method + '-complete').addClass('_active').removeClass('no-display _hidden').css({opacity: ''});

                    setTimeout(
                        function () {
                            $(this.element).find('#' + this.options.method + '-complete').fadeOut(function() {
                                $(this).addClass('no-display _hidden').removeClass('_active').css('display', '');
                            });
                        }.bind(this),
                        this.options.messageFadeDelay
                    );
                }
                else {
                    $(this.element).find('#' + this.options.method + '-failed').find('.error-text').html(error);
                    $(this.element).find('#' + this.options.method + '-failed').addClass('_active').removeClass('no-display _hidden').css({opacity: ''});
                }

                // Button disable
                if (this.options.submitSelector && $(this.options.submitSelector).length > 0) {
                    $(this.options.submitSelector).removeClass('disabled').prop('disabled', false);
                }
            } catch (error) {
                // do nothing on load-waiting error.
            }
        },

        /**
         * Combine all fields into a single string for quick comparison purposes.
         */
        concatFields: function () {
            this.tmpConcat = '';

            $(this.fields).each(function (i, elemIndex) {
                var field = $(this.element).find('#' + this.options.method + elemIndex);

                if (typeof field != 'undefined' && field.length > 0 && $(field).val().length > 0) {
                    this.tmpConcat += $(field).val();
                }
            }.bind(this));

            return this.tmpConcat;
        }
    });

    return $.mage.authnetcimAcceptjs;
});
