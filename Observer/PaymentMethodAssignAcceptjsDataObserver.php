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

namespace ParadoxLabs\Authnetcim\Observer;

/**
 * PaymentMethodAssignAcceptjsDataObserver Class
 */
class PaymentMethodAssignAcceptjsDataObserver implements \Magento\Framework\Event\ObserverInterface
{
    /**
     * @var \ParadoxLabs\TokenBase\Model\Method\Factory
     */
    private $methodFactory;

    /**
     * PaymentMethodAssignAcceptjsDataObserver constructor.
     *
     * @param \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
     */
    public function __construct(
        \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
    ) {
        $this->methodFactory = $methodFactory;
    }

    /**
     * Assign data to the payment instance for our methods.
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        /** @var \Magento\Payment\Model\MethodInterface $method */
        $method = $observer->getData('method');
        $tokenbaseMethod = $this->methodFactory->getMethodInstance($method->getCode());

        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = $observer->getData('payment_model');

        /** @var \Magento\Framework\DataObject $data */
        $data = $observer->getData('data');

        /**
         * Merge together data from additional_data array
         */
        if ($data->hasData('additional_data')) {
            foreach ($data->getData('additional_data') as $key => $value) {
                if ($data->getData($key) == false) {
                    $data->setData($key, $value);
                }
            }
        }

        /**
         * Store Accept.js info if given and enabled.
         */
        if ($tokenbaseMethod->isAcceptJsEnabled() === true
            && $data->getData('acceptjs_key') != ''
            && $data->getData('acceptjs_value') != '') {
            $payment->setAdditionalInformation('acceptjs_key', $data->getData('acceptjs_key'))
                    ->setAdditionalInformation('acceptjs_value', $data->getData('acceptjs_value'))
                    ->setCcLast4($data->getData('cc_last4'));

            if ($method->getConfigData('can_store_bin') == 1) {
                $payment->setAdditionalInformation('cc_bin', $data->getData('cc_bin'));
            }

            /**
             * Since we received payment data, reset any attached stored card.
             * If this is a card edit, the card ID will be reloaded later.
             * @see \ParadoxLabs\TokenBase\Observer\PaymentMethodAssignDataObserver::assignTokenbaseData()
             */
            $payment->setData('tokenbase_id', null);
        }
    }
}
