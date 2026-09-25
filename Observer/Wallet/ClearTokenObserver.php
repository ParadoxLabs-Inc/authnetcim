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
 *
 * @link https://support.paradoxlabs.com
 */

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Observer\Wallet;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Payment\Model\InfoInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use ParadoxLabs\Authnetcim\Model\Wallet\PaymentResponseHandler;
use ParadoxLabs\Authnetcim\Model\Wallet\WalletType;
use Throwable;

/**
 * Remove the single-use wallet token from quote/order payments after checkout succeeds or fails.
 *
 * On success, the quote is saved right after this event, so unsetting is enough. On failure nothing saves the
 * quote afterward, so save it here.
 */
class ClearTokenObserver implements ObserverInterface
{
    /**
     * @param PaymentResponseHandler $responseHandler
     * @param CartRepositoryInterface $quoteRepository
     * @param bool $saveQuote
     */
    public function __construct(
        private readonly PaymentResponseHandler $responseHandler,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly bool $saveQuote = false,
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        try {
            $this->clear($observer->getEvent()->getData('order'));

            $quote = $observer->getEvent()->getData('quote');
            if ($this->clear($quote) && $this->saveQuote === true && $quote instanceof Quote) {
                $this->quoteRepository->save($quote);
            }
        } catch (Throwable) {
            // Never interrupt checkout from here.
        }
    }

    /**
     * Clear the token from the given quote/order's payment. Returns whether anything was removed.
     *
     * @param mixed $object
     * @return bool
     */
    private function clear(mixed $object): bool
    {
        if (!$object instanceof Quote && !$object instanceof Order) {
            return false;
        }

        $payment = $object->getPayment();
        if (!$payment instanceof InfoInterface
            || $payment->getMethod() !== WalletType::METHOD_CODE
            || !$payment->hasAdditionalInformation('wallet_token')) {
            return false;
        }

        $this->responseHandler->clearTransientData($payment);

        return true;
    }
}
