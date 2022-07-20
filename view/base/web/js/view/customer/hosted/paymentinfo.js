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

    $.widget('mage.authnetcimHostedPaymentInfo', {
        options: {
            target: null,
            paramUrl: null,
            updateCardUrl: null,
            successUrl: null,
            fieldPrefix: '#'
        },

        _create: function() {
            this.element.find('#submit-address').on('click', this.saveAddress.bind(this));
            this.element.find('#edit-address').on('click', this.editAddress.bind(this));

            this.bindCommunicator();
        },

        editAddress: function() {
            this.element.find('.address').show();
            this.element.find('.payment').hide();
        },

        saveAddress: function() {
            if (this.element.valid() === false) {
                return;
            }

            this.renderAddress();

            this.element.find('.address').hide();
            this.element.find('.payment').show();

            this.fixScroll();

            this.initHostedForm();
        },

        renderAddress: function() {
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

        fixScroll: function() {
            var topPosition = $('fieldset.payment:first').position().top;

            if (topPosition < window.scrollY) {
                window.scrollTo(0, topPosition);
            }
        },

        initHostedForm: function() {
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

        loadHostedForm: function(data) {
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

        handleAjaxError: function(jqXHR, status, error) {
            this.processingSave = false;
            this.element.find('#' + this.options.target).trigger('processStop');

            var message = $.mage.__('A server error occurred. Please try again.');

            try {
                var responseJson = JSON.parse(jqXHR.responseText);
                if (responseJson.message !== undefined) {
                    message = responseJson.message;
                }
            } catch (error) {}

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
                    this.element.find('#' + this.options.target).height(height + 'px');
                    break;
            }
        },

        handleCancel: function(response) {
            this.initHostedForm();
        },

        handleSave: function(event) {
            if (this.processingSave || this.element.find('#' + this.options.target).is(':visible') === false) {
                console.log('Ignored duplicate handleSave');
                return;
            }

            this.processingSave = true;
            this.element.find('#' + this.options.target).trigger('processStart');

            // TODO: Save address to card
            // TODO: Support updates to existing card
            $.post({
                url: this.options.updateCardUrl,
                dataType: 'json',
                data: this.element.serialize(),
                global: false,
                success: this.updateCard.bind(this),
                error: this.handleAjaxError.bind(this)
            });
        },

        updateCard: function(response) {
            this.processingSave = false;

            this.element.find('input[name="id"], input[name="card_id"]').val(response.card.id);
            this.element.find('#' + this.options.target).trigger('processStop');
            this.element.trigger('submit');
        }
    });

    return $.mage.authnetcimHostedPaymentInfo;
});
