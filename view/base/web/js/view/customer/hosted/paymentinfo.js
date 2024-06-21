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

/*jshint jquery:true*/
define([
    'jquery',
    'Magento_Ui/js/modal/alert',
    'mage/translate'
], function ($, alert) {
    'use strict';

    $.widget('mage.authnetcimHostedPaymentInfo', {
        options: {
            target: null,
            paramUrl: null,
            updateCardUrl: null,
            successUrl: null,
            fieldPrefix: '#'
        },

        /**
         * Bind and initialize component
         * @private
         */
        _create: function () {
            this.element.find('#submit-address').on('click', this.saveAddress.bind(this));
            this.element.find('#edit-address').on('click', this.editAddress.bind(this));

            this.bindCommunicator();
        },

        /**
         * Edit billing address
         */
        editAddress: function () {
            this.element.find('.address').show();
            this.element.find('.payment').hide();
        },

        /**
         * Confirm billing address
         */
        saveAddress: function () {
            if (this.element.valid() === false) {
                return;
            }

            this.renderAddress();

            this.element.find('.address').hide();
            this.element.find('.payment').show();

            this.fixScroll();

            this.initHostedForm();
        },

        /**
         * Draw address inputs to text
         */
        renderAddress: function () {
            var address = $(this.options.fieldPrefix + 'firstname').val() + ' ';
            address += $(this.options.fieldPrefix + 'lastname').val() + '<br />';
            address += $(this.options.fieldPrefix + 'company').val()
                       ? $(this.options.fieldPrefix + 'company').val() + '<br />'
                       : '';
            address += $(this.options.fieldPrefix + 'street').val() + '<br />';
            address += $(this.options.fieldPrefix + 'street_2').val()
                       ? $(this.options.fieldPrefix + 'street_2').val() + '<br />'
                       : '';
            address += $(this.options.fieldPrefix + 'city').val() + ', ';
            address += $(this.options.fieldPrefix + 'region-id option:selected').text()
                       ? $(this.options.fieldPrefix + 'region-id option:selected').text() + ' '
                       : $(this.options.fieldPrefix + 'region').val() + ' ';
            address += $(this.options.fieldPrefix + 'zip').val() + '<br />';
            address += $(this.options.fieldPrefix + 'country option:selected').text() + '<br />';
            address += $(this.options.fieldPrefix + 'telephone').val()
                       ? $(this.options.fieldPrefix + 'telephone').val()
                       : '';

            this.element.find('address').html(address);
        },

        /**
         * Rescroll window upon address confirmation, if needed
         */
        fixScroll: function () {
            var topPosition = $('fieldset.payment:first').position().top;

            if (topPosition < window.scrollY) {
                window.scrollTo(0, topPosition);
            }
        },

        /**
         * Clear and reload the payment form
         */
        initHostedForm: function () {
            if (this.element.find('#' + this.options.target).is(':visible') === false) {
                return;
            }

            this.processingSave = false;

            // Clear and spinner the CC form while we load new params
            this.element.find('#' + this.options.target).prop('src', 'about:blank')
                .trigger('processStart');

            return $.post({
                url: this.options.paramUrl,
                dataType: 'json',
                data: this.element.serialize(),
                global: false,
                success: this.loadHostedForm.bind(this),
                error: this.handleAjaxError.bind(this)
            });
        },

        /**
         * Post data to iframe to load the hosted payment form
         * @param data
         */
        loadHostedForm: function (data) {
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

            // Reload after 15 min expiration
            setTimeout(this.initHostedForm.bind(this), 15*60*1000);

            this.element.find('#' + this.options.target).trigger('processStop');
        },

        /**
         * Display error message when AJAX request fails
         * @param jqXHR
         * @param status
         * @param error
         */
        handleAjaxError: function (jqXHR, status, error) {
            this.processingSave = false;
            this.element.find('#' + this.options.target).trigger('processStop');

            var message = $.mage.__('A server error occurred. Please try again.');

            try {
                var responseJson = JSON.parse(jqXHR.responseText);
                if (responseJson.message !== undefined) {
                    message = responseJson.message;
                }
            } catch (error) {
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
         * Listen for messages from the payment form iframe
         */
        bindCommunicator: function () {
            window.removeEventListener(
                'message',
                this.handleCommunication.bind(this),
                true
            );

            window.addEventListener(
                'message',
                this.handleCommunication.bind(this),
                true
            );
        },

        /**
         * Validate and process a message from the payment form
         * @param event
         */
        handleCommunication: function (event) {
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
                case 'successfulSave':
                    this.handleSave(event.data);
                    break;
                case 'resizeWindow':
                    var height = Math.ceil(parseFloat(event.data.height));
                    this.element.find(this.options.fieldPrefix + this.options.target).height(height + 'px');
                    break;
            }
        },

        /**
         * Reload the page to reset the form and address.
         * @param response
         */
        handleCancel: function (response) {
            location.assign(location.href);
        },

        /**
         * Fetch new card details upon payment form completion
         * @param event
         */
        handleSave: function (event) {
            if (this.processingSave || this.element.find('#' + this.options.target).is(':visible') === false) {
                return;
            }

            this.processingSave = true;
            this.element.find('#' + this.options.target).trigger('processStart');

            $.post({
                url: this.options.updateCardUrl,
                dataType: 'json',
                data: this.element.serialize(),
                global: false,
                success: this.updateCard.bind(this),
                error: this.handleAjaxError.bind(this)
            });
        },

        /**
         * Complete card edit process; save address to card
         * @param data
         */
        updateCard: function (response) {
            this.processingSave = false;

            this.element.find('input[name="id"], input[name="card_id"]').val(response.card.id);
            this.element.find('#' + this.options.target).trigger('processStop');
            this.element.trigger('submit');
        }
    });

    return $.mage.authnetcimHostedPaymentInfo;
});
