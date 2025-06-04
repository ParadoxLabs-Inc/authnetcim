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

namespace ParadoxLabs\Authnetcim\Model\Service;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\TransactionInterface;
use ParadoxLabs\Authnetcim\Model\ConfigProvider;
use ParadoxLabs\TokenBase\Model\Gateway\Response;

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
     * @var \ParadoxLabs\TokenBase\Helper\Data
     */
    protected $helper;
    /**
     * @var \Magento\Framework\App\Request\Http
     */
    protected $request;
    /**
     * @var \ParadoxLabs\Authnetcim\Model\ConfigProvider
     */
    protected $configProvider;
    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\CollectionFactory
     */
    protected $orderCollectionFactory;
    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $orderRepository;
    /**
     * @var \Magento\Sales\Model\Service\InvoiceService
     */
    protected $invoiceService;
    /**
     * @var \Magento\Sales\Api\InvoiceRepositoryInterface
     */
    protected $invoiceRepository;
    /**
     * @var \Magento\Sales\Api\CreditmemoRepositoryInterface
     */
    protected $creditmemoRepository;
    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\CollectionFactory
     */
    protected $txnCollectionFactory;
    /**
     * @var \Magento\Sales\Api\CreditmemoManagementInterface
     */
    protected $creditmemoService;
    /**
     * @var \Magento\Sales\Model\Order\CreditmemoFactory
     */
    protected $creditmemoFactory;
    /**
     * @var \ParadoxLabs\TokenBase\Model\Method\Factory
     */
    protected $methodFactory;
    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;
    /**
     * @var \ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider
     */
    protected $achConfigProvider;

    protected \Magento\Framework\Event\ManagerInterface $eventManager;

    /**
     * WebhookProcessor constructor.
     *
     * @param \ParadoxLabs\TokenBase\Helper\Data $helper
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \ParadoxLabs\Authnetcim\Model\ConfigProvider $configProvider
     * @param \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Magento\Sales\Api\InvoiceManagementInterface $invoiceService
     * @param \Magento\Sales\Api\InvoiceRepositoryInterface $invoiceRepository
     * @param \Magento\Sales\Api\CreditmemoRepositoryInterface $creditmemoRepository
     * @param \Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\CollectionFactory $txnCollectionFactory
     * @param \Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory
     * @param \Magento\Sales\Api\CreditmemoManagementInterface $creditmemoService
     * @param \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider $achConfigProvider
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     */
    public function __construct(
        \ParadoxLabs\TokenBase\Helper\Data $helper,
        \Magento\Framework\App\RequestInterface $request,
        ConfigProvider $configProvider,
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Sales\Api\InvoiceManagementInterface $invoiceService,
        \Magento\Sales\Api\InvoiceRepositoryInterface $invoiceRepository,
        \Magento\Sales\Api\CreditmemoRepositoryInterface $creditmemoRepository,
        \Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\CollectionFactory $txnCollectionFactory,
        \Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory,
        \Magento\Sales\Api\CreditmemoManagementInterface $creditmemoService,
        \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider $achConfigProvider,
        \Magento\Framework\Event\ManagerInterface $eventManager
    ) {
        $this->helper = $helper;
        $this->request = $request;
        $this->configProvider = $configProvider;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->orderRepository = $orderRepository;
        $this->invoiceService = $invoiceService;
        $this->invoiceRepository = $invoiceRepository;
        $this->creditmemoRepository = $creditmemoRepository;
        $this->txnCollectionFactory = $txnCollectionFactory;
        $this->creditmemoService = $creditmemoService;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->methodFactory = $methodFactory;
        $this->storeManager = $storeManager;
        $this->achConfigProvider = $achConfigProvider;
        $this->eventManager = $eventManager;
    }

    /**
     * Process webhook input for the current request.
     *
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
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
            /** @var \ParadoxLabs\Authnetcim\Model\Method $method */
            $method = $this->methodFactory->getMethodInstance($this->configProvider->getCode());
            $method->setStore($this->storeManager->getStore()->getId());
            $gateway = $method->gateway();
            $this->executeWebhook($gateway);
        } catch (\Exception $exception) {
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
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function validateWebhook(): void
    {
        $signatureHeader = $this->request->getServer('X-ANET-SIGNATURE')
            ?? $this->request->getServer('HTTP_X_ANET_SIGNATURE')
            ?? '=';
        $deliveredHmac   = strtoupper(explode('=', $signatureHeader, 2)[1]);

        $payload         = $this->request->getContent();

        if (empty($payload)) {
            $this->helper->log($this->configProvider->getCode(), 'Empty webhook request received');
            throw new \Magento\Framework\Exception\InputException(__('No webhook received'));
        }

        $generatedHmac   = strtoupper(hash_hmac('sha512', $payload, $this->configProvider->getSignatureKey()));

        if ($signatureHeader === '=' || $deliveredHmac !== $generatedHmac) {
            $this->helper->log($this->configProvider->getCode(), 'Webhook signature failed: '.$payload);
            $this->helper->log($this->configProvider->getCode(), 'Webhook signature failed: '.$payload, true);
            $this->helper->log($this->configProvider->getCode(), 'Delivered: '.$deliveredHmac, true);
            $this->helper->log($this->configProvider->getCode(), 'Generated: '.$generatedHmac, true);
            $this->helper->log($this->configProvider->getCode(), json_encode($this->request->getServer()), true);
            throw new \Magento\Framework\Exception\LocalizedException(__('Invalid webhook signature'));
        } else {
            $this->helper->log($this->configProvider->getCode(), 'Received valid webhook: '.$payload);
        }
    }

    /**
     * Fetch order by Authnet transaction ID
     *
     * @param string $transactionId
     * @return \Magento\Sales\Api\Data\OrderInterface
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

        /** @var \Magento\Sales\Model\Order $order */
        $order = $orders->getFirstItem();
        return $order;
    }

    /**
     * Get auth transaction object for the given order/txn.
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param string $transactionId
     * @param string $type
     * @return \Magento\Sales\Api\Data\TransactionInterface
     */
    protected function getTransaction(OrderInterface $order, string $transactionId, string $type): TransactionInterface
    {
        /** @var \Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\Collection $transactions */
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
     * @param \ParadoxLabs\TokenBase\Api\GatewayInterface $gateway
     * @return void
     * @throws \Exception
     */
    protected function executeWebhook(\ParadoxLabs\TokenBase\Api\GatewayInterface $gateway): void
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
            if ($order instanceof \Magento\Sales\Model\Order === false || empty($order->getId())) {
                return;
            }

            if ($eventType === 'net.authorize.payment.fraud.approved' && $order->isFraudDetected()
                && $responseCode === 1) {
                $this->markApproved($order, $txnDetails);
            } elseif ($order->isFraudDetected() && in_array($responseCode, [2,3], true)) {
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
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param \ParadoxLabs\TokenBase\Model\Gateway\Response $txnDetails
     * @return void
     */
    protected function markApproved(OrderInterface $order, Response $txnDetails): void
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = $order->getPayment();
        $payment->setData('parent_transaction_id', $txnDetails->getTransactionId());

        $transaction = $payment->getAuthorizationTransaction();
        $transaction->setAdditionalInformation('is_transaction_fraud', false);

        $payment->setIsTransactionApproved(true);
        $payment->update(false);

        $order->addCommentToStatusHistory(
            __(
                'Transaction "%1" approved via Authorize.net webhook.',
                $txnDetails->getTransactionId()
            ),
            true
        );

        $this->orderRepository->save($order);

        if ($txnDetails['transaction_type'] === 'auth_only') {
            $this->helper->log(
                $this->configProvider->getCode(),
                sprintf('Marking order %s authorized', $order->getIncrementId())
            );
        } else {
            $this->helper->log(
                $this->configProvider->getCode(),
                sprintf('Marking order %s paid', $order->getIncrementId())
            );
        }
    }

    /**
     * Mark the given order/transaction declined
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param \ParadoxLabs\TokenBase\Model\Gateway\Response $txnDetails
     * @return void
     */
    protected function markDeclined(OrderInterface $order, Response $txnDetails): void
    {
        $this->helper->log(
            $this->configProvider->getCode(),
            sprintf('Marking order %s failed', $order->getIncrementId())
        );

        /** @var \Magento\Sales\Model\Order\Payment $payment */
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
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param \ParadoxLabs\TokenBase\Model\Gateway\Response $txnDetails
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function markCaptured(OrderInterface $order, Response $txnDetails): void
    {
        /** @var \Magento\Sales\Model\Order $order */

        // Note: Invoices don't have a way to specify an amount independent of items/totals calculation. Could
        // theoretically calculate it ourselves, but not flawlessly. So: Full captures only.
        if ($txnDetails->getData('amount_settled') < $order->getTotalDue()) {
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

        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = $order->getPayment();

        $invoice = $this->invoiceService->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
        $invoice->register();
        $invoice->setTransactionId($txnDetails->getTransactionId());
        $invoice->setIsPaid(true);

        $payment->setTransactionId($txnDetails->getTransactionId());
        $payment->setLastTransId($txnDetails->getTransactionId());
        $payment->setShouldCloseParentTransaction(true);

        $this->eventManager->dispatch(
            'sales_order_payment_capture',
            ['payment' => $payment, 'invoice' => $invoice]
        );

        $payment->setAdditionalInformation(
            array_replace_recursive($payment->getAdditionalInformation() ?? [], $txnDetails->getData())
        );
        $payment->setTransactionAdditionalInfo(
            \Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS,
            $txnDetails->getData()
        );
        $payment->setData(
            'base_amount_paid_online',
            $payment->getBaseAmountPaidOnline() + $txnDetails->getData('amount_settled')
        );

        $payment->addTransaction(
            \Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE,
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

        $this->invoiceRepository->save($invoice);
        $this->orderRepository->save($order);
    }

    /**
     * Mark the given order/transaction refunded
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param \ParadoxLabs\TokenBase\Model\Gateway\Response $txnDetails
     * @return void
     */
    protected function markRefunded(OrderInterface $order, Response $txnDetails): void
    {
        /** @var \Magento\Sales\Model\Order $order */

        $invoice    = null;
        $amountPaid = $order->getTotalPaid();

        if ($order->getData('invoice_id') !== null) {
            $invoice    = $this->invoiceRepository->get($order->getData('invoice_id'));
            $amountPaid = $invoice->getGrandTotal();
        }

        // NB: Refunds don't have a way to specify an amount independent of items/totals calculation. Could
        // theoretically calculate it ourselves, but not flawlessly. So: Full refunds only.
        if ($txnDetails->getData('amount') < $amountPaid) {
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
                    'Authorize.net webhook reports $%1 was refunded in transaction ID %2, but the amount paid is $%3.'
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

        if ($invoice instanceof \Magento\Sales\Model\Order\Invoice) {
            $creditmemo = $this->creditmemoFactory->createByInvoice($invoice);
        } else {
            $creditmemo = $this->creditmemoFactory->createByOrder($order);
        }

        if ($creditmemo->getGrandTotal() > 0) {
            $this->creditmemoService->refund($creditmemo, true);
        }
    }
}
