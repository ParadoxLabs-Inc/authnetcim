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
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Payment\Gateway\Data\PaymentDataObjectFactoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use ParadoxLabs\Authnetcim\Gateway\Wallet\Command\VoidCommand;
use ParadoxLabs\Authnetcim\Model\Wallet\WalletType;
use ParadoxLabs\TokenBase\Helper\Data as Helper;
use Throwable;

/**
 * Void the wallet transaction when checkout fails after the payment went through.
 *
 * If anything throws after Payment::place() (an order save error, a sales_order_place_after observer, inventory), the
 * authorization or unsettled capture stays open at Authorize.net for an order that doesn't exist, and a retry puts a
 * second hold on the card. TokenBase does this for its own methods only.
 */
class CheckoutFailureVoidObserver implements ObserverInterface
{
    /**
     * Order states that mean the order did not go through, even if it was saved.
     */
    private const FAILED_ORDER_STATES = [
        Order::STATE_CANCELED,
        Order::STATE_CLOSED,
    ];

    /**
     * @param VoidCommand $voidCommand
     * @param PaymentDataObjectFactoryInterface $paymentDataObjectFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param Helper $helper
     */
    public function __construct(
        private readonly VoidCommand $voidCommand,
        private readonly PaymentDataObjectFactoryInterface $paymentDataObjectFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Helper $helper,
    ) {
    }

    /**
     * If checkout failed, void any wallet transaction that went through.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $order   = $observer->getEvent()->getData('order');
        $payment = $order instanceof Order ? $order->getPayment() : null;

        if (!$payment instanceof Payment
            || $payment->getMethod() !== WalletType::METHOD_CODE
            || empty($payment->getLastTransId())) {
            return;
        }

        $transactionId = (string)$payment->getLastTransId();

        try {
            if ($this->isOrderSaved($order)) {
                return;
            }

            // The 'void' command (not 'cancel') throws when the gateway rejects the void, so the outcome is known.
            $this->voidCommand->execute(
                [
                    'payment' => $this->paymentDataObjectFactory->create($payment),
                ]
            );

            $this->log(
                sprintf(
                    'Auto-voided transaction %s for order %s because checkout failed.',
                    $transactionId,
                    $order->getIncrementId()
                )
            );
        } catch (Throwable $exception) {
            // Log and ignore; this runs while Magento handles the original checkout exception.
            $this->log(
                sprintf(
                    'Checkout failed, and voiding transaction %s for order %s failed: %s',
                    $transactionId,
                    $order->getIncrementId(),
                    $exception->getMessage()
                )
            );
        }
    }

    /**
     * Was the order saved before checkout failed? If so, the transaction is the order's and must stay open.
     *
     * That happens when something after the order save throws, e.g. a submit-success observer or the cart save.
     *
     * @param Order $order
     * @return bool
     */
    private function isOrderSaved(Order $order): bool
    {
        if ((int)$order->getId() <= 0
            || in_array($order->getState(), self::FAILED_ORDER_STATES, true)) {
            return false;
        }

        // A failed order save rolls back the insert but leaves the new ID on the order, so confirm the order exists.
        try {
            $this->orderRepository->get((int)$order->getId());
        } catch (NoSuchEntityException) {
            return false;
        }

        return true;
    }

    /**
     * Write to the wallet log.
     *
     * @param string $message
     * @return void
     */
    private function log(string $message): void
    {
        $this->helper->log(WalletType::METHOD_CODE, $message);
    }
}
