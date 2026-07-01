<?php

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Test\Unit\Observer;

use Magento\Customer\Api\Data\AddressInterface;
use Magento\Framework\DataObject;
use Magento\Quote\Api\Data\PaymentExtensionInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Payment;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use ParadoxLabs\Authnetcim\Model\Gateway;
use ParadoxLabs\Authnetcim\Observer\PaymentMethodAssignDataObserver;
use ParadoxLabs\Authnetcim\Model\Service\CustomerProfile;
use ParadoxLabs\TokenBase\Api\CardRepositoryInterface;
use ParadoxLabs\TokenBase\Api\Data\CardInterface;
use ParadoxLabs\TokenBase\Api\Data\CardInterfaceFactory;
use ParadoxLabs\Authnetcim\Model\Method as AuthnetcimMethod;
use ParadoxLabs\TokenBase\Helper\Data;
use ParadoxLabs\TokenBase\Model\Gateway\Response;
use ParadoxLabs\TokenBase\Model\Method\Factory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PaymentMethodAssignDataObserverTest extends TestCase
{
    private PaymentMethodAssignDataObserver $observer;
    private Data|MockObject $helperMock;
    private CardRepositoryInterface|MockObject $cardRepositoryMock;
    private Factory|MockObject $methodFactoryMock;
    private CustomerProfile|MockObject $customerProfileServiceMock;
    private CardInterfaceFactory|MockObject $cardFactoryMock;

    protected function setUp(): void
    {
        $this->helperMock = $this->createMock(Data::class);
        $this->cardRepositoryMock = $this->createMock(CardRepositoryInterface::class);
        $this->methodFactoryMock = $this->createMock(Factory::class);
        $this->customerProfileServiceMock = $this->createMock(CustomerProfile::class);
        $this->cardFactoryMock = $this->createMock(CardInterfaceFactory::class);

        $this->observer = new PaymentMethodAssignDataObserver(
            $this->helperMock,
            $this->cardRepositoryMock,
            $this->methodFactoryMock,
            $this->customerProfileServiceMock,
            $this->cardFactoryMock,
        );
    }

    public function testProcessAcceptJsStoresToken(): void
    {
        $paymentMock = $this->createPaymentMock();
        $dataMock = new DataObject([
            'acceptjs_key' => 'my-key',
            'acceptjs_value' => 'my-value',
            'cc_last4' => '1111',
        ]);

        $tokenbaseMethodMock = $this->createMock(AuthnetcimMethod::class);
        $tokenbaseMethodMock->method('isAcceptJsEnabled')
            ->willReturn(true);
        $tokenbaseMethodMock->method('getConfigData')
            ->willReturn(0);

        $paymentMock->method('setAdditionalInformation')
            ->willReturnSelf();

        $paymentMock->expects($this->once())
            ->method('setCcLast4')
            ->with('1111')
            ->willReturnSelf();

        $paymentMock->method('setData')
            ->willReturnSelf();

        $this->observer->processAcceptJs($paymentMock, $dataMock, $tokenbaseMethodMock);
    }

    public function testProcessAcceptJsStoresBinWhenEnabled(): void
    {
        $paymentMock = $this->createPaymentMock();
        $dataMock = new DataObject([
            'acceptjs_key' => 'my-key',
            'acceptjs_value' => 'my-value',
            'cc_last4' => '1111',
            'cc_bin' => '411111',
        ]);

        $tokenbaseMethodMock = $this->createMock(AuthnetcimMethod::class);
        $tokenbaseMethodMock->method('isAcceptJsEnabled')
            ->willReturn(true);
        $tokenbaseMethodMock->method('getConfigData')
            ->with('can_store_bin')
            ->willReturn(1);

        $setAdditionalCalls = [];
        $paymentMock->method('setAdditionalInformation')
            ->willReturnCallback(function ($key, $value = null) use (&$setAdditionalCalls, $paymentMock) {
                if ($key !== null && $value !== null) {
                    $setAdditionalCalls[$key] = $value;
                }

                return $paymentMock;
            });

        $paymentMock->method('setCcLast4')
            ->willReturnSelf();

        $this->observer->processAcceptJs($paymentMock, $dataMock, $tokenbaseMethodMock);

        $this->assertArrayHasKey('cc_bin', $setAdditionalCalls);
        $this->assertSame('411111', $setAdditionalCalls['cc_bin']);
    }

    public function testProcessAcceptJsResetsTokenbaseId(): void
    {
        $paymentMock = $this->createPaymentMock();
        $dataMock = new DataObject([
            'acceptjs_key' => 'my-key',
            'acceptjs_value' => 'my-value',
            'cc_last4' => '1111',
        ]);

        $tokenbaseMethodMock = $this->createMock(AuthnetcimMethod::class);
        $tokenbaseMethodMock->method('isAcceptJsEnabled')
            ->willReturn(true);
        $tokenbaseMethodMock->method('getConfigData')
            ->willReturn(0);

        $paymentMock->method('setAdditionalInformation')
            ->willReturnSelf();
        $paymentMock->method('setCcLast4')
            ->willReturnSelf();

        $paymentMock->expects($this->once())
            ->method('setData')
            ->with('tokenbase_id', null)
            ->willReturnSelf();

        $extAttributesMock = $this->createMock(PaymentExtensionInterface::class);
        $extAttributesMock->expects($this->once())
            ->method('setTokenbaseId')
            ->with(null);

        $paymentMock->method('getExtensionAttributes')
            ->willReturn($extAttributesMock);

        $this->observer->processAcceptJs($paymentMock, $dataMock, $tokenbaseMethodMock);
    }

    public function testProcessAcceptJsSkipsWhenDisabled(): void
    {
        $paymentMock = $this->createPaymentMock();
        $dataMock = new DataObject([
            'acceptjs_key' => 'my-key',
            'acceptjs_value' => 'my-value',
        ]);

        $tokenbaseMethodMock = $this->createMock(AuthnetcimMethod::class);
        $tokenbaseMethodMock->method('isAcceptJsEnabled')
            ->willReturn(false);

        $paymentMock->expects($this->never())
            ->method('setAdditionalInformation');

        $this->observer->processAcceptJs($paymentMock, $dataMock, $tokenbaseMethodMock);
    }

    public function testProcessAcceptJsSkipsWhenNoKey(): void
    {
        $paymentMock = $this->createPaymentMock();
        $dataMock = new DataObject([
            'acceptjs_key' => '',
            'acceptjs_value' => 'my-value',
        ]);

        $tokenbaseMethodMock = $this->createMock(AuthnetcimMethod::class);
        $tokenbaseMethodMock->method('isAcceptJsEnabled')
            ->willReturn(true);

        $paymentMock->expects($this->never())
            ->method('setAdditionalInformation');

        $this->observer->processAcceptJs($paymentMock, $dataMock, $tokenbaseMethodMock);
    }

    public function testProcessAcceptJsSkipsWhenNoValue(): void
    {
        $paymentMock = $this->createPaymentMock();
        $dataMock = new DataObject([
            'acceptjs_key' => 'my-key',
            'acceptjs_value' => '',
        ]);

        $tokenbaseMethodMock = $this->createMock(AuthnetcimMethod::class);
        $tokenbaseMethodMock->method('isAcceptJsEnabled')
            ->willReturn(true);

        $paymentMock->expects($this->never())
            ->method('setAdditionalInformation');

        $this->observer->processAcceptJs($paymentMock, $dataMock, $tokenbaseMethodMock);
    }

    public function testProcessAcceptHostedSkipsEmptyTransactionId(): void
    {
        $paymentMock = $this->createMock(Payment::class);
        $dataMock = new DataObject([
            'transaction_id' => '',
        ]);

        $tokenbaseMethodMock = $this->createMock(AuthnetcimMethod::class);

        $tokenbaseMethodMock->expects($this->never())
            ->method('gateway');

        $this->observer->processAcceptHosted($paymentMock, $dataMock, $tokenbaseMethodMock);
    }

    public function testProcessAcceptHostedSkipsWhenAcceptJsEnabled(): void
    {
        $paymentMock = $this->createMock(Payment::class);
        $dataMock = new DataObject([
            'transaction_id' => 'txn123',
        ]);

        $tokenbaseMethodMock = $this->createMock(AuthnetcimMethod::class);
        $tokenbaseMethodMock->method('isAcceptJsEnabled')
            ->willReturn(true);

        $tokenbaseMethodMock->expects($this->never())
            ->method('gateway');

        $this->observer->processAcceptHosted($paymentMock, $dataMock, $tokenbaseMethodMock);
    }

    public function testProcessAcceptHostedSkipsAlreadyProcessedTransaction(): void
    {
        $paymentMock = $this->createMock(Payment::class);
        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key) {
                if ($key === 'transaction_id') {
                    return 'txn123';
                }

                return null;
            });

        $dataMock = new DataObject([
            'transaction_id' => 'txn123', // Same as already stored
        ]);

        $tokenbaseMethodMock = $this->createMock(AuthnetcimMethod::class);
        $tokenbaseMethodMock->method('isAcceptJsEnabled')
            ->willReturn(false);

        $tokenbaseMethodMock->expects($this->never())
            ->method('gateway');

        $this->observer->processAcceptHosted($paymentMock, $dataMock, $tokenbaseMethodMock);
    }

    public function testProcessAcceptHostedSkipsNonQuotePayment(): void
    {
        // Use Sales\Order\Payment (non-Quote\Payment)
        $paymentMock = $this->createPaymentMock();
        $paymentMock->method('getAdditionalInformation')
            ->willReturn(null);

        $dataMock = new DataObject([
            'transaction_id' => 'txn123',
        ]);

        $tokenbaseMethodMock = $this->createMock(AuthnetcimMethod::class);
        $tokenbaseMethodMock->method('isAcceptJsEnabled')
            ->willReturn(false);

        $tokenbaseMethodMock->expects($this->never())
            ->method('gateway');

        $this->observer->processAcceptHosted($paymentMock, $dataMock, $tokenbaseMethodMock);
    }

    public function testProcessAcceptHostedFetchesTransaction(): void
    {
        $paymentMock = $this->createQuotePaymentMock();
        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key = null) {
                if ($key === 'transaction_id') {
                    return null;
                }

                if ($key === 'profile_id') {
                    return 'profile123';
                }

                return [];
            });

        $dataMock = new DataObject([
            'transaction_id' => 'txn456',
            'save' => false,
        ]);

        $tokenbaseMethodMock = $this->createMock(AuthnetcimMethod::class);
        $tokenbaseMethodMock->method('isAcceptJsEnabled')
            ->willReturn(false);

        $gatewayMock = $this->createMock(Gateway::class);
        $tokenbaseMethodMock->method('gateway')
            ->willReturn($gatewayMock);

        $gatewayMock->expects($this->once())
            ->method('setTransactionId')
            ->with('txn456');

        $transactionDetailsMock = $this->createMock(Response::class);
        $transactionDetailsMock->method('getResponseCode')
            ->willReturn(1);
        $transactionDetailsMock->method('getData')
            ->willReturnCallback(function ($key = null) {
                if ($key === 'amount') {
                    return 100.00;
                }

                if ($key === 'customer_email') {
                    return 'test@example.com';
                }

                if ($key === 'invoice_number') {
                    return '100000001';
                }

                if ($key === 'submit_time_utc') {
                    return date('Y-m-d H:i:s');
                }

                return [];
            });

        $gatewayMock->method('getTransactionDetailsObject')
            ->willReturn($transactionDetailsMock);

        $quoteMock = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBillingAddress', 'getReservedOrderId', 'getData'])
            ->getMock();

        // getBaseGrandTotal()/getCustomerId()/getCustomerEmail() are magic on Quote;
        // they route through the real __call into the stubbed getData().
        $quoteMock->method('getData')
            ->willReturnMap([
                ['base_grand_total', null, 100.00],
                ['customer_id', null, 1],
                ['customer_email', null, 'test@example.com'],
            ]);
        $quoteMock->method('getReservedOrderId')
            ->willReturn('100000001');

        $billingAddressMock = $this->createMock(Address::class);
        $billingAddressMock->method('getEmail')
            ->willReturn('test@example.com');
        $billingAddressMock->method('getDataModel')
            ->willReturn($this->createMock(AddressInterface::class));

        $quoteMock->method('getBillingAddress')
            ->willReturn($billingAddressMock);

        $paymentMock->method('getQuote')
            ->willReturn($quoteMock);

        $paymentMock->method('getMethod')
            ->willReturn('authnetcim');

        $paymentMock->method('setAdditionalInformation')
            ->willReturnSelf();
        $paymentMock->method('setData')
            ->willReturnSelf();

        $cardMock = $this->createMock(CardInterface::class);
        $cardMock->method('setMethod')
            ->willReturnSelf();
        $cardMock->method('setCustomerId')
            ->willReturnSelf();
        $cardMock->method('setCustomerEmail')
            ->willReturnSelf();
        $cardMock->method('setActive')
            ->willReturnSelf();
        $cardMock->method('setProfileId')
            ->willReturnSelf();
        $cardMock->method('setAddress')
            ->willReturnSelf();
        $cardMock->method('setPaymentId')
            ->willReturnSelf();
        $cardMock->method('getId')
            ->willReturn(123);
        $cardMock->method('getPaymentId')
            ->willReturn('payment456');

        $this->cardFactoryMock->method('create')
            ->willReturn($cardMock);

        $gatewayMock->method('createCustomerProfileFromTransaction')
            ->willReturn([
                'customerPaymentProfileIdList' => ['numericString' => 'payment456'],
            ]);

        $this->customerProfileServiceMock->method('updateCardFromPaymentProfile')
            ->willReturn($cardMock);

        $this->observer->processAcceptHosted($paymentMock, $dataMock, $tokenbaseMethodMock);
    }

    private function createPaymentMock(): OrderPayment|MockObject
    {
        // setCcLast4()/setData()/getExtensionAttributes() aren't declared on InfoInterface,
        // but they ARE real methods on Sales\Order\Payment -- mock that concrete class
        // instead (it's still not instanceof Quote\Payment, which some tests below rely on
        // to exercise the "non-Quote payment" branch). getQuote()/getMethod() were unused
        // magic stubs on the old InfoInterface double; dropped.
        return $this->getMockBuilder(OrderPayment::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'setAdditionalInformation',
                'getAdditionalInformation',
                'setData',
                'setCcLast4',
                'getExtensionAttributes',
            ])
            ->getMock();
    }

    private function createQuotePaymentMock(): Payment|MockObject
    {
        return $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getAdditionalInformation',
                'setAdditionalInformation',
                'setData',
                'getQuote',
                'getMethod',
            ])
            ->getMock();
    }
}
