<?php

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Test\Unit\Model;

use Closure;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use ParadoxLabs\Authnetcim\Helper\Data;
use ParadoxLabs\Authnetcim\Model\ConfigProvider;
use ParadoxLabs\Authnetcim\Model\Gateway;
use ParadoxLabs\Authnetcim\Model\Method;
use ParadoxLabs\TokenBase\Api\CardRepositoryInterface;
use ParadoxLabs\TokenBase\Api\Data\CardInterface;
use ParadoxLabs\TokenBase\Model\AbstractMethod;
use ParadoxLabs\TokenBase\Model\Card;
use ParadoxLabs\TokenBase\Model\Gateway\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MethodTest extends TestCase
{
    public function testIsAcceptJsEnabledReturnsTrue(): void
    {
        $method = $this->createMethodMock(['getConfigData']);

        $method->method('getConfigData')
            ->willReturnCallback(function ($key) {
                if ($key === 'form_type') {
                    return ConfigProvider::FORM_ACCEPTJS;
                }

                if ($key === 'client_key') {
                    return 'test_client_key';
                }

                return null;
            });

        $result = $method->isAcceptJsEnabled();

        $this->assertTrue($result);
    }

    public function testIsAcceptJsEnabledReturnsFalseNoKey(): void
    {
        $method = $this->createMethodMock(['getConfigData']);

        $method->method('getConfigData')
            ->willReturnCallback(function ($key) {
                if ($key === 'form_type') {
                    return ConfigProvider::FORM_ACCEPTJS;
                }

                if ($key === 'client_key') {
                    return '';
                }

                return null;
            });

        $result = $method->isAcceptJsEnabled();

        $this->assertFalse($result);
    }

    public function testIsAcceptJsEnabledReturnsFalseWrongFormType(): void
    {
        $method = $this->createMethodMock(['getConfigData']);

        $method->method('getConfigData')
            ->willReturnCallback(function ($key) {
                if ($key === 'form_type') {
                    return ConfigProvider::FORM_HOSTED;
                }

                if ($key === 'client_key') {
                    return 'test_client_key';
                }

                return null;
            });

        $result = $method->isAcceptJsEnabled();

        $this->assertFalse($result);
    }

    public function testPaymentContainsCardDetectsAcceptJsToken(): void
    {
        $method = $this->createMethodMock(['getInfoInstance']);

        $infoMock = $this->createMock(InfoInterface::class);
        $infoMock->method('getAdditionalInformation')
            ->with('acceptjs_value')
            ->willReturn('encrypted_token_value');

        $method->method('getInfoInstance')
            ->willReturn($infoMock);

        $paymentMock = $this->createMock(InfoInterface::class);

        $reflection = new ReflectionMethod($method, 'paymentContainsCard');

        $result = $reflection->invoke($method, $paymentMock);

        $this->assertTrue($result);
    }

    public function testPaymentContainsCardDelegatesToParent(): void
    {
        $method = $this->createMethodMock(['getInfoInstance']);

        $infoMock = $this->createMock(InfoInterface::class);
        $infoMock->method('getAdditionalInformation')
            ->with('acceptjs_value')
            ->willReturn(null);

        $method->method('getInfoInstance')
            ->willReturn($infoMock);

        $paymentMock = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                if ($key === 'cc_number') {
                    return null;
                }

                return null;
            });

        $reflection = new ReflectionMethod($method, 'paymentContainsCard');

        $result = $reflection->invoke($method, $paymentMock);

        $this->assertFalse($result);
    }

    public function testFixLegacyCcTypeSetsTypeFromResponse(): void
    {
        $cardMock = $this->getMockBuilder(Card::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getType', 'setType', 'setData'])
            ->getMock();
        $cardMock->method('getType')
            ->willReturn(null);
        $cardMock->method('setType')
            ->willReturnSelf();
        $cardMock->method('setData')
            ->willReturnSelf();

        $cardRepositoryMock = $this->createMock(CardRepositoryInterface::class);
        $cardRepositoryMock->method('save')
            ->willReturn($cardMock);

        $helperMock = $this->createMock(Data::class);
        $helperMock->method('mapCcTypeToMagento')
            ->with('Visa')
            ->willReturn('VI');

        $responseMock = $this->createMock(Response::class);
        $responseMock->method('getData')
            ->willReturnCallback(function ($key) {
                if ($key === 'card_type') {
                    return 'Visa';
                }

                return null;
            });

        $orderPaymentMock = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setCcType'])
            ->getMock();
        $orderPaymentMock->expects($this->once())
            ->method('setCcType')
            ->with('VI');

        $orderMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPayment'])
            ->getMock();
        $orderMock->method('getPayment')
            ->willReturn($orderPaymentMock);

        $paymentMock = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrder'])
            ->getMock();
        $paymentMock->method('getOrder')
            ->willReturn($orderMock);

        $method = $this->getMockBuilder(Method::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCard'])
            ->getMock();
        $method->method('getCard')
            ->willReturn($cardMock);

        Closure::bind(
            function () use ($helperMock, $cardRepositoryMock): void {
                $this->helper         = $helperMock;
                $this->cardRepository = $cardRepositoryMock;
            },
            $method,
            AbstractMethod::class
        )();

        $reflection = new ReflectionMethod($method, 'fixLegacyCcType');

        $result = $reflection->invoke($method, $paymentMock, $responseMock);

        $this->assertSame($paymentMock, $result);
    }

    public function testFixLegacyCcTypeSkipsExistingType(): void
    {
        $cardMock = $this->createMock(CardInterface::class);
        $cardMock->method('getType')
            ->willReturn('VI');

        $responseMock = $this->createMock(Response::class);
        $responseMock->method('getData')
            ->willReturnCallback(function ($key) {
                if ($key === 'card_type') {
                    return 'Visa';
                }

                return null;
            });

        $paymentMock = $this->createMock(Payment::class);

        $method = $this->getMockBuilder(Method::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCard'])
            ->getMock();
        $method->method('getCard')
            ->willReturn($cardMock);

        $reflection = new ReflectionMethod($method, 'fixLegacyCcType');

        $result = $reflection->invoke($method, $paymentMock, $responseMock);

        $this->assertSame($paymentMock, $result);
    }

    public function testStoreTransactionStatusesSetsEmptyFields(): void
    {
        $method = $this->createMethodMock([]);

        $responseMock = $this->createMock(Response::class);
        $responseMock->method('getData')
            ->willReturnCallback(function ($key) {
                $data = [
                    'avs_result_code' => 'Y',
                    'card_code_response_code' => 'M',
                    'cavv_response_code' => '2',
                    'auth_code' => 'ABC123',
                ];

                return $data[$key] ?? null;
            });

        $paymentMock = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'setData'])
            ->getMock();

        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                return null;
            });

        $paymentMock->expects($this->exactly(4))
            ->method('setData')
            ->willReturnSelf();

        $reflection = new ReflectionMethod($method, 'storeTransactionStatuses');

        $result = $reflection->invoke($method, $paymentMock, $responseMock);

        $this->assertSame($paymentMock, $result);
    }

    public function testStoreTransactionStatusesSkipsPopulatedFields(): void
    {
        $method = $this->createMethodMock([]);

        $responseMock = $this->createMock(Response::class);
        $responseMock->method('getData')
            ->willReturnCallback(function ($key) {
                $data = [
                    'avs_result_code' => 'Y',
                    'card_code_response_code' => 'M',
                    'cavv_response_code' => '2',
                    'auth_code' => 'ABC123',
                ];

                return $data[$key] ?? null;
            });

        $paymentMock = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'setData'])
            ->getMock();

        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                $existing = [
                    'cc_avs_status' => 'A',
                    'cc_cid_status' => 'P',
                    'cc_status' => '1',
                ];

                return $existing[$key] ?? null;
            });

        $paymentMock->expects($this->once())
            ->method('setData')
            ->with('cc_approval', 'ABC123')
            ->willReturnSelf();

        $reflection = new ReflectionMethod($method, 'storeTransactionStatuses');

        $result = $reflection->invoke($method, $paymentMock, $responseMock);

        $this->assertSame($paymentMock, $result);
    }

    public function testAcceptPaymentApprovesTransaction(): void
    {
        $gatewayMock = $this->createMock(Gateway::class);

        $responseMock = $this->createMock(Response::class);
        $responseMock->method('getData')
            ->willReturnCallback(function ($key = null) {
                if ($key === null) {
                    return ['is_approved' => true];
                }

                if ($key === 'is_approved') {
                    return true;
                }

                return null;
            });

        $gatewayMock->method('acceptPayment')
            ->willReturn($responseMock);

        $transactionMock = $this->createMock(TransactionInterface::class);
        $transactionMock->expects($this->once())
            ->method('setAdditionalInformation')
            ->with('is_transaction_fraud', false);

        $paymentMock = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getTransactionId',
                'setData',
                'getAuthorizationTransaction',
            ])
            ->getMock();
        $paymentMock->method('getTransactionId')
            ->willReturn('12345');

        // setIsTransactionApproved() is magic; it routes through the real __call into the
        // stubbed setData(). Assert on the 'is_transaction_approved' key instead of the magic setter.
        $isTransactionApprovedCalled = false;
        $paymentMock->method('setData')
            ->willReturnCallback(function ($key, $value = null) use ($paymentMock, &$isTransactionApprovedCalled) {
                if ($key === 'is_transaction_approved') {
                    $isTransactionApprovedCalled = true;

                    self::assertTrue($value);
                }

                return $paymentMock;
            });

        $paymentMock->method('getAuthorizationTransaction')
            ->willReturn($transactionMock);

        $method = $this->getMockBuilder(Method::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['gateway', 'log'])
            ->getMock();
        $method->method('gateway')
            ->willReturn($gatewayMock);
        $method->method('log')
            ->willReturn(null);

        $result = $method->acceptPayment($paymentMock);

        $this->assertTrue($isTransactionApprovedCalled);
        $this->assertTrue($result);
    }

    public function testAcceptPaymentReturnsFalseWhenNotApproved(): void
    {
        $gatewayMock = $this->createMock(Gateway::class);

        $responseMock = $this->createMock(Response::class);
        $responseMock->method('getData')
            ->willReturnCallback(function ($key = null) {
                if ($key === null) {
                    return ['is_approved' => false];
                }

                if ($key === 'is_approved') {
                    return false;
                }

                return null;
            });

        $gatewayMock->method('acceptPayment')
            ->willReturn($responseMock);

        $paymentMock = $this->createMock(Payment::class);

        $method = $this->getMockBuilder(Method::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['gateway', 'log'])
            ->getMock();
        $method->method('gateway')
            ->willReturn($gatewayMock);
        $method->method('log')
            ->willReturn(null);

        $result = $method->acceptPayment($paymentMock);

        $this->assertFalse($result);
    }

    public function testDenyPaymentDeniesTransaction(): void
    {
        $gatewayMock = $this->createMock(Gateway::class);

        $responseMock = $this->createMock(Response::class);
        $responseMock->method('getData')
            ->willReturnCallback(function ($key = null) {
                if ($key === null) {
                    return ['is_denied' => true];
                }

                if ($key === 'is_denied') {
                    return true;
                }

                return null;
            });

        $gatewayMock->method('denyPayment')
            ->willReturn($responseMock);

        $paymentMock = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setData'])
            ->getMock();

        // setIsTransactionDenied() is magic; it routes through the real __call into the
        // stubbed setData(). Assert on the 'is_transaction_denied' key instead of the magic setter.
        $isTransactionDeniedCalled = false;
        $paymentMock->method('setData')
            ->willReturnCallback(function ($key, $value = null) use ($paymentMock, &$isTransactionDeniedCalled) {
                if ($key === 'is_transaction_denied') {
                    $isTransactionDeniedCalled = true;

                    self::assertTrue($value);
                }

                return $paymentMock;
            });

        $method = $this->getMockBuilder(Method::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['gateway', 'log'])
            ->getMock();
        $method->method('gateway')
            ->willReturn($gatewayMock);
        $method->method('log')
            ->willReturn(null);

        $result = $method->denyPayment($paymentMock);

        $this->assertTrue($isTransactionDeniedCalled);
        $this->assertTrue($result);
    }

    public function testDenyPaymentReturnsFalseWhenNotDenied(): void
    {
        $gatewayMock = $this->createMock(Gateway::class);

        $responseMock = $this->createMock(Response::class);
        $responseMock->method('getData')
            ->willReturnCallback(function ($key = null) {
                if ($key === null) {
                    return ['is_denied' => false];
                }

                if ($key === 'is_denied') {
                    return false;
                }

                return null;
            });

        $gatewayMock->method('denyPayment')
            ->willReturn($responseMock);

        $paymentMock = $this->createMock(Payment::class);

        $method = $this->getMockBuilder(Method::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['gateway', 'log'])
            ->getMock();
        $method->method('gateway')
            ->willReturn($gatewayMock);
        $method->method('log')
            ->willReturn(null);

        $result = $method->denyPayment($paymentMock);

        $this->assertFalse($result);
    }

    private function createMethodMock(array $methods = []): Method|MockObject
    {
        return $this->getMockBuilder(Method::class)
            ->disableOriginalConstructor()
            ->onlyMethods($methods)
            ->getMock();
    }
}
