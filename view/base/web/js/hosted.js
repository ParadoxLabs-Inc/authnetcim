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

/*jshint jquery:true*/
define([
    'jquery',
    'Magento_Ui/js/modal/alert',
    'mage/translate'
], function($, alert) {
    'use strict';

    $.widget('mage.authnetcimHostedForm', {
        options: {
            method: 'authnetcim',
            target: null,
            paramUrl: null,
            newCardUrl: null,
            cardSelector: '[name="payment[card_id]"]'
        },

        processingSave: false,

        /**
         * Bind and initialize component
         * @private
         */
        _create: function() {
            this.element.on('change', this.options.cardSelector, this.handleCardSelectChange.bind(this));

            // Admin only listener
            if (typeof order === 'object') {
                $('body').on('beforeSubmitOrder', '#edit_form', this.checkHostedFormStatus.bind(this));
            }

            this.bindCommunicator();

            this.handleCardSelectChange();
        },

        /**
         * Reload the payment form/toggle fields if circumstances require
         */
        handleCardSelectChange: function() {
            if (this.element.find(this.options.cardSelector).val() !== '') {
                this.element.find('input.cvv').prop('disabled', false);
                this.element.find('div.cvv').show();
                this.element.find('div.save').toggle(
                    !!this.element.find(this.options.cardSelector + ' option:selected').data('new')
                );

                return;
            }

            // Hide additional fields when iframe is visible
            this.element.find('input.cvv').prop('disabled', true);
            this.element.find('div.cvv').hide();
            this.element.find('div.save').hide();

            // Re/init iframe if 'add new card' is selected
            this.initHostedForm();
        },

        /**
         * Clear and reload the payment form
         */
        initHostedForm: function() {
            if (this.element.find('#' + this.options.target).is(':visible') === false) {
                return;
            }

            // Clear and spinner the CC form while we load new params
            this.element.find('#' + this.options.target).prop('src', 'about:blank')
                .trigger('processStart');

            var payload = this.getFormParams();

            return $.post({
                url: this.options.paramUrl,
                dataType: 'json',
                data: payload,
                global: false,
                success: this.loadHostedForm.bind(this),
                error: this.handleAjaxError.bind(this)
            });
        },

        /**
         * Reload the payment form when it's expired
         */
        reloadExpiredHostedForm: function() {
            // If form has expired (15 minutes), and is still being displayed, force reload it.
            this.initHostedForm();
        },

        /**
         * Post data to iframe to load the hosted payment form
         * @param data
         */
        loadHostedForm: function(data, status, jqXHR) {
            if (data.iframeAction === undefined) {
                return this.handleAjaxError(jqXHR, status, data);
            }

            var form = document.createElement('form');
            form.target = this.options.target;
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

            this.element.find('#' + this.options.target).trigger('processStop');
        },

        /**
         * Display error message when AJAX request fails
         * @param jqXHR
         * @param status
         * @param error
         */
        handleAjaxError: function(jqXHR, status, error) {
            var iframe  = this.element.find('#' + this.options.target);
            var message = $.mage.__('A server error occurred. Please try again.');

            iframe.trigger('processStop');
            this.processingSave = false;

            try {
                var responseJson = JSON.parse(jqXHR.responseText);
                if (responseJson.message !== undefined) {
                    message = responseJson.message;
                }
            } catch (error) {}

            if (iframe.siblings('.message').length > 0) {
                iframe.siblings('.message').text(message).show();
                iframe.hide();
                return;
            }

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
         * Prevent standard button submit if the hosted form is active
         */
        checkHostedFormStatus: function(event) {
            if (this.element.find('#' + this.options.target).is(':visible') === false) {
                return;
            }

            var message = $.mage.__('Please use the payment form to complete the order.');

            try {
                alert({
                    title: $.mage.__('Error'),
                    content: message
                });
            } catch (error) {
                // Fall back to standard alert if jq widget hasn't initialized yet
                window.alert(message);
            }

            if (typeof order === 'object') {
                $('#edit_form').trigger('processStop');
                $('#order-billing_method')[0].scrollIntoView();
            }

            return false;
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
                || this.element.find('#' + this.options.target).is(':visible') === false) {
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
                case "transactResponse":
                    this.handleResponse(JSON.parse(event.data.response));
                    break;
                case 'successfulSave':
                    this.handleSave(event.data);
                    break;
                case 'resizeWindow':
                    var height = Math.ceil(parseFloat(event.data.height));
                    this.element.find('#' + this.options.target).height(height + 'px');
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
                this.element.find('input[name="payment[save]"]').val(1).prop('checked', true);
            } else {
                this.element.find('input[name="payment[save]"]').val(0).prop('checked', false);
            }

            this.element.find('#' + this.options.method + '-transaction-id').val(response.transId).trigger('change');

            if (typeof order === 'object') {
                $('#edit_form').trigger('realOrder');
            } else {
                this.element.closest('form').submit();
            }

            this.iframeInitialized = false;
        },

        /**
         * Fetch new card details upon payment form completion
         * @param event
         */
        handleSave: function(event) {
            if (this.processingSave || this.element.find('#' + this.options.target).is(':visible') === false) {
                return;
            }

            this.processingSave = true;
            this.element.find('#' + this.options.target).trigger('processStart');

            $.post({
                url: this.options.newCardUrl,
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
            this.element.find('#' + this.options.target).trigger('processStop');

            if (data.card.method !== this.options.method) {
                return;
            }

            var card   = data.card;
            var option = $('<option>');
            option.val(card.id)
                  .text(card.label)
                  .data('new', card.new)
                  .data('type', card.cc_type)
                  .data('cc_bin', card.cc_bin)
                  .data('cc_last4', card.cc_last4);

            this.element.find(this.options.cardSelector).append(option).val(card.id).trigger('change');

            this.processingSave = false;

            if (this.element.find('#' + this.options.method + '-cc-cid').length > 0) {
                this.element.find('#' + this.options.method + '-cc-cid').trigger('focus');
            }
        },

        /**
         * Get AJAX request parameters from form input
         * @returns {{}}
         */
        getFormParams: function() {
            var payload = {
                'method': this.options.method
            };

            var inputs = this.element.find(':input');
            for (var key = 0; key < inputs.length; key++) {
                if (inputs[key] === undefined
                    || inputs[key] === null
                    || inputs[key].name === undefined
                    || inputs[key].name.length === 0) {
                    continue;
                }

                payload[inputs[key].name] = $(inputs[key]).val();
            }

            return payload;
        }
    });

    return $.mage.authnetcimHostedForm;
});
