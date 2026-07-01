<?php

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Test\Unit\Model\Service;

use Magento\Framework\App\Request\Http;
use ParadoxLabs\Authnetcim\Model\Method;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\Collection;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Framework\DB\Select;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\InvoiceManagementInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\CollectionFactory as TxnCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider as AchConfigProvider;
use ParadoxLabs\Authnetcim\Model\ConfigProvider;
use ParadoxLabs\Authnetcim\Model\Gateway;
use ParadoxLabs\Authnetcim\Model\Service\WebhookProcessor;
use ParadoxLabs\TokenBase\Api\GatewayInterface;
use ParadoxLabs\TokenBase\Helper\Data;
use ParadoxLabs\TokenBase\Model\Gateway\Response;
use ParadoxLabs\TokenBase\Model\Method\Factory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WebhookProcessorTest extends TestCase
{
    private WebhookProcessor $processor;
    private Data|MockObject $helperMock;
    private RequestInterface|MockObject $requestMock;
    private ConfigProvider|MockObject $configProviderMock;
    private OrderCollectionFactory|MockObject $orderCollectionFactoryMock;
    private OrderRepositoryInterface|MockObject $orderRepositoryMock;
    private InvoiceManagementInterface|MockObject $invoiceServiceMock;
    private InvoiceRepositoryInterface|MockObject $invoiceRepositoryMock;
    private CreditmemoRepositoryInterface|MockObject $creditmemoRepositoryMock;
    private TxnCollectionFactory|MockObject $txnCollectionFactoryMock;
    private CreditmemoFactory|MockObject $creditmemoFactoryMock;
    private CreditmemoManagementInterface|MockObject $creditmemoServiceMock;
    private Factory|MockObject $methodFactoryMock;
    private StoreManagerInterface|MockObject $storeManagerMock;
    private AchConfigProvider|MockObject $achConfigProviderMock;
    private ManagerInterface|MockObject $eventManagerMock;

    protected function setUp(): void
    {
        $this->helperMock = $this->createMock(Data::class);
        $this->requestMock = $this->createMock(Http::class);
        $this->configProviderMock = $this->createMock(ConfigProvider::class);
        $this->orderCollectionFactoryMock = $this->createMock(OrderCollectionFactory::class);
        $this->orderRepositoryMock = $this->createMock(OrderRepositoryInterface::class);
        $this->invoiceServiceMock = $this->createMock(InvoiceManagementInterface::class);
        $this->invoiceRepositoryMock = $this->createMock(InvoiceRepositoryInterface::class);
        $this->creditmemoRepositoryMock = $this->createMock(CreditmemoRepositoryInterface::class);
        $this->txnCollectionFactoryMock = $this->createMock(TxnCollectionFactory::class);
        $this->creditmemoFactoryMock = $this->createMock(CreditmemoFactory::class);
        $this->creditmemoServiceMock = $this->createMock(CreditmemoManagementInterface::class);
        $this->methodFactoryMock = $this->createMock(Factory::class);
        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->achConfigProviderMock = $this->createMock(AchConfigProvider::class);
        $this->eventManagerMock = $this->createMock(ManagerInterface::class);

        $this->processor = new WebhookProcessor(
            $this->helperMock,
            $this->requestMock,
            $this->configProviderMock,
            $this->orderCollectionFactoryMock,
            $this->orderRepositoryMock,
            $this->invoiceServiceMock,
            $this->invoiceRepositoryMock,
            $this->creditmemoRepositoryMock,
            $this->txnCollectionFactoryMock,
            $this->creditmemoFactoryMock,
            $this->creditmemoServiceMock,
            $this->methodFactoryMock,
            $this->storeManagerMock,
            $this->achConfigProviderMock,
            $this->eventManagerMock,
        );
    }

    public function testProcessReturnsEarlyWhenDisabled(): void
    {
        $this->requestMock->method('getParam')
            ->with('ach', false)
            ->willReturn(false);

        $this->configProviderMock->expects($this->once())
            ->method('isWebhookEnabled')
            ->willReturn(false);

        $this->configProviderMock->method('getCode')
            ->willReturn('authnetcim');

        $this->helperMock->expects($this->once())
            ->method('log')
            ->with('authnetcim', 'Webhook received, but disabled in config.');

        $this->processor->process();
    }

    public function testProcessSwitchesToAchConfigProvider(): void
    {
        $this->requestMock->method('getParam')
            ->with('ach', false)
            ->willReturn(true);

        $this->achConfigProviderMock->expects($this->once())
            ->method('isWebhookEnabled')
            ->willReturn(false);

        $this->achConfigProviderMock->method('getCode')
            ->willReturn('authnetcim_ach');

        $this->helperMock->expects($this->once())
            ->method('log')
            ->with('authnetcim_ach', 'Webhook received, but disabled in config.');

        $this->processor->process();
    }

    public function testValidateWebhookThrowsOnEmptyPayload(): void
    {
        $this->requestMock->method('getParam')
            ->willReturn(false);

        $this->configProviderMock->method('isWebhookEnabled')
            ->willReturn(true);

        $this->configProviderMock->method('getCode')
            ->willReturn('authnetcim');

        $this->requestMock->method('getServer')
            ->willReturn('sha512=ABCDEF');

        $this->requestMock->method('getContent')
            ->willReturn('');

        $this->expectException(InputException::class);
        $this->expectExceptionMessage('No webhook received');

        $this->processor->process();
    }

    public function testValidateWebhookThrowsOnInvalidSignature(): void
    {
        $this->requestMock->method('getParam')
            ->willReturn(false);

        $this->configProviderMock->method('isWebhookEnabled')
            ->willReturn(true);

        $this->configProviderMock->method('getCode')
            ->willReturn('authnetcim');

        $this->configProviderMock->method('getSignatureKey')
            ->willReturn('test-signature-key');

        $payload = '{"eventType":"net.authorize.payment.fraud.approved","payload":{"id":"123"}}';

        $this->requestMock->method('getServer')
            ->willReturnCallback(function ($key = null) {
                if ($key === 'X-ANET-SIGNATURE') {
                    return 'sha512=INVALIDSIGNATURE';
                }

                return [];
            });

        $this->requestMock->method('getContent')
            ->willReturn($payload);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid webhook signature');

        $this->processor->process();
    }

    public function testValidateWebhookPassesValidSignature(): void
    {
        $this->requestMock->method('getParam')
            ->willReturn(false);

        $this->configProviderMock->method('isWebhookEnabled')
            ->willReturn(true);

        $this->configProviderMock->method('getCode')
            ->willReturn('authnetcim');

        $signatureKey = 'test-signature-key';
        $this->configProviderMock->method('getSignatureKey')
            ->willReturn($signatureKey);

        $payload = '{"eventType":"net.authorize.payment.other","payload":{"id":"123","responseCode":1}}';
        $validSignature = strtoupper(hash_hmac('sha512', $payload, $signatureKey));

        $this->requestMock->method('getServer')
            ->willReturnCallback(function ($key = null) use ($validSignature) {
                if ($key === 'X-ANET-SIGNATURE') {
                    return 'sha512=' . $validSignature;
                }

                return [];
            });

        $this->requestMock->method('getContent')
            ->willReturn($payload);

        $methodMock = $this->createMock(Method::class);
        $gatewayMock = $this->createMock(Gateway::class);
        $storeMock = $this->createMock(StoreInterface::class);

        $this->methodFactoryMock->method('getMethodInstance')
            ->willReturn($methodMock);

        $methodMock->method('gateway')
            ->willReturn($gatewayMock);

        $this->storeManagerMock->method('getStore')
            ->willReturn($storeMock);

        $storeMock->method('getId')
            ->willReturn(1);

        // Should not throw exception - signature is valid
        $this->processor->process();

        $this->assertTrue(true);
    }

    public function testValidateWebhookUsesHeaderFallback(): void
    {
        $this->requestMock->method('getParam')
            ->willReturn(false);

        $this->configProviderMock->method('isWebhookEnabled')
            ->willReturn(true);

        $this->configProviderMock->method('getCode')
            ->willReturn('authnetcim');

        $signatureKey = 'test-signature-key';
        $this->configProviderMock->method('getSignatureKey')
            ->willReturn($signatureKey);

        $payload = '{"eventType":"net.authorize.payment.other","payload":{"id":"123","responseCode":1}}';
        $validSignature = strtoupper(hash_hmac('sha512', $payload, $signatureKey));

        $this->requestMock->method('getServer')
            ->willReturnCallback(function ($key = null) use ($validSignature) {
                if ($key === 'X-ANET-SIGNATURE') {
                    return null;
                }

                if ($key === 'HTTP_X_ANET_SIGNATURE') {
                    return 'sha512=' . $validSignature;
                }

                return [];
            });

        $this->requestMock->method('getContent')
            ->willReturn($payload);

        $methodMock = $this->createMock(Method::class);
        $gatewayMock = $this->createMock(Gateway::class);
        $storeMock = $this->createMock(StoreInterface::class);

        $this->methodFactoryMock->method('getMethodInstance')
            ->willReturn($methodMock);

        $methodMock->method('gateway')
            ->willReturn($gatewayMock);

        $this->storeManagerMock->method('getStore')
            ->willReturn($storeMock);

        $storeMock->method('getId')
            ->willReturn(1);

        // Should not throw - using fallback header
        $this->processor->process();

        $this->assertTrue(true);
    }

    public function testExecuteWebhookIgnoresNonTransactionEvents(): void
    {
        $payload = '{"eventType":"net.authorize.customer.created","payload":{"id":"123","responseCode":1}}';
        $this->setupValidWebhookRequest($payload);

        $methodMock = $this->createMock(Method::class);
        $gatewayMock = $this->createMock(Gateway::class);

        $this->methodFactoryMock->method('getMethodInstance')
            ->willReturn($methodMock);

        $methodMock->method('gateway')
            ->willReturn($gatewayMock);

        // Should not throw - event is ignored
        $this->processor->process();

        $this->assertTrue(true);
    }

    public function testExecuteWebhookRoutesFraudApproved(): void
    {
        $payload = json_encode([
            'eventType' => 'net.authorize.payment.fraud.approved',
            'payload' => ['id' => 'txn123', 'responseCode' => 1],
        ]);

        $this->setupValidWebhookRequest($payload);

        $gatewayMock = $this->setupGatewayAndOrder('txn123', true);

        $txnDetailsMock = $this->createMock(Response::class);
        $txnDetailsMock->method('getTransactionId')
            ->willReturn('txn123');
        $txnDetailsMock->method('getTransactionType')
            ->willReturn('auth_only');
        $txnDetailsMock->method('getData')
            ->willReturnCallback(function ($key = null) {
                if ($key === 'amount_settled') {
                    return 0; // Auth only, no capture yet
                }

                return [];
            });

        $gatewayMock->method('getTransactionDetailsObject')
            ->willReturn($txnDetailsMock);

        $this->orderRepositoryMock->expects($this->once())
            ->method('save');

        $this->processor->process();
    }

    public function testExecuteWebhookRoutesFraudDeclined(): void
    {
        $payload = json_encode([
            'eventType' => 'net.authorize.payment.fraud.declined',
            'payload' => ['id' => 'txn123', 'responseCode' => 2],
        ]);

        $this->setupValidWebhookRequest($payload);

        $gatewayMock = $this->setupGatewayAndOrder('txn123', true);

        $txnDetailsMock = $this->createMock(Response::class);
        $txnDetailsMock->method('getTransactionId')
            ->willReturn('txn123');
        $txnDetailsMock->method('getData')
            ->willReturn([]);

        $gatewayMock->method('getTransactionDetailsObject')
            ->willReturn($txnDetailsMock);

        $this->orderRepositoryMock->expects($this->once())
            ->method('save');

        $this->processor->process();
    }

    public function testExecuteWebhookRoutesPriorAuthCapture(): void
    {
        $payload = json_encode([
            'eventType' => 'net.authorize.payment.priorAuthCapture.created',
            'payload' => ['id' => 'txn123', 'responseCode' => 1],
        ]);

        $this->setupValidWebhookRequest($payload);

        $gatewayMock = $this->setupGatewayAndOrderForCapture('txn123');

        $txnDetailsMock = $this->createMock(Response::class);
        $txnDetailsMock->method('getTransactionId')
            ->willReturn('txn123');
        $txnDetailsMock->method('getData')
            ->willReturnCallback(function ($key = null) {
                if ($key === 'amount_settled') {
                    return 100.00;
                }

                return [];
            });

        $gatewayMock->method('getTransactionDetailsObject')
            ->willReturn($txnDetailsMock);

        $this->orderRepositoryMock->expects($this->once())
            ->method('save');

        $this->processor->process();
    }

    public function testExecuteWebhookRoutesRefund(): void
    {
        $payload = json_encode([
            'eventType' => 'net.authorize.payment.refund.created',
            'payload' => ['id' => 'refund123', 'responseCode' => 1],
        ]);

        $this->setupValidWebhookRequest($payload);

        $gatewayMock = $this->setupGatewayAndOrderForRefund('txn123');

        $txnDetailsMock = $this->createMock(Response::class);
        $txnDetailsMock->method('getTransactionId')
            ->willReturn('refund123');
        $txnDetailsMock->method('getData')
            ->willReturnCallback(function ($key = null) {
                if ($key === 'reference_transaction_id') {
                    return 'txn123';
                }

                if ($key === 'amount') {
                    return 100.00;
                }

                return [];
            });

        $gatewayMock->method('getTransactionDetailsObject')
            ->willReturn($txnDetailsMock);

        // creditmemoService->refund is called instead of orderRepository->save for full refunds
        $this->creditmemoServiceMock->expects($this->once())
            ->method('refund');

        $this->processor->process();
    }

    public function testMarkCapturedSkipsPartialCapture(): void
    {
        $payload = json_encode([
            'eventType' => 'net.authorize.payment.priorAuthCapture.created',
            'payload' => ['id' => 'txn123', 'responseCode' => 1],
        ]);

        $this->setupValidWebhookRequest($payload);

        $orderMock = $this->createMock(Order::class);
        $orderMock->method('getId')
            ->willReturn(1);
        $orderMock->method('getIncrementId')
            ->willReturn('100000001');
        $orderMock->method('canInvoice')
            ->willReturn(true);
        $orderMock->method('getTotalDue')
            ->willReturn(200.00); // Order is $200

        $orderMock->expects($this->once())
            ->method('addCommentToStatusHistory');

        $gatewayMock = $this->setupGatewayWithOrder($orderMock);

        $txnDetailsMock = $this->createMock(Response::class);
        $txnDetailsMock->method('getTransactionId')
            ->willReturn('txn123');
        $txnDetailsMock->method('getData')
            ->willReturnCallback(function ($key = null) {
                if ($key === 'amount_settled') {
                    return 100.00; // Only $100 captured
                }

                return [];
            });

        $gatewayMock->method('getTransactionDetailsObject')
            ->willReturn($txnDetailsMock);

        $this->orderRepositoryMock->expects($this->once())
            ->method('save');

        $this->processor->process();
    }

    public function testMarkCapturedSkipsClosedTransaction(): void
    {
        $payload = json_encode([
            'eventType' => 'net.authorize.payment.priorAuthCapture.created',
            'payload' => ['id' => 'txn123', 'responseCode' => 1],
        ]);

        $this->setupValidWebhookRequest($payload);

        $orderMock = $this->createMock(Order::class);
        $orderMock->method('getId')
            ->willReturn(1);
        $orderMock->method('getIncrementId')
            ->willReturn('100000001');
        $orderMock->method('canInvoice')
            ->willReturn(true);
        $orderMock->method('getTotalDue')
            ->willReturn(100.00);

        $gatewayMock = $this->setupGatewayWithOrder($orderMock);

        $txnDetailsMock = $this->createMock(Response::class);
        $txnDetailsMock->method('getTransactionId')
            ->willReturn('txn123');
        $txnDetailsMock->method('getData')
            ->willReturnCallback(function ($key = null) {
                if ($key === 'amount_settled') {
                    return 100.00;
                }

                return [];
            });

        $gatewayMock->method('getTransactionDetailsObject')
            ->willReturn($txnDetailsMock);

        // Setup closed transaction
        $txnCollectionMock = $this->createMock(Collection::class);
        $closedTxnMock = $this->createMock(Transaction::class);
        $closedTxnMock->method('getTxnId')
            ->willReturn('txn123');
        $closedTxnMock->method('getIsClosed')
            ->willReturn(true);

        $txnCollectionMock->method('addFieldToFilter')
            ->willReturnSelf();
        $txnCollectionMock->method('setOrder')
            ->willReturnSelf();
        $txnCollectionMock->method('getFirstItem')
            ->willReturn($closedTxnMock);

        $this->txnCollectionFactoryMock->method('create')
            ->willReturn($txnCollectionMock);

        // Should NOT save order when transaction is already closed
        $this->orderRepositoryMock->expects($this->never())
            ->method('save');

        $this->processor->process();
    }

    public function testMarkRefundedSkipsPartialRefund(): void
    {
        $payload = json_encode([
            'eventType' => 'net.authorize.payment.refund.created',
            'payload' => ['id' => 'refund123', 'responseCode' => 1],
        ]);

        $this->setupValidWebhookRequest($payload);

        $orderMock = $this->createMock(Order::class);
        $orderMock->method('getId')
            ->willReturn(1);
        $orderMock->method('getIncrementId')
            ->willReturn('100000001');
        $orderMock->method('canCreditmemo')
            ->willReturn(true);
        $orderMock->method('getTotalPaid')
            ->willReturn(200.00); // $200 paid
        $orderMock->method('getData')
            ->willReturnCallback(function ($key) {
                if ($key === 'invoice_id') {
                    return null;
                }

                return null;
            });

        $orderMock->expects($this->once())
            ->method('addCommentToStatusHistory');

        $gatewayMock = $this->setupGatewayWithOrder($orderMock);

        $txnDetailsMock = $this->createMock(Response::class);
        $txnDetailsMock->method('getTransactionId')
            ->willReturn('refund123');
        $txnDetailsMock->method('getData')
            ->willReturnCallback(function ($key = null) {
                if ($key === 'reference_transaction_id') {
                    return 'txn123';
                }

                if ($key === 'amount') {
                    return 100.00; // Only $100 refunded
                }

                return [];
            });

        $gatewayMock->method('getTransactionDetailsObject')
            ->willReturn($txnDetailsMock);

        $this->orderRepositoryMock->expects($this->once())
            ->method('save');

        $this->processor->process();
    }

    private function setupValidWebhookRequest(string $payload): void
    {
        $this->requestMock->method('getParam')
            ->willReturn(false);

        $this->configProviderMock->method('isWebhookEnabled')
            ->willReturn(true);

        $this->configProviderMock->method('getCode')
            ->willReturn('authnetcim');

        $signatureKey = 'test-signature-key';
        $this->configProviderMock->method('getSignatureKey')
            ->willReturn($signatureKey);

        $validSignature = strtoupper(hash_hmac('sha512', $payload, $signatureKey));

        $this->requestMock->method('getServer')
            ->willReturnCallback(function ($key = null) use ($validSignature) {
                if ($key === 'X-ANET-SIGNATURE') {
                    return 'sha512=' . $validSignature;
                }

                return [];
            });

        $this->requestMock->method('getContent')
            ->willReturn($payload);

        $storeMock = $this->createMock(StoreInterface::class);
        $storeMock->method('getId')
            ->willReturn(1);
        $this->storeManagerMock->method('getStore')
            ->willReturn($storeMock);
    }

    private function setupGatewayAndOrder(string $txnId, bool $isFraudDetected = false): GatewayInterface|MockObject
    {
        $orderMock = $this->createMock(Order::class);
        $orderMock->method('getId')
            ->willReturn(1);
        $orderMock->method('getIncrementId')
            ->willReturn('100000001');
        $orderMock->method('isFraudDetected')
            ->willReturn($isFraudDetected);
        $orderMock->method('canInvoice')
            ->willReturn(false);
        $orderMock->method('canCreditmemo')
            ->willReturn(false);
        $orderMock->method('addCommentToStatusHistory')
            ->willReturnSelf();

        $paymentMock = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'setData',
                'setTransactionId',
                'update',
                'getAuthorizationTransaction',
            ])
            ->getMock();

        // setIsTransactionApproved()/setIsTransactionDenied() are magic; they route through
        // the real __call into the stubbed setData(), which already returns self below.
        $paymentMock->method('setData')
            ->willReturnSelf();
        $paymentMock->method('setTransactionId')
            ->willReturnSelf();
        $paymentMock->method('update')
            ->willReturnSelf();

        $authTxnMock = $this->createMock(Transaction::class);
        $authTxnMock->method('setAdditionalInformation')
            ->willReturnSelf();
        $authTxnMock->method('closeAuthorization')
            ->willReturnSelf();

        $paymentMock->method('getAuthorizationTransaction')
            ->willReturn($authTxnMock);

        $orderMock->method('getPayment')
            ->willReturn($paymentMock);

        $orderMock->method('cancel')
            ->willReturnSelf();

        return $this->setupGatewayWithOrder($orderMock);
    }

    private function setupGatewayAndOrderForCapture(string $txnId): GatewayInterface|MockObject
    {
        $orderMock = $this->createMock(Order::class);
        $orderMock->method('getId')
            ->willReturn(1);
        $orderMock->method('getIncrementId')
            ->willReturn('100000001');
        $orderMock->method('isFraudDetected')
            ->willReturn(false);
        $orderMock->method('canInvoice')
            ->willReturn(true);
        $orderMock->method('canCreditmemo')
            ->willReturn(false);
        $orderMock->method('getTotalDue')
            ->willReturn(100.00);
        $orderMock->method('getGrandTotal')
            ->willReturn(100.00);
        $orderMock->method('addCommentToStatusHistory')
            ->willReturnSelf();

        $paymentMock = $this->createMock(Payment::class);
        $paymentMock->method('setTransactionId')
            ->willReturnSelf();
        $paymentMock->method('setLastTransId')
            ->willReturnSelf();
        $paymentMock->method('setShouldCloseParentTransaction')
            ->willReturnSelf();
        $paymentMock->method('setAdditionalInformation')
            ->willReturnSelf();
        $paymentMock->method('setTransactionAdditionalInfo')
            ->willReturnSelf();
        $paymentMock->method('setData')
            ->willReturnSelf();
        $paymentMock->method('getAdditionalInformation')
            ->willReturn([]);
        $paymentMock->method('getBaseAmountPaidOnline')
            ->willReturn(0);
        $paymentMock->method('addTransaction')
            ->willReturnSelf();

        $orderMock->method('getPayment')
            ->willReturn($paymentMock);

        $txnCollectionMock = $this->createMock(Collection::class);
        $openTxnMock = $this->createMock(Transaction::class);
        $openTxnMock->method('getTxnId')
            ->willReturn($txnId);
        $openTxnMock->method('getIsClosed')
            ->willReturn(false);

        $txnCollectionMock->method('addFieldToFilter')
            ->willReturnSelf();
        $txnCollectionMock->method('setOrder')
            ->willReturnSelf();
        $txnCollectionMock->method('getFirstItem')
            ->willReturn($openTxnMock);

        $this->txnCollectionFactoryMock->method('create')
            ->willReturn($txnCollectionMock);

        return $this->setupGatewayWithOrder($orderMock);
    }

    private function setupGatewayAndOrderForRefund(string $txnId): GatewayInterface|MockObject
    {
        $orderMock = $this->createMock(Order::class);
        $orderMock->method('getId')
            ->willReturn(1);
        $orderMock->method('getIncrementId')
            ->willReturn('100000001');
        $orderMock->method('isFraudDetected')
            ->willReturn(false);
        $orderMock->method('canInvoice')
            ->willReturn(false);
        $orderMock->method('canCreditmemo')
            ->willReturn(true);
        $orderMock->method('getTotalPaid')
            ->willReturn(100.00);
        $orderMock->method('getData')
            ->willReturnCallback(function ($key) {
                if ($key === 'invoice_id') {
                    return null;
                }

                return null;
            });
        $orderMock->method('addCommentToStatusHistory')
            ->willReturnSelf();

        $txnCollectionMock = $this->createMock(Collection::class);
        $openTxnMock = $this->createMock(Transaction::class);
        $openTxnMock->method('getTxnId')
            ->willReturn($txnId);
        $openTxnMock->method('getIsClosed')
            ->willReturn(false);

        $txnCollectionMock->method('addFieldToFilter')
            ->willReturnSelf();
        $txnCollectionMock->method('setOrder')
            ->willReturnSelf();
        $txnCollectionMock->method('getFirstItem')
            ->willReturn($openTxnMock);

        $this->txnCollectionFactoryMock->method('create')
            ->willReturn($txnCollectionMock);

        $creditmemoMock = $this->createMock(Creditmemo::class);
        $creditmemoMock->method('getGrandTotal')
            ->willReturn(100.00);

        $this->creditmemoFactoryMock->method('createByOrder')
            ->willReturn($creditmemoMock);

        return $this->setupGatewayWithOrder($orderMock);
    }

    private function setupGatewayWithOrder(Order|MockObject $orderMock): GatewayInterface|MockObject
    {
        $methodMock = $this->createMock(Method::class);
        $gatewayMock = $this->createMock(Gateway::class);

        $this->methodFactoryMock->method('getMethodInstance')
            ->willReturn($methodMock);

        $methodMock->method('gateway')
            ->willReturn($gatewayMock);

        $orderCollectionMock = $this->createMock(\Magento\Sales\Model\ResourceModel\Order\Collection::class);
        $orderCollectionMock->method('join')
            ->willReturnSelf();
        $orderCollectionMock->method('getTable')
            ->willReturn('table_name');
        $orderCollectionMock->method('getSelect')
            ->willReturn($this->createMock(Select::class));
        $orderCollectionMock->method('addFieldToFilter')
            ->willReturnSelf();
        $orderCollectionMock->method('setPageSize')
            ->willReturnSelf();
        $orderCollectionMock->method('getFirstItem')
            ->willReturn($orderMock);

        $this->orderCollectionFactoryMock->method('create')
            ->willReturn($orderCollectionMock);

        return $gatewayMock;
    }
}
