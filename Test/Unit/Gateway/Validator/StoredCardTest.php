<?php

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Test\Unit\Gateway\Validator;

use ParadoxLabs\TokenBase\Gateway\Validator\CreditCard\Types;
use Magento\Payment\Gateway\ConfigInterface;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use Magento\Payment\Model\Info;
use ParadoxLabs\Authnetcim\Gateway\Validator\CreditCard;
use ParadoxLabs\Authnetcim\Gateway\Validator\StoredCard;
use ParadoxLabs\Authnetcim\Model\ConfigProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StoredCardTest extends TestCase
{
    private StoredCard $validator;
    private ResultInterfaceFactory|MockObject $resultFactoryMock;
    private CreditCard|MockObject $ccValidatorMock;
    private ConfigInterface|MockObject $configMock;

    protected function setUp(): void
    {
        $this->resultFactoryMock = $this->createMock(ResultInterfaceFactory::class);
        $this->ccValidatorMock = $this->createMock(CreditCard::class);
        $this->configMock = $this->createMock(ConfigInterface::class);

        $this->validator = new StoredCard(
            $this->resultFactoryMock,
            $this->ccValidatorMock,
            $this->configMock,
        );
    }

    public function testValidateDelegatesToParentForAcceptJs(): void
    {
        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                if ($key === 'form_type') {
                    return ConfigProvider::FORM_ACCEPTJS;
                }

                return null;
            });

        $paymentMock = $this->createPaymentMock();
        $paymentMock->method('getData')
            ->willReturn(null);

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

    public function testValidateReturnsValidWhenNoTokenbaseId(): void
    {
        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                if ($key === 'form_type') {
                    return ConfigProvider::FORM_HOSTED;
                }

                return null;
            });

        $paymentMock = $this->createPaymentMock();
        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                if ($key === 'tokenbase_id') {
                    return null;
                }

                return null;
            });

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

    public function testValidateSkipsCvvWhenNotRequired(): void
    {
        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                if ($key === 'form_type') {
                    return ConfigProvider::FORM_HOSTED;
                }

                if ($key === 'require_ccv') {
                    return 0;
                }

                return null;
            });

        $paymentMock = $this->createPaymentMock();
        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                if ($key === 'tokenbase_id') {
                    return 123;
                }

                return null;
            });

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

    public function testValidateSkipsCvvForSubscriptionGenerated(): void
    {
        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                if ($key === 'form_type') {
                    return ConfigProvider::FORM_HOSTED;
                }

                if ($key === 'require_ccv') {
                    return 1;
                }

                return null;
            });

        $paymentMock = $this->createPaymentMock();
        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                if ($key === 'tokenbase_id') {
                    return 123;
                }

                return null;
            });
        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key) {
                if ($key === 'is_subscription_generated') {
                    return true;
                }

                return null;
            });

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

    public function testValidateSkipsCvvWhenTransactionIdPresent(): void
    {
        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                if ($key === 'form_type') {
                    return ConfigProvider::FORM_HOSTED;
                }

                if ($key === 'require_ccv') {
                    return 1;
                }

                return null;
            });

        $paymentMock = $this->createPaymentMock();
        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                if ($key === 'tokenbase_id') {
                    return 123;
                }

                return null;
            });
        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key) {
                if ($key === 'is_subscription_generated') {
                    return false;
                }

                if ($key === 'transaction_id') {
                    return 'txn123';
                }

                return null;
            });

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

    public function testValidateSkipsCvvForPaymentInfoSource(): void
    {
        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                if ($key === 'form_type') {
                    return ConfigProvider::FORM_HOSTED;
                }

                if ($key === 'require_ccv') {
                    return 1;
                }

                return null;
            });

        $paymentMock = $this->createPaymentMock();
        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                if ($key === 'tokenbase_id') {
                    return 123;
                }

                if ($key === 'tokenbase_source') {
                    return 'paymentinfo';
                }

                return null;
            });
        $paymentMock->method('getAdditionalInformation')
            ->willReturn(null);

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

    public function testValidateFailsForNonNumericCvv(): void
    {
        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                if ($key === 'form_type') {
                    return ConfigProvider::FORM_HOSTED;
                }

                if ($key === 'require_ccv') {
                    return 1;
                }

                return null;
            });

        $paymentMock = $this->createPaymentMock();
        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                if ($key === 'tokenbase_id') {
                    return 123;
                }

                if ($key === 'cc_cid') {
                    return 'abc';
                }

                if ($key === 'tokenbase_source') {
                    return 'checkout';
                }

                return null;
            });
        $paymentMock->method('getAdditionalInformation')
            ->willReturn(null);

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

    public function testValidateFailsForShortCvv(): void
    {
        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                if ($key === 'form_type') {
                    return ConfigProvider::FORM_HOSTED;
                }

                if ($key === 'require_ccv') {
                    return 1;
                }

                return null;
            });

        $paymentMock = $this->createPaymentMock();
        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                if ($key === 'tokenbase_id') {
                    return 123;
                }

                if ($key === 'cc_cid') {
                    return '12';
                }

                if ($key === 'tokenbase_source') {
                    return 'checkout';
                }

                return null;
            });
        $paymentMock->method('getAdditionalInformation')
            ->willReturn(null);

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

    public function testValidateFailsForWrongLengthCvv(): void
    {
        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                if ($key === 'form_type') {
                    return ConfigProvider::FORM_HOSTED;
                }

                if ($key === 'require_ccv') {
                    return 1;
                }

                return null;
            });

        $ccTypesMock = $this->getMockBuilder(Types::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getType'])
            ->getMock();
        $ccTypesMock->method('getType')
            ->with('VI')
            ->willReturn([
                'code' => [
                    'size' => 3,
                    'name' => 'CVV',
                ],
            ]);

        $this->ccValidatorMock->method('getCcTypes')
            ->willReturn($ccTypesMock);

        $paymentMock = $this->createPaymentMock();
        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                if ($key === 'tokenbase_id') {
                    return 123;
                }

                if ($key === 'cc_cid') {
                    return '1234';
                }

                if ($key === 'cc_type') {
                    return 'VI';
                }

                if ($key === 'tokenbase_source') {
                    return 'checkout';
                }

                return null;
            });
        $paymentMock->method('getAdditionalInformation')
            ->willReturn(null);

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

    public function testValidatePassesForCorrectCvv(): void
    {
        $this->configMock->method('getValue')
            ->willReturnCallback(function ($key) {
                if ($key === 'form_type') {
                    return ConfigProvider::FORM_HOSTED;
                }

                if ($key === 'require_ccv') {
                    return 1;
                }

                return null;
            });

        $ccTypesMock = $this->getMockBuilder(Types::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getType'])
            ->getMock();
        $ccTypesMock->method('getType')
            ->with('VI')
            ->willReturn([
                'code' => [
                    'size' => 3,
                    'name' => 'CVV',
                ],
            ]);

        $this->ccValidatorMock->method('getCcTypes')
            ->willReturn($ccTypesMock);

        $paymentMock = $this->createPaymentMock();
        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                if ($key === 'tokenbase_id') {
                    return 123;
                }

                if ($key === 'cc_cid') {
                    return '123';
                }

                if ($key === 'cc_type') {
                    return 'VI';
                }

                if ($key === 'tokenbase_source') {
                    return 'checkout';
                }

                return null;
            });
        $paymentMock->method('getAdditionalInformation')
            ->willReturn(null);

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

    private function createPaymentMock(): Info|MockObject
    {
        return $this->getMockBuilder(Info::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'getAdditionalInformation'])
            ->getMock();
    }
}
