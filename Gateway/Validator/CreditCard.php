<?php
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

namespace ParadoxLabs\Authnetcim\Gateway\Validator;

/**
 * CreditCard Class
 */
class CreditCard extends \ParadoxLabs\TokenBase\Gateway\Validator\CreditCard
{
    /**
     * Performs domain-related validation for business object
     *
     * @param array $validationSubject
     * @return \Magento\Payment\Gateway\Validator\ResultInterface
     */
    public function validate(array $validationSubject)
    {
        $isValid = true;
        $fails   = [];

        /** @var \Magento\Payment\Model\Info $payment */
        $payment = $validationSubject['payment'];

        /**
         * Comply with the configuration settings for allowed card types.
         */
        $typeInfo = $payment->getData('cc_type');
        $availableTypes = explode(',', $this->config->getValue('cctypes'));
        if (isset($typeInfo) && in_array($typeInfo, $availableTypes, true) === false) {
            // Is the type allowed?
            $isValid = false;
            $fails[] = __('This credit card type is not allowed for this payment method.');
        }

        if ($this->isAcceptJsEnabled() === true
            && strlen(str_replace(['X', '-'], '', (string)$payment->getData('cc_number'))) > 4) {
            // This gets triggered if Accept.js is enabled but we received raw credit card data anyway.
            // We don't ever want that, so refuse to process it. Whatever happened must be fixed.
            $isValid = false;
            $fails[] =__(
                'We did not receive the expected Accept.js data. Please verify payment details and try again.'
                .' If you get this error twice, contact support.'
            );
        }

        /**
         * If we do not have Accept.js info, apply normal CC validation.
         */
        $acceptJsValue = $payment->getAdditionalInformation('acceptjs_value');

        if (empty($fails) && empty($acceptJsValue)) {
            return parent::validate($validationSubject);
        }

        return $this->createResult($isValid, $fails);
    }

    /**
     * Determine whether Accept.js is configured.
     */
    public function isAcceptJsEnabled()
    {
        $clientKey = $this->config->getValue('client_key');

        if ($this->config->getValue('acceptjs') == 1 && !empty($clientKey)) {
            return true;
        }

        return false;
    }
}
