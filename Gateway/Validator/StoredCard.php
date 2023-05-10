<?php declare(strict_types=1);
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

use ParadoxLabs\Authnetcim\Model\ConfigProvider;

class StoredCard extends \ParadoxLabs\TokenBase\Gateway\Validator\StoredCard
{
    /**
     * @var \ParadoxLabs\TokenBase\Gateway\Validator\CreditCard
     */
    private $ccValidator;

    /**
     * @var \Magento\Payment\Gateway\ConfigInterface
     */
    private $config;

    /**
     * Constructor
     *
     * @param \Magento\Payment\Gateway\Validator\ResultInterfaceFactory $resultFactory
     * @param \ParadoxLabs\TokenBase\Gateway\Validator\CreditCard $ccValidator
     * @param \Magento\Payment\Gateway\ConfigInterface $config
     */
    public function __construct(
        \Magento\Payment\Gateway\Validator\ResultInterfaceFactory $resultFactory,
        \ParadoxLabs\TokenBase\Gateway\Validator\CreditCard $ccValidator,
        \Magento\Payment\Gateway\ConfigInterface $config
    ) {
        parent::__construct($resultFactory, $ccValidator, $config);

        $this->ccValidator = $ccValidator;
        $this->config = $config;
    }

    /**
     * Validate a stored card on the payment object
     *
     * @param array $validationSubject
     * @return \Magento\Payment\Gateway\Validator\ResultInterface
     */
    public function validate(array $validationSubject)
    {
        // If Accept.js is enabled, kick to standard CC validator
        if ((int)$this->config->getValue('form_type') === ConfigProvider::FORM_ACCEPTJS) {
            return parent::validate($validationSubject);
        }

        $isValid = true;
        $fails   = [];

        /** @var \Magento\Payment\Model\Info $payment */
        $payment = $validationSubject['payment'];

        /**
         * If we have a tokenbase ID, we're using a stored card.
         */
        $tokenbaseId = $payment->getData('tokenbase_id');
        if (!empty($tokenbaseId)) {
            /**
             * If Require CCV is enabled, enforce it.
             */
            if ($this->config->getValue('require_ccv') == 1
                && (bool)$payment->getAdditionalInformation('is_subscription_generated') !== true
                && empty($payment->getAdditionalInformation('transaction_id'))
                && $payment->getData('tokenbase_source') !== 'paymentinfo') {
                $ccvLength = null;
                $ccvLabel  = 'CVV';

                $ccType = $payment->getData('cc_type');
                if (!empty($ccType)) {
                    $typeInfo = $this->ccValidator->getCcTypes()->getType($ccType);
                    if ($typeInfo !== false) {
                        $ccvLength = $typeInfo['code']['size'];
                        $ccvLabel  = $typeInfo['code']['name'];
                    }
                }

                if (!is_numeric($payment->getData('cc_cid'))
                    || ($ccvLength !== null && strlen((string)$payment->getData('cc_cid')) !== (int)$ccvLength)
                    || strlen((string)$payment->getData('cc_cid')) < 3) {
                    $isValid = false;
                    $fails[] = __('Please enter your credit card %1.', $ccvLabel);
                }
            }
        }

        return $this->createResult($isValid, $fails);
    }
}
