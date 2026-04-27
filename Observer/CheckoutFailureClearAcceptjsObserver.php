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
 *
 * @link https://support.paradoxlabs.com
 */

namespace ParadoxLabs\Authnetcim\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Payment\Model\InfoInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Model\Order;
use Throwable;

class CheckoutFailureClearAcceptjsObserver implements ObserverInterface
{
    /**
     * @param OrderPaymentRepositoryInterface $orderPaymentRepository
     * @param CartRepositoryInterface $quoteRepository
     */
    public function __construct(
        private readonly OrderPaymentRepositoryInterface $orderPaymentRepository,
        private readonly CartRepositoryInterface $quoteRepository
    ) {
    }

    /**
     * Assign data to the payment instance for our methods.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        try {
            $this->clearAcceptJsTokens($observer->getEvent()->getData('order'));
            $this->clearAcceptJsTokens($observer->getEvent()->getData('quote'));
        } catch (Throwable) {
            // Ignore any errors; we don't want to throw them in this context.
        }
    }

    /**
     * Unset payment object values, to ensure they will not be reused.
     *
     * @return $this
     */
    protected function clearAcceptJsTokens(mixed $object)
    {
        if ($object instanceof Quote || $object instanceof Order) {
            $payment = $object->getPayment();

            if ($payment instanceof InfoInterface) {
                $acceptJsKey = $payment->getAdditionalInformation('acceptjs_key');
                $acceptJsValue = $payment->getAdditionalInformation('acceptjs_value');

                if (!empty($acceptJsKey) || !empty($acceptJsValue)) {
                    $payment->setAdditionalInformation('acceptjs_key', null);
                    $payment->setAdditionalInformation('acceptjs_value', null);

                    if ($payment->getId() > 0) {
                        if ($payment instanceof OrderPaymentInterface) {
                            $this->orderPaymentRepository->save($payment);
                        } elseif ($payment instanceof PaymentInterface
                            && $payment->getQuote() instanceof CartInterface
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
