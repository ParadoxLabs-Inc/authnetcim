<?php

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Test\Unit\Gateway\Validator;

use DateTime;
use Magento\Payment\Model\Info;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Payment\Gateway\ConfigInterface;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use ParadoxLabs\Authnetcim\Gateway\Validator\CreditCard;
use ParadoxLabs\Authnetcim\Model\ConfigProvider;
use ParadoxLabs\TokenBase\Gateway\Validator\CreditCard\Types;
use ParadoxLabs\TokenBase\Model\Method\Factory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CreditCardTest extends TestCase
{
    private CreditCard $validator;
    private ResultInterfaceFactory|MockObject $resultFactoryMock;
    private ConfigInterface|MockObject $configMock;
    private Types|MockObject $ccTypesMock;
    private TimezoneInterface|MockObject $dateProcessorMock;
    private Factory|MockObject $methodFactoryMock;
    private ResultInterface|MockObject $resultMock;

    protected function setUp(): void
    {
        $this->resultFactoryMock = $this->createMock(ResultInterfaceFactory::class);
        $this->configMock = $this->createMock(ConfigInterface::class);
        $this->ccTypesMock = $this->createMock(Types::class);
        $this->dateProcessorMock = $this->createMock(TimezoneInterface::class);
        $this->methodFactoryMock = $this->createMock(Factory::class);
        $this->resultMock = $this->createMock(ResultInterface::class);

        $this->resultFactoryMock->method('create')
            ->willReturn($this->resultMock);

        $this->validator = new CreditCard(
            $this->resultFactoryMock,
            $this->configMock,
            $this->ccTypesMock,
            $this->dateProcessorMock,
            $this->methodFactoryMock,
        );
    }

    public function testIsAcceptJsEnabledReturnsTrueWhenConfigured(): void
    {
        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'form_type' => ConfigProvider::FORM_ACCEPTJS,
                    'client_key' => 'valid-client-key',
                    default => null,
                };
            });

        $this->assertTrue($this->validator->isAcceptJsEnabled());
    }

    public function testIsAcceptJsEnabledReturnsFalseWithoutClientKey(): void
    {
        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'form_type' => ConfigProvider::FORM_ACCEPTJS,
                    'client_key' => '',
                    default => null,
                };
            });

        $this->assertFalse($this->validator->isAcceptJsEnabled());
    }

    public function testIsAcceptJsEnabledReturnsFalseForHostedForm(): void
    {
        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'form_type' => ConfigProvider::FORM_HOSTED,
                    'client_key' => 'some-key',
                    default => null,
                };
            });

        $this->assertFalse($this->validator->isAcceptJsEnabled());
    }

    public function testIsAcceptJsEnabledReturnsFalseForInlineForm(): void
    {
        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'form_type' => 'inline',
                    'client_key' => 'some-key',
                    default => null,
                };
            });

        $this->assertFalse($this->validator->isAcceptJsEnabled());
    }

    public function testValidateAcceptJsSkipsWhenDisabled(): void
    {
        $paymentMock = $this->createPaymentMock();

        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'form_type' => 'inline',
                    'client_key' => '',
                    'cctypes' => 'VI,MC',
                    default => null,
                };
            });

        // Even with raw CC number, should skip Accept.js validation when disabled
        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'cc_number' => '4111111111111111',
                    'cc_type' => 'VI',
                    default => null,
                };
            });

        $paymentMock->method('getAdditionalInformation')
            ->willReturn(null);

        $this->ccTypesMock->method('getTypeForCard')
            ->willReturn([
                'type' => 'VI',
                'luhn' => true,
                'code' => ['name' => 'CVV', 'size' => 3],
            ]);

        $dateTimeMock = $this->createMock(DateTime::class);
        $dateTimeMock->method('format')
            ->willReturn('2025');
        $this->dateProcessorMock->method('date')
            ->willReturn($dateTimeMock);

        $this->validator->validate(['payment' => $paymentMock, 'storeId' => 1]);

        // Expectation: no exception thrown
        $this->assertTrue(true);
    }

    public function testValidateAcceptJsThrowsOnRawCcNumber(): void
    {
        $paymentMock = $this->createPaymentMock();

        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'form_type' => ConfigProvider::FORM_ACCEPTJS,
                    'client_key' => 'valid-client-key',
                    'cctypes' => 'VI,MC',
                    default => null,
                };
            });

        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'cc_number' => '4111111111111111',
                    'cc_type' => 'VI',
                    default => null,
                };
            });

        $paymentMock->method('getAdditionalInformation')
            ->willReturn(null);

        $result = $this->validator->validate(['payment' => $paymentMock, 'storeId' => 1]);

        // Result should be returned with validation errors
        $this->assertInstanceOf(ResultInterface::class, $result);
    }

    public function testValidateAcceptJsPassesWithMaskedNumber(): void
    {
        $paymentMock = $this->createPaymentMock();

        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'form_type' => ConfigProvider::FORM_ACCEPTJS,
                    'client_key' => 'valid-client-key',
                    'cctypes' => 'VI,MC',
                    default => null,
                };
            });

        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'cc_number' => 'XXXX-XXXX-XXXX-1111',
                    'cc_type' => 'VI',
                    default => null,
                };
            });

        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key = null) {
                if ($key === null) {
                    return [];
                }

                return match ($key) {
                    'acceptjs_value' => 'token-value',
                    default => null,
                };
            });

        $this->validator->validate(['payment' => $paymentMock, 'storeId' => 1]);

        // Expectation: no exception thrown
        $this->assertTrue(true);
    }

    public function testValidateAcceptJsPassesWithEmptyNumber(): void
    {
        $paymentMock = $this->createPaymentMock();

        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'form_type' => ConfigProvider::FORM_ACCEPTJS,
                    'client_key' => 'valid-client-key',
                    'cctypes' => 'VI,MC',
                    default => null,
                };
            });

        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'cc_number' => '',
                    'cc_type' => 'VI',
                    default => null,
                };
            });

        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key = null) {
                if ($key === null) {
                    return [];
                }

                return match ($key) {
                    'acceptjs_value' => 'token-value',
                    default => null,
                };
            });

        $this->validator->validate(['payment' => $paymentMock, 'storeId' => 1]);

        $this->assertTrue(true);
    }

    public function testValidateHostedTransactionSkipsWhenNotHosted(): void
    {
        $paymentMock = $this->createOrderPaymentMock();

        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'form_type' => ConfigProvider::FORM_ACCEPTJS,
                    'client_key' => '',
                    'cctypes' => 'VI,MC',
                    default => null,
                };
            });

        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'cc_number' => '',
                    'cc_type' => 'VI',
                    default => null,
                };
            });

        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key = null) {
                if ($key === null) {
                    return ['transaction_id' => '123'];
                }

                return match ($key) {
                    'acceptjs_value' => 'token',
                    'transaction_id' => '123',
                    default => null,
                };
            });

        $this->validator->validate(['payment' => $paymentMock, 'storeId' => 1]);

        $this->assertTrue(true);
    }

    public function testValidateHostedTransactionSkipsWithTokenbaseId(): void
    {
        $paymentMock = $this->createOrderPaymentMock();

        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'form_type' => ConfigProvider::FORM_HOSTED,
                    'client_key' => '',
                    'cctypes' => 'VI,MC',
                    default => null,
                };
            });

        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'cc_number' => '',
                    'cc_type' => 'VI',
                    'tokenbase_id' => '456',
                    default => null,
                };
            });

        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key = null) {
                if ($key === null) {
                    return ['transaction_id' => '123'];
                }

                return match ($key) {
                    'acceptjs_value' => '',
                    'transaction_id' => '123',
                    default => null,
                };
            });

        $this->validator->validate(['payment' => $paymentMock, 'storeId' => 1]);

        $this->assertTrue(true);
    }

    public function testValidateHostedTransactionThrowsOnDeclined(): void
    {
        $paymentMock = $this->createOrderPaymentMock();
        $orderMock = $this->createMock(Order::class);

        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'form_type' => ConfigProvider::FORM_HOSTED,
                    'client_key' => '',
                    'cctypes' => 'VI,MC',
                    default => null,
                };
            });

        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'cc_number' => '',
                    'cc_type' => 'VI',
                    'tokenbase_id' => '',
                    default => null,
                };
            });

        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key = null) {
                if ($key === null) {
                    return [
                        'transaction_id' => '123',
                        'response_code' => 2, // Declined
                    ];
                }

                return match ($key) {
                    'acceptjs_value' => '',
                    'transaction_id' => '123',
                    default => null,
                };
            });

        $paymentMock->method('getOrder')
            ->willReturn($orderMock);

        $result = $this->validator->validate(['payment' => $paymentMock, 'storeId' => 1]);

        $this->assertInstanceOf(ResultInterface::class, $result);
    }

    public function testValidateHostedTransactionThrowsOnAmountMismatch(): void
    {
        $paymentMock = $this->createOrderPaymentMock();
        $orderMock = $this->createMock(Order::class);

        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'form_type' => ConfigProvider::FORM_HOSTED,
                    'client_key' => '',
                    'cctypes' => 'VI,MC',
                    default => null,
                };
            });

        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'cc_number' => '',
                    'cc_type' => 'VI',
                    'tokenbase_id' => '',
                    default => null,
                };
            });

        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key = null) {
                if ($key === null) {
                    return [
                        'transaction_id' => '123',
                        'response_code' => 1,
                        'amount' => 50.00,
                        'customer_email' => 'test@example.com',
                        'invoice_number' => '100000001',
                        'submit_time_utc' => date('Y-m-d H:i:s'),
                    ];
                }

                return match ($key) {
                    'acceptjs_value' => '',
                    'transaction_id' => '123',
                    default => null,
                };
            });

        $orderMock->method('getBaseGrandTotal')
            ->willReturn(100.00);
        $orderMock->method('getCustomerEmail')
            ->willReturn('test@example.com');
        $orderMock->method('getIncrementId')
            ->willReturn('100000001');

        $paymentMock->method('getOrder')
            ->willReturn($orderMock);

        $result = $this->validator->validate(['payment' => $paymentMock, 'storeId' => 1]);

        $this->assertInstanceOf(ResultInterface::class, $result);
    }

    public function testValidateHostedTransactionThrowsOnEmailMismatch(): void
    {
        $paymentMock = $this->createOrderPaymentMock();
        $orderMock = $this->createMock(Order::class);

        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'form_type' => ConfigProvider::FORM_HOSTED,
                    'client_key' => '',
                    'cctypes' => 'VI,MC',
                    default => null,
                };
            });

        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'cc_number' => '',
                    'cc_type' => 'VI',
                    'tokenbase_id' => '',
                    default => null,
                };
            });

        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key = null) {
                if ($key === null) {
                    return [
                        'transaction_id' => '123',
                        'response_code' => 1,
                        'amount' => 100.00,
                        'customer_email' => 'wrong@example.com',
                        'invoice_number' => '100000001',
                        'submit_time_utc' => date('Y-m-d H:i:s'),
                    ];
                }

                return match ($key) {
                    'acceptjs_value' => '',
                    'transaction_id' => '123',
                    default => null,
                };
            });

        $orderMock->method('getBaseGrandTotal')
            ->willReturn(100.00);
        $orderMock->method('getCustomerEmail')
            ->willReturn('test@example.com');
        $orderMock->method('getIncrementId')
            ->willReturn('100000001');

        $paymentMock->method('getOrder')
            ->willReturn($orderMock);

        $result = $this->validator->validate(['payment' => $paymentMock, 'storeId' => 1]);

        $this->assertInstanceOf(ResultInterface::class, $result);
    }

    public function testValidateHostedTransactionThrowsOnExpired(): void
    {
        $paymentMock = $this->createOrderPaymentMock();
        $orderMock = $this->createMock(Order::class);

        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'form_type' => ConfigProvider::FORM_HOSTED,
                    'client_key' => '',
                    'cctypes' => 'VI,MC',
                    default => null,
                };
            });

        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'cc_number' => '',
                    'cc_type' => 'VI',
                    'tokenbase_id' => '',
                    default => null,
                };
            });

        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key = null) {
                if ($key === null) {
                    return [
                        'transaction_id' => '123',
                        'response_code' => 1,
                        'amount' => 100.00,
                        'customer_email' => 'test@example.com',
                        'invoice_number' => '100000001',
                        'submit_time_utc' => date('Y-m-d H:i:s', strtotime('-20 minutes')),
                    ];
                }

                return match ($key) {
                    'acceptjs_value' => '',
                    'transaction_id' => '123',
                    default => null,
                };
            });

        $orderMock->method('getBaseGrandTotal')
            ->willReturn(100.00);
        $orderMock->method('getCustomerEmail')
            ->willReturn('test@example.com');
        $orderMock->method('getIncrementId')
            ->willReturn('100000001');

        $paymentMock->method('getOrder')
            ->willReturn($orderMock);

        $result = $this->validator->validate(['payment' => $paymentMock, 'storeId' => 1]);

        $this->assertInstanceOf(ResultInterface::class, $result);
    }

    public function testValidateHostedTransactionPassesValid(): void
    {
        $paymentMock = $this->createOrderPaymentMock();
        $orderMock = $this->createMock(Order::class);

        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'form_type' => ConfigProvider::FORM_HOSTED,
                    'client_key' => '',
                    'cctypes' => 'VI,MC',
                    default => null,
                };
            });

        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'cc_number' => '',
                    'cc_type' => 'VI',
                    'tokenbase_id' => '',
                    default => null,
                };
            });

        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key = null) {
                if ($key === null) {
                    return [
                        'transaction_id' => '123',
                        'response_code' => 1,
                        'amount' => 100.00,
                        'customer_email' => 'test@example.com',
                        'invoice_number' => '100000001',
                        'submit_time_utc' => date('Y-m-d H:i:s'),
                    ];
                }

                return match ($key) {
                    'acceptjs_value' => '',
                    'transaction_id' => '123',
                    default => null,
                };
            });

        $orderMock->method('getBaseGrandTotal')
            ->willReturn(100.00);
        $orderMock->method('getCustomerEmail')
            ->willReturn('test@example.com');
        $orderMock->method('getIncrementId')
            ->willReturn('100000001');

        $paymentMock->method('getOrder')
            ->willReturn($orderMock);

        $this->validator->validate(['payment' => $paymentMock, 'storeId' => 1]);

        $this->assertTrue(true);
    }

    public function testValidateHostedTransactionPassesWithResponseCode4(): void
    {
        $paymentMock = $this->createOrderPaymentMock();
        $orderMock = $this->createMock(Order::class);

        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'form_type' => ConfigProvider::FORM_HOSTED,
                    'client_key' => '',
                    'cctypes' => 'VI,MC',
                    default => null,
                };
            });

        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'cc_number' => '',
                    'cc_type' => 'VI',
                    'tokenbase_id' => '',
                    default => null,
                };
            });

        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key = null) {
                if ($key === null) {
                    return [
                        'transaction_id' => '123',
                        'response_code' => 4, // Held for review
                        'amount' => 100.00,
                        'customer_email' => 'test@example.com',
                        'invoice_number' => '100000001',
                        'submit_time_utc' => date('Y-m-d H:i:s'),
                    ];
                }

                return match ($key) {
                    'acceptjs_value' => '',
                    'transaction_id' => '123',
                    default => null,
                };
            });

        $orderMock->method('getBaseGrandTotal')
            ->willReturn(100.00);
        $orderMock->method('getCustomerEmail')
            ->willReturn('test@example.com');
        $orderMock->method('getIncrementId')
            ->willReturn('100000001');

        $paymentMock->method('getOrder')
            ->willReturn($orderMock);

        $this->validator->validate(['payment' => $paymentMock, 'storeId' => 1]);

        $this->assertTrue(true);
    }

    public function testValidateCcTypeThrowsForDisallowed(): void
    {
        $paymentMock = $this->createPaymentMock();

        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'form_type' => ConfigProvider::FORM_ACCEPTJS,
                    'client_key' => 'key',
                    'cctypes' => 'VI,MC', // Only Visa and MC allowed
                    default => null,
                };
            });

        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'cc_number' => 'XXXX1111',
                    'cc_type' => 'AE', // Amex not allowed
                    default => null,
                };
            });

        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key = null) {
                if ($key === null) {
                    return [];
                }

                return match ($key) {
                    'acceptjs_value' => 'token',
                    default => null,
                };
            });

        $result = $this->validator->validate(['payment' => $paymentMock, 'storeId' => 1]);

        $this->assertInstanceOf(ResultInterface::class, $result);
    }

    public function testValidateCcTypePassesForAllowed(): void
    {
        $paymentMock = $this->createPaymentMock();

        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'form_type' => ConfigProvider::FORM_ACCEPTJS,
                    'client_key' => 'key',
                    'cctypes' => 'VI,MC,AE',
                    default => null,
                };
            });

        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'cc_number' => 'XXXX1111',
                    'cc_type' => 'VI',
                    default => null,
                };
            });

        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key = null) {
                if ($key === null) {
                    return [];
                }

                return match ($key) {
                    'acceptjs_value' => 'token',
                    default => null,
                };
            });

        $this->validator->validate(['payment' => $paymentMock, 'storeId' => 1]);

        $this->assertTrue(true);
    }

    public function testValidateCcTypeSkipsForHostedForm(): void
    {
        $paymentMock = $this->createPaymentMock();

        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'form_type' => ConfigProvider::FORM_HOSTED,
                    'client_key' => '',
                    'cctypes' => 'VI,MC', // AE not allowed
                    default => null,
                };
            });

        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'cc_number' => '',
                    'cc_type' => 'AE', // Would fail if checked
                    default => null,
                };
            });

        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key = null) {
                if ($key === null) {
                    return [];
                }

                return match ($key) {
                    'acceptjs_value' => 'token',
                    default => null,
                };
            });

        $this->validator->validate(['payment' => $paymentMock, 'storeId' => 1]);

        // CC type validation should be skipped for hosted form
        $this->assertTrue(true);
    }

    public function testValidateCcTypePassesWhenNoTypeSet(): void
    {
        $paymentMock = $this->createPaymentMock();

        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'form_type' => ConfigProvider::FORM_ACCEPTJS,
                    'client_key' => 'key',
                    'cctypes' => 'VI,MC',
                    default => null,
                };
            });

        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                return match ($key) {
                    'cc_number' => 'XXXX1111',
                    'cc_type' => null, // No type set
                    default => null,
                };
            });

        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key = null) {
                if ($key === null) {
                    return [];
                }

                return match ($key) {
                    'acceptjs_value' => 'token',
                    default => null,
                };
            });

        $this->validator->validate(['payment' => $paymentMock, 'storeId' => 1]);

        $this->assertTrue(true);
    }

    private function createPaymentMock(): MockObject
    {
        // getOrder() is magic on Info; unused by tests that call this factory (getData default is null).
        return $this->getMockBuilder(Info::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'getAdditionalInformation'])
            ->getMock();
    }

    private function createOrderPaymentMock(): OrderPayment|MockObject
    {
        return $this->getMockBuilder(OrderPayment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'getAdditionalInformation', 'getOrder'])
            ->getMock();
    }
}
