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
 * CheckoutFailureClearAcceptjsObserver Class
 */
class CheckoutFailureClearAcceptjsObserver implements \Magento\Framework\Event\ObserverInterface
{
    /**
     * @var \Magento\Sales\Api\OrderPaymentRepositoryInterface
     */
    private $orderPaymentRepository;

    /**
     * @var \Magento\Quote\Api\CartRepositoryInterface
     */
    private $quoteRepository;

    /**
     * @param \Magento\Sales\Api\OrderPaymentRepositoryInterface $orderPaymentRepository
     * @param \Magento\Quote\Api\CartRepositoryInterface $quoteRepository
     */
    public function __construct(
        \Magento\Sales\Api\OrderPaymentRepositoryInterface $orderPaymentRepository,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository
    ) {
        $this->orderPaymentRepository = $orderPaymentRepository;
        $this->quoteRepository = $quoteRepository;
    }

    /**
     * Assign data to the payment instance for our methods.
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            $this->clearAcceptJsTokens($observer->getEvent()->getData('order'));
            $this->clearAcceptJsTokens($observer->getEvent()->getData('quote'));
        } catch (\Exception $e) {
            // Ignore any errors; we don't want to throw them in this context.
        }
    }

    /**
     * Unset payment object values, to ensure they will not be reused.
     *
     * @param mixed $object
     * @return $this
     */
    protected function clearAcceptJsTokens($object)
    {
        if ($object instanceof \Magento\Quote\Model\Quote || $object instanceof \Magento\Sales\Model\Order) {
            $payment = $object->getPayment();

            if ($payment instanceof \Magento\Payment\Model\InfoInterface) {
                $acceptJsKey = $payment->getAdditionalInformation('acceptjs_key');
                $acceptJsValue = $payment->getAdditionalInformation('acceptjs_value');

                if (!empty($acceptJsKey) || !empty($acceptJsValue)) {
                    $payment->setAdditionalInformation('acceptjs_key', null);
                    $payment->setAdditionalInformation('acceptjs_value', null);

                    if ($payment->getId() > 0) {
                        if ($payment instanceof \Magento\Sales\Api\Data\OrderPaymentInterface) {
                            $this->orderPaymentRepository->save($payment);
                        } elseif ($payment instanceof \Magento\Quote\Api\Data\PaymentInterface
                            && $payment->getQuote() instanceof \Magento\Quote\Api\Data\CartInterface
                        ) {
                            $this->quoteRepository->save($payment->getQuote());
                        }
                    }
                }
            }
        }

        return $this;
    }
}
