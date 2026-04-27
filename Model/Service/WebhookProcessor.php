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

namespace ParadoxLabs\Authnetcim\Model\Service;

use ParadoxLabs\Authnetcim\Model\Method;
use Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\Collection;
use Magento\Sales\Model\Order\Payment;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\InvoiceManagementInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use ParadoxLabs\Authnetcim\Model\ConfigProvider;
use ParadoxLabs\TokenBase\Api\GatewayInterface;
use ParadoxLabs\TokenBase\Helper\Data;
use ParadoxLabs\TokenBase\Model\Gateway\Response;
use ParadoxLabs\TokenBase\Model\Method\Factory;
use Throwable;

class WebhookProcessor
{
    const TRANSACTION_EVENTS = [
        'net.authorize.payment.fraud.approved',
        'net.authorize.payment.fraud.declined',
        'net.authorize.payment.priorAuthCapture.created',
        'net.authorize.payment.refund.created',
        'net.authorize.payment.void.created',
    ];
    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\CollectionFactory
     */
    protected $txnCollectionFactory;

    /**
     * WebhookProcessor constructor.
     *
     * @param Data $helper
     * @param RequestInterface $request
     * @param ConfigProvider $configProvider
     * @param \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param InvoiceManagementInterface $invoiceService
     * @param InvoiceRepositoryInterface $invoiceRepository
     * @param CreditmemoRepositoryInterface $creditmemoRepository
     * @param \Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\CollectionFactory $txnCollectionFactory
     * @param CreditmemoFactory $creditmemoFactory
     * @param CreditmemoManagementInterface $creditmemoService
     * @param Factory $methodFactory
     * @param StoreManagerInterface $storeManager
     * @param \ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider $achConfigProvider
     * @param ManagerInterface $eventManager
     */
    public function __construct(
        protected readonly Data $helper,
        protected readonly RequestInterface $request,
        protected ConfigProvider $configProvider,
        protected readonly \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory,
        protected readonly OrderRepositoryInterface $orderRepository,
        protected readonly InvoiceManagementInterface $invoiceService,
        protected readonly InvoiceRepositoryInterface $invoiceRepository,
        protected readonly CreditmemoRepositoryInterface $creditmemoRepository,
        CollectionFactory $txnCollectionFactory,
        protected readonly CreditmemoFactory $creditmemoFactory,
        protected readonly CreditmemoManagementInterface $creditmemoService,
        protected readonly Factory $methodFactory,
        protected readonly StoreManagerInterface $storeManager,
        protected readonly \ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider $achConfigProvider,
        protected readonly ManagerInterface $eventManager
    ) {
        $this->txnCollectionFactory   = $txnCollectionFactory;
    }

    /**
     * Process webhook input for the current request.
     *
     * @return void
     * @throws LocalizedException
     */
    public function process(): void
    {
        if ((bool)$this->request->getParam('ach', false) === true) {
            $this->configProvider = $this->achConfigProvider;
        }

        if ($this->configProvider->isWebhookEnabled() !== true) {
            $this->helper->log($this->configProvider->getCode(), 'Webhook received, but disabled in config.');

            return;
        }

        // Note: This requires the entry URL to be for the proper website for the txn, in order to pick up the right
        // config for validation. The setup process should ensure that though.
        $this->validateWebhook();

        try {
            /** @var Method $method */
            $method = $this->methodFactory->getMethodInstance($this->configProvider->getCode());
            $method->setStore($this->storeManager->getStore()->getId());
            $gateway = $method->gateway();
            $this->executeWebhook($gateway);
        } catch (Throwable $exception) {
            $this->helper->log(
                $this->configProvider->getCode(),
                'Webhook failed to execute: ' . $exception->getMessage()
            );

            throw $exception;
        }
    }

    /**
     * Validate the webhook signature against configuration.
     *
     * @return void
     * @throws LocalizedException
     */
    protected function validateWebhook(): void
    {
        $signatureHeader = $this->request->getServer('X-ANET-SIGNATURE')
            ?? $this->request->getServer('HTTP_X_ANET_SIGNATURE')
            ?? '=';
        $deliveredHmac   = strtoupper(explode('=', (string) $signatureHeader, 2)[1]);

        $payload = $this->request->getContent();

        if (empty($payload)) {
            $this->helper->log($this->configProvider->getCode(), 'Empty webhook request received');
            throw new InputException(__('No webhook received'));
        }

        $generatedHmac = strtoupper(hash_hmac('sha512', $payload, $this->configProvider->getSignatureKey()));

        if ($signatureHeader === '=' || $deliveredHmac !== $generatedHmac) {
            $this->helper->log($this->configProvider->getCode(), 'Webhook signature failed: ' . $payload);
            $this->helper->log($this->configProvider->getCode(), 'Webhook signature failed: ' . $payload, true);
            $this->helper->log($this->configProvider->getCode(), 'Delivered: ' . $deliveredHmac, true);
            $this->helper->log($this->configProvider->getCode(), 'Generated: ' . $generatedHmac, true);
            $this->helper->log($this->configProvider->getCode(), json_encode($this->request->getServer()), true);
            throw new LocalizedException(__('Invalid webhook signature'));
        } else {
            $this->helper->log($this->configProvider->getCode(), 'Received valid webhook: ' . $payload);
        }
    }

    /**
     * Fetch order by Authnet transaction ID
     *
     * @param string $transactionId
     * @return OrderInterface
     */
    protected function getOrderByTxnId(string $transactionId): OrderInterface
    {
        $orders = $this->orderCollectionFactory->create();
        $orders->join(
            [
                'spt' => $orders->getTable('sales_payment_transaction'),
            ],
            'spt.order_id=main_table.entity_id',
            ''
        );
        $orders->getSelect()->joinInner(
            [
                'sop' => $orders->getTable('sales_order_payment'),
            ],
            'sop.parent_id=main_table.entity_id',
            ''
        );
        $orders->getSelect()->joinLeft(
            [
                'si' => $orders->getTable('sales_invoice'),
            ],
            'si.order_id=main_table.entity_id and si.transaction_id=spt.txn_id',
            [
                'invoice_id' => 'si.entity_id',
            ]
        );
        $orders->addFieldToFilter('spt.txn_id', $transactionId);
        $orders->addFieldToFilter('sop.method', $this->configProvider->getCode());
        $orders->setPageSize(1);

        /** @var Order $order */
        $order = $orders->getFirstItem();

        return $order;
    }

    /**
     * Get auth transaction object for the given order/txn.
     *
     * @param OrderInterface $order
     * @param string $transactionId
     * @param string $type
     * @return TransactionInterface
     */
    protected function getTransaction(OrderInterface $order, string $transactionId, string $type): TransactionInterface
    {
        /** @var Collection $transactions */
        $transactions = $this->txnCollectionFactory->create();
        $transactions->addFieldToFilter('order_id', $order->getId());
        $transactions->addFieldToFilter('txn_id', $transactionId);
        $transactions->addFieldToFilter('txn_type', $type);
        $transactions->setOrder('is_closed', 'asc');

        /** @var TransactionInterface $transaction */
        $transaction = $transactions->getFirstItem();

        return $transaction;
    }

    /**
     * Process data changes from the webhook
     *
     * @param GatewayInterface $gateway
     * @return void
     * @throws \Exception
     */
    protected function executeWebhook(GatewayInterface $gateway): void
    {
        $webhook       = json_decode((string)$this->request->getContent(), true);
        $transactionId = $webhook['payload']['id'];
        $responseCode  = (int)$webhook['payload']['responseCode'];
        $eventType     = $webhook['eventType'];

        if (!empty($transactionId) && in_array($eventType, self::TRANSACTION_EVENTS, true)) {
            $gateway->setParameter('transId', $transactionId);
            $txnDetails = $gateway->getTransactionDetailsObject();

            if ($eventType === 'net.authorize.payment.refund.created') {
                $transactionId = $txnDetails->getData('reference_transaction_id');
            }

            $order = $this->getOrderByTxnId($transactionId);
            if ($order instanceof Order === false || empty($order->getId())) {
                return;
            }

            if ($eventType === 'net.authorize.payment.fraud.approved' && $order->isFraudDetected()
                && $responseCode === 1) {
                $this->markApproved($order, $txnDetails);
            } elseif (($eventType === 'net.authorize.payment.fraud.declined' || in_array($responseCode, [2, 3], true))
                && $order->isFraudDetected()) {
                $this->markDeclined($order, $txnDetails);
            } elseif ($eventType === 'net.authorize.payment.priorAuthCapture.created' && $order->canInvoice()) {
                $this->markCaptured($order, $txnDetails);
            } elseif ($eventType === 'net.authorize.payment.refund.created' && $order->canCreditmemo()) {
                $this->markRefunded($order, $txnDetails);
            }

            $this->helper->log($this->configProvider->getCode(), json_encode($txnDetails->getData()));
        }
    }

    /**
     * Mark the given order/transaction approved
     *
     * @param OrderInterface $order
     * @param Response $txnDetails
     * @return void
     */
    protected function markApproved(OrderInterface $order, Response $txnDetails): void
    {
        /** @var Payment $payment */
        $payment = $order->getPayment();
        $payment->setData('parent_transaction_id', $txnDetails->getTransactionId());
        $payment->setTransactionId($txnDetails->getTransactionId());

        $transaction = $payment->getAuthorizationTransaction();
        $transaction->setAdditionalInformation('is_transaction_fraud', false);

        $payment->setIsTransactionApproved(true);
        $payment->update(false);

        if ($txnDetails->getTransactionType() === 'auth_capture'
            || $txnDetails->getData('amount_settled') > 0) {
            $this->markCaptured($order, $txnDetails);

            return;
        }

        $order->addCommentToStatusHistory(
            __(
                'Transaction "%1" approved via Authorize.net webhook.',
                $txnDetails->getTransactionId()
            ),
            true
        );

        if ($txnDetails->getTransactionType() === 'auth_only') {
            $this->helper->log(
                $this->configProvider->getCode(),
                sprintf('Marking order %s authorized', $order->getIncrementId())
            );
        }

        $this->orderRepository->save($order);
    }

    /**
     * Mark the given order/transaction declined
     *
     * @param OrderInterface $order
     * @param Response $txnDetails
     * @return void
     */
    protected function markDeclined(OrderInterface $order, Response $txnDetails): void
    {
        $this->helper->log(
            $this->configProvider->getCode(),
            sprintf('Marking order %s failed', $order->getIncrementId())
        );

        /** @var Payment $payment */
        $payment = $order->getPayment();
        $payment->setData('parent_transaction_id', $txnDetails->getTransactionId());
        $payment->setIsTransactionDenied(true);
        $payment->getAuthorizationTransaction()->closeAuthorization();
        $payment->update(false);

        $order->cancel();

        $order->addCommentToStatusHistory(
            __(
                'Transaction "%1" declined via Authorize.net webhook.',
                $txnDetails->getTransactionId()
            ),
            true
        );
        $this->orderRepository->save($order);
    }

    /**
     * Mark the given order/transaction captured
     *
     * @param OrderInterface $order
     * @param Response $txnDetails
     * @return void
     * @throws LocalizedException
     */
    protected function markCaptured(OrderInterface $order, Response $txnDetails): void
    {
        /** @var Order $order */
        // Note: Invoices don't have a way to specify an amount independent of items/totals calculation. Could
        // theoretically calculate it ourselves, but not flawlessly. So: Full captures only.
        $uncoveredAmount = (float)$order->getTotalDue() - (float)$txnDetails->getData('amount_settled');
        if ($uncoveredAmount > 0.001) {
            $this->helper->log(
                $this->configProvider->getCode(),
                sprintf(
                    'Order %s value is %0.2f; only %0.2f was captured. Unable to mark invoiced.',
                    $order->getIncrementId(),
                    $order->getGrandTotal(),
                    $txnDetails->getData('amount_settled')
                )
            );

            $order->addCommentToStatusHistory(
                __(
                    'Authorize.net webhook reports $%1 was captured in transaction ID %2, but the total due is $%3. The'
                    . ' order must be invoiced manually to reconcile records.',
                    $txnDetails->getData('amount_settled'),
                    $txnDetails->getTransactionId(),
                    $order->getTotalDue()
                )
            );
            $this->orderRepository->save($order);

            return;
        }

        $authTxn = $this->getTransaction(
            $order,
            $txnDetails->getTransactionId(),
            TransactionInterface::TYPE_AUTH
        );
        if ($authTxn->getTxnId() && $authTxn->getIsClosed()) {
            // Transaction is already captured in Magento; disregard this push.
            return;
        }

        $this->helper->log(
            $this->configProvider->getCode(),
            sprintf('Marking order %s paid', $order->getIncrementId())
        );

        /** @var Payment $payment */
        $payment = $order->getPayment();
        $payment->setTransactionId($txnDetails->getTransactionId());
        $payment->setLastTransId($txnDetails->getTransactionId());
        $payment->setShouldCloseParentTransaction(true);

        $payment->setAdditionalInformation(
            array_replace_recursive($payment->getAdditionalInformation() ?? [], $txnDetails->getData())
        );
        $payment->setTransactionAdditionalInfo(
            Transaction::RAW_DETAILS,
            $txnDetails->getData()
        );
        $payment->setData(
            'base_amount_paid_online',
            $payment->getBaseAmountPaidOnline() + $txnDetails->getData('amount_settled')
        );

        $payment->addTransaction(
            Transaction::TYPE_CAPTURE,
            $order,
            false
        );

        $order->addCommentToStatusHistory(
            __(
                '$%1 captured via Authorize.net webhook. Transaction ID: "%2"',
                $txnDetails->getData('amount_settled'),
                $txnDetails->getTransactionId()
            ),
            true
        );

        $this->orderRepository->save($order);
    }

    /**
     * Mark the given order/transaction refunded
     *
     * @param OrderInterface $order
     * @param Response $txnDetails
     * @return void
     */
    protected function markRefunded(OrderInterface $order, Response $txnDetails): void
    {
        /** @var Order $order */
        $invoice    = null;
        $amountPaid = $order->getTotalPaid();

        if ($order->getData('invoice_id') !== null) {
            $invoice    = $this->invoiceRepository->get($order->getData('invoice_id'));
            $amountPaid = $invoice->getGrandTotal();
        }

        // NB: Refunds don't have a way to specify an amount independent of items/totals calculation. Could
        // theoretically calculate it ourselves, but not flawlessly. So: Full refunds only.
        $uncoveredAmount = (float)$amountPaid - (float)$txnDetails->getData('amount');
        if ($uncoveredAmount > 0.001) {
            $this->helper->log(
                $this->configProvider->getCode(),
                sprintf(
                    '%0.2f is paid on the order or invoice; only %0.2f was refunded. Unable to mark refunded.',
                    $amountPaid,
                    $txnDetails->getData('amount')
                )
            );

            $order->addCommentToStatusHistory(
                __(
                    'Authorize.net webhook reports $%1 was refunded in transaction ID "%2", but the amount paid is $%3.'
                    . ' The order must be refunded manually to reconcile records.',
                    $txnDetails->getData('amount'),
                    $txnDetails->getTransactionId(),
                    $amountPaid
                )
            );
            $this->orderRepository->save($order);

            return;
        }

        $capture = $this->getTransaction(
            $order,
            $txnDetails->getData('reference_transaction_id'),
            TransactionInterface::TYPE_CAPTURE
        );
        if ($capture->getTxnId() && $capture->getIsClosed()) {
            // Transaction is already refunded in Magento; disregard this push.
            return;
        }

        $this->helper->log(
            $this->configProvider->getCode(),
            sprintf('Marking order %s refunded', $order->getIncrementId())
        );

        $order->addCommentToStatusHistory(
            __(
                '$%1 refunded via Authorize.net webhook. Transaction ID: "%2"',
                $txnDetails->getData('amount'),
                $txnDetails->getTransactionId()
            ),
            true
        );

        if ($invoice instanceof Invoice) {
            $creditmemo = $this->creditmemoFactory->createByInvoice($invoice);
        } else {
            $creditmemo = $this->creditmemoFactory->createByOrder($order);
        }

        if ($creditmemo->getGrandTotal() > 0) {
            $this->creditmemoService->refund($creditmemo, true);
        }
    }
}
