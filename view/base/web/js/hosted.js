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
            target: null,
            paramUrl: null,
            newCardUrl: null,
            cardSelector: '[name="payment[card_id]"]'
        },

        processingSave: false,

        _create: function() {
            this.element.on('change', this.options.cardSelector, this.handleCardSelectChange.bind(this));

            this.handleCardSelectChange();
        },

        handleCardSelectChange: function() {
            if (this.element.find(this.options.cardSelector).val() !== '') {
                this.element.find('div.cvv').show();
                this.element.find('div.save').toggle(
                    !!this.element.find(this.options.cardSelector + ' option:selected').data('new')
                );

                return;
            }

            // Hide additional fields when iframe is visible
            this.element.find('div.cvv').hide();
            this.element.find('div.save').hide();

            // Re/init iframe if 'add new card' is selected
            this.initHostedForm();
        },

        initHostedForm: function() {
            this.bindCommunicator();

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

        reloadExpiredHostedForm: function() {
            // If form has expired (15 minutes), and is still being displayed, force reload it.
            this.initHostedForm();
        },

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
                    this.element.find('#' + this.options.target).height(height + 'px');
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

        addAndSelectCard: function(data) {
            this.element.find('#' + this.options.target).trigger('processStop');

            var card   = data.card;
            var option = $('<option>');
            option.val(card.id)
                  .text(card.label)
                  .data('new', card.new)
                  .data('type', card.cc_type)
                  .data('cc_bin', card.cc_bin)
                  .data('cc_last_4', card.cc_last_4);

            this.element.find(this.options.cardSelector).append(option).val(card.id).trigger('change');

            this.processingSave = false;
        },

        getFormParams: function() {
            var payload = {};
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
