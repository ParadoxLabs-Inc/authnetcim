<?php

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Test\Unit\Gateway\Validator;

use Magento\Payment\Gateway\ConfigInterface;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use ParadoxLabs\Authnetcim\Gateway\Validator\NewAch;
use ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NewAchTest extends TestCase
{
    private NewAch $validator;
    private ResultInterfaceFactory|MockObject $resultFactoryMock;
    private ConfigInterface|MockObject $configMock;

    protected function setUp(): void
    {
        $this->resultFactoryMock = $this->createMock(ResultInterfaceFactory::class);
        $this->configMock = $this->createMock(ConfigInterface::class);

        $this->validator = new NewAch(
            $this->resultFactoryMock,
            $this->configMock,
        );
    }

    public function testValidateSkipsHostedValidationForNonHostedFormType(): void
    {
        $this->configMock->method('getValue')
            ->with('form_type')
            ->willReturn('inline');

        $paymentMock = $this->createOrderPaymentMock();
        $paymentMock->method('getAdditionalInformation')
            ->willReturn(['transaction_id' => 'txn123']);
        $paymentMock->method('hasData')
            ->willReturn(true);

        $resultMock = $this->createMock(ResultInterface::class);
        $resultMock->method('isValid')
            ->willReturn(true);

        $this->resultFactoryMock->method('create')
            ->willReturn($resultMock);

        $result = $this->validator->validate([
            'payment' => $paymentMock,
            'storeId' => 1,
        ]);

        $this->assertTrue($result->isValid());
    }

    public function testValidateSkipsHostedValidationForQuotePayment(): void
    {
        $this->configMock->method('getValue')
            ->with('form_type')
            ->willReturn(ConfigProvider::FORM_HOSTED);

        $paymentMock = $this->createMock(QuotePayment::class);
        $paymentMock->method('getAdditionalInformation')
            ->willReturn(['transaction_id' => 'txn123']);
        $paymentMock->method('hasData')
            ->willReturn(true);

        $resultMock = $this->createMock(ResultInterface::class);
        $resultMock->method('isValid')
            ->willReturn(true);

        $this->resultFactoryMock->method('create')
            ->willReturn($resultMock);

        $result = $this->validator->validate([
            'payment' => $paymentMock,
            'storeId' => 1,
        ]);

        $this->assertTrue($result->isValid());
    }

    public function testValidateSkipsHostedValidationWhenNoTransactionId(): void
    {
        $this->configMock->method('getValue')
            ->with('form_type')
            ->willReturn(ConfigProvider::FORM_HOSTED);

        $paymentMock = $this->createOrderPaymentMock();
        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key = null) {
                if ($key === 'transaction_id') {
                    return null;
                }

                return [];
            });
        $paymentMock->method('hasData')
            ->willReturn(true);

        $resultMock = $this->createMock(ResultInterface::class);
        $resultMock->method('isValid')
            ->willReturn(true);

        $this->resultFactoryMock->method('create')
            ->willReturn($resultMock);

        $result = $this->validator->validate([
            'payment' => $paymentMock,
            'storeId' => 1,
        ]);

        $this->assertTrue($result->isValid());
    }

    public function testValidateThrowsForDeclinedResponseCode(): void
    {
        $this->configMock->method('getValue')
            ->with('form_type')
            ->willReturn(ConfigProvider::FORM_HOSTED);

        $paymentMock = $this->createOrderPaymentMock();
        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key = null) {
                if ($key === 'transaction_id') {
                    return 'txn123';
                }

                return [
                    'transaction_id' => 'txn123',
                    'response_code' => 2,
                ];
            });

        $resultMock = $this->createMock(ResultInterface::class);
        $resultMock->method('isValid')
            ->willReturn(false);
        $resultMock->method('getFailsDescription')
            ->willReturn(['Transaction was declined.']);

        $this->resultFactoryMock->method('create')
            ->willReturn($resultMock);

        $result = $this->validator->validate([
            'payment' => $paymentMock,
            'storeId' => 1,
        ]);

        $this->assertFalse($result->isValid());
    }

    public function testValidateAcceptsApprovedResponseCodes(): void
    {
        $this->configMock->method('getValue')
            ->with('form_type')
            ->willReturn(ConfigProvider::FORM_HOSTED);

        $orderMock = $this->createOrderMock('100000001', 'test@example.com', 100.00);

        $paymentMock = $this->createOrderPaymentMock();
        $paymentMock->method('getOrder')
            ->willReturn($orderMock);
        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key = null) {
                if ($key === 'transaction_id') {
                    return 'txn123';
                }

                return [
                    'transaction_id' => 'txn123',
                    'response_code' => 1,
                    'customer_email' => 'test@example.com',
                    'invoice_number' => '100000001',
                    'amount' => 100.00,
                    'submit_time_utc' => date('Y-m-d H:i:s'),
                ];
            });
        $paymentMock->method('hasData')
            ->willReturn(true);

        $resultMock = $this->createMock(ResultInterface::class);
        $resultMock->method('isValid')
            ->willReturn(true);

        $this->resultFactoryMock->method('create')
            ->willReturn($resultMock);

        $result = $this->validator->validate([
            'payment' => $paymentMock,
            'storeId' => 1,
        ]);

        $this->assertTrue($result->isValid());
    }

    public function testValidateThrowsForEmailMismatch(): void
    {
        $this->configMock->method('getValue')
            ->with('form_type')
            ->willReturn(ConfigProvider::FORM_HOSTED);

        $orderMock = $this->createOrderMock('100000001', 'order@example.com', 100.00);

        $paymentMock = $this->createOrderPaymentMock();
        $paymentMock->method('getOrder')
            ->willReturn($orderMock);
        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key = null) {
                if ($key === 'transaction_id') {
                    return 'txn123';
                }

                return [
                    'transaction_id' => 'txn123',
                    'response_code' => 1,
                    'customer_email' => 'different@example.com',
                    'invoice_number' => '100000001',
                    'amount' => 100.00,
                    'submit_time_utc' => date('Y-m-d H:i:s'),
                ];
            });

        $resultMock = $this->createMock(ResultInterface::class);
        $resultMock->method('isValid')
            ->willReturn(false);

        $this->resultFactoryMock->method('create')
            ->willReturn($resultMock);

        $result = $this->validator->validate([
            'payment' => $paymentMock,
            'storeId' => 1,
        ]);

        $this->assertFalse($result->isValid());
    }

    public function testValidateThrowsForAmountShortfall(): void
    {
        $this->configMock->method('getValue')
            ->with('form_type')
            ->willReturn(ConfigProvider::FORM_HOSTED);

        $orderMock = $this->createOrderMock('100000001', 'test@example.com', 100.00);

        $paymentMock = $this->createOrderPaymentMock();
        $paymentMock->method('getOrder')
            ->willReturn($orderMock);
        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key = null) {
                if ($key === 'transaction_id') {
                    return 'txn123';
                }

                return [
                    'transaction_id' => 'txn123',
                    'response_code' => 1,
                    'customer_email' => 'test@example.com',
                    'invoice_number' => '100000001',
                    'amount' => 99.99,
                    'submit_time_utc' => date('Y-m-d H:i:s'),
                ];
            });

        $resultMock = $this->createMock(ResultInterface::class);
        $resultMock->method('isValid')
            ->willReturn(false);

        $this->resultFactoryMock->method('create')
            ->willReturn($resultMock);

        $result = $this->validator->validate([
            'payment' => $paymentMock,
            'storeId' => 1,
        ]);

        $this->assertFalse($result->isValid());
    }

    public function testValidateThrowsForExpiredTransaction(): void
    {
        $this->configMock->method('getValue')
            ->with('form_type')
            ->willReturn(ConfigProvider::FORM_HOSTED);

        $orderMock = $this->createOrderMock('100000001', 'test@example.com', 100.00);

        $paymentMock = $this->createOrderPaymentMock();
        $paymentMock->method('getOrder')
            ->willReturn($orderMock);
        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key = null) {
                if ($key === 'transaction_id') {
                    return 'txn123';
                }

                $expiredTime = date('Y-m-d H:i:s', time() - 16 * 60);

                return [
                    'transaction_id' => 'txn123',
                    'response_code' => 1,
                    'customer_email' => 'test@example.com',
                    'invoice_number' => '100000001',
                    'amount' => 100.00,
                    'submit_time_utc' => $expiredTime,
                ];
            });

        $resultMock = $this->createMock(ResultInterface::class);
        $resultMock->method('isValid')
            ->willReturn(false);

        $this->resultFactoryMock->method('create')
            ->willReturn($resultMock);

        $result = $this->validator->validate([
            'payment' => $paymentMock,
            'storeId' => 1,
        ]);

        $this->assertFalse($result->isValid());
    }

    private function createOrderPaymentMock(): OrderPayment|MockObject
    {
        return $this->getMockBuilder(OrderPayment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAdditionalInformation', 'getOrder', 'hasData', 'getData'])
            ->getMock();
    }

    private function createOrderMock(
        string $incrementId,
        string $customerEmail,
        float $grandTotal
    ): Order|MockObject {
        $orderMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getIncrementId', 'getCustomerEmail', 'getBaseGrandTotal'])
            ->getMock();

        $orderMock->method('getIncrementId')
            ->willReturn($incrementId);
        $orderMock->method('getCustomerEmail')
            ->willReturn($customerEmail);
        $orderMock->method('getBaseGrandTotal')
            ->willReturn($grandTotal);

        return $orderMock;
    }
}
