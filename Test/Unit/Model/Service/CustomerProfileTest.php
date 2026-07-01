<?php

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Test\Unit\Model\Service;

use Magento\Framework\Exception\LocalizedException;
use ParadoxLabs\Authnetcim\Helper\Data;
use ParadoxLabs\Authnetcim\Model\Gateway;
use ParadoxLabs\Authnetcim\Model\Service\CustomerProfile;
use ParadoxLabs\TokenBase\Api\CardRepositoryInterface;
use ParadoxLabs\TokenBase\Api\Data\CardInterface;
use ParadoxLabs\TokenBase\Api\MethodInterface;
use ParadoxLabs\TokenBase\Model\Card;
use ParadoxLabs\TokenBase\Model\Method\Factory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CustomerProfileTest extends TestCase
{
    private CustomerProfile $customerProfile;
    private Data|MockObject $helperMock;
    private CardRepositoryInterface|MockObject $cardRepositoryMock;
    private Factory|MockObject $methodFactoryMock;
    private MethodInterface|MockObject $methodMock;
    private Gateway|MockObject $gatewayMock;
    private CardInterface|MockObject $cardMock;

    protected function setUp(): void
    {
        $this->helperMock = $this->createMock(Data::class);
        $this->cardRepositoryMock = $this->createMock(CardRepositoryInterface::class);
        $this->methodFactoryMock = $this->createMock(Factory::class);
        $this->methodMock = $this->createMock(MethodInterface::class);
        $this->gatewayMock = $this->createMock(Gateway::class);
        // getTypeInstance()/setData()/getData() aren't declared on CardInterface (they're
        // real methods on the concrete Card model), so mock the concrete class instead of
        // the interface -- avoids addMethods() while keeping every other CardInterface
        // getter/setter auto-mocked (all real, declared methods on Card).
        $this->cardMock = $this->getMockBuilder(Card::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->customerProfile = new CustomerProfile(
            $this->helperMock,
            $this->cardRepositoryMock,
            $this->methodFactoryMock,
        );
    }

    public function testSetMethodSetsActiveMethod(): void
    {
        $this->customerProfile->setMethod($this->methodMock);

        $this->methodMock->expects($this->once())
            ->method('gateway')
            ->willReturn($this->gatewayMock);

        // Verify method is used when calling fetchAddedCard
        $this->gatewayMock->method('getCustomerProfile')
            ->willReturn([
                'messages' => ['message' => ['text' => 'Successful.']],
                'profile' => ['paymentProfiles' => ['customerPaymentProfileId' => '123']],
            ]);

        $this->customerProfile->fetchAddedCard('profile123');
    }

    public function testFetchAddedCardSetsGatewayParams(): void
    {
        $this->methodFactoryMock->method('getMethodInstance')
            ->willReturn($this->methodMock);

        $this->methodMock->method('gateway')
            ->willReturn($this->gatewayMock);

        $this->gatewayMock->expects($this->exactly(3))
            ->method('setParameter')
            ->willReturnCallback(function ($key, $value) {
                static $calls = [];
                $calls[] = [$key, $value];

                if (count($calls) === 3) {
                    $this->assertContains(['customerProfileId', 'profile123'], $calls);
                    $this->assertContains(['unmaskExpirationDate', 'true'], $calls);
                    $this->assertContains(['includeIssuerInfo', 'true'], $calls);
                }
            });

        $this->gatewayMock->method('getCustomerProfile')
            ->willReturn([
                'messages' => ['message' => ['text' => 'Successful.']],
                'profile' => ['paymentProfiles' => ['customerPaymentProfileId' => '123']],
            ]);

        $this->customerProfile->fetchAddedCard('profile123');
    }

    public function testFetchAddedCardThrowsOnError(): void
    {
        $this->methodFactoryMock->method('getMethodInstance')
            ->willReturn($this->methodMock);

        $this->methodMock->method('gateway')
            ->willReturn($this->gatewayMock);

        $this->gatewayMock->method('getCustomerProfile')
            ->willReturn([
                'messages' => ['message' => ['text' => 'Profile not found.']],
            ]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Profile not found.');

        $this->customerProfile->fetchAddedCard('nonexistent');
    }

    public function testFetchAddedCardThrowsWhenNoPaymentProfiles(): void
    {
        $this->methodFactoryMock->method('getMethodInstance')
            ->willReturn($this->methodMock);

        $this->methodMock->method('gateway')
            ->willReturn($this->gatewayMock);

        $this->gatewayMock->method('getCustomerProfile')
            ->willReturn([
                'messages' => ['message' => ['text' => 'Successful.']],
                'profile' => [],
            ]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Unable to find payment record.');

        $this->customerProfile->fetchAddedCard('profile123');
    }

    public function testFetchAddedCardReturnsSingleProfile(): void
    {
        $this->methodFactoryMock->method('getMethodInstance')
            ->willReturn($this->methodMock);

        $this->methodMock->method('gateway')
            ->willReturn($this->gatewayMock);

        $expectedProfile = [
            'customerPaymentProfileId' => '456',
            'payment' => ['creditCard' => ['cardNumber' => 'XXXX1111']],
        ];

        $this->gatewayMock->method('getCustomerProfile')
            ->willReturn([
                'messages' => ['message' => ['text' => 'Successful.']],
                'profile' => ['paymentProfiles' => $expectedProfile],
            ]);

        $result = $this->customerProfile->fetchAddedCard('profile123');

        $this->assertSame($expectedProfile, $result);
    }

    public function testFetchAddedCardReturnsNewestProfile(): void
    {
        $this->methodFactoryMock->method('getMethodInstance')
            ->willReturn($this->methodMock);

        $this->methodMock->method('gateway')
            ->willReturn($this->gatewayMock);

        $profile1 = [
            'customerPaymentProfileId' => '100',
            'payment' => ['creditCard' => ['cardNumber' => 'XXXX1111']],
        ];
        $profile2 = [
            'customerPaymentProfileId' => '200',
            'payment' => ['creditCard' => ['cardNumber' => 'XXXX2222']],
        ];
        $profile3 = [
            'customerPaymentProfileId' => '300',
            'payment' => ['creditCard' => ['cardNumber' => 'XXXX3333']],
        ];

        $this->gatewayMock->method('getCustomerProfile')
            ->willReturn([
                'messages' => ['message' => ['text' => 'Successful.']],
                'profile' => ['paymentProfiles' => [$profile1, $profile3, $profile2]],
            ]);

        $result = $this->customerProfile->fetchAddedCard('profile123');

        // Should return profile with highest ID (300)
        $this->assertSame($profile3, $result);
    }

    public function testImportPaymentProfileSetsProfileId(): void
    {
        $paymentProfile = [
            'customerProfileId' => 'profile-abc',
            'customerPaymentProfileId' => 'payment-xyz',
            'payment' => ['creditCard' => [
                'cardNumber' => 'XXXX4444',
                'expirationDate' => '2028-12',
                'cardType' => 'Visa',
            ]],
        ];

        $this->cardMock->expects($this->once())
            ->method('setProfileId')
            ->with('profile-abc')
            ->willReturnSelf();

        $this->cardMock->method('getProfileId')
            ->willReturn('profile-abc');

        $this->cardMock->method('setPaymentId')
            ->willReturnSelf();

        $this->cardMock->method('getTypeInstance')
            ->willReturn($this->cardMock);

        $this->cardMock->method('setData')
            ->willReturnSelf();

        $this->cardMock->method('setAdditional')
            ->willReturnSelf();

        $this->cardMock->method('getAdditional')
            ->willReturn([]);

        $this->helperMock->method('mapCcTypeToMagento')
            ->with('Visa')
            ->willReturn('VI');

        $this->cardRepositoryMock->expects($this->once())
            ->method('save')
            ->with($this->cardMock);

        $this->customerProfile->importPaymentProfile($this->cardMock, $paymentProfile);
    }

    public function testImportPaymentProfileSetsPaymentId(): void
    {
        $paymentProfile = [
            'customerProfileId' => 'profile-abc',
            'customerPaymentProfileId' => 'payment-xyz',
            'payment' => ['creditCard' => [
                'cardNumber' => 'XXXX4444',
                'expirationDate' => '2028-12',
                'cardType' => 'Visa',
            ]],
        ];

        $this->cardMock->method('setProfileId')
            ->willReturnSelf();

        $this->cardMock->expects($this->once())
            ->method('setPaymentId')
            ->with('payment-xyz')
            ->willReturnSelf();

        $this->cardMock->method('getTypeInstance')
            ->willReturn($this->cardMock);

        $this->cardMock->method('setData')
            ->willReturnSelf();

        $this->cardMock->method('setAdditional')
            ->willReturnSelf();

        $this->cardMock->method('getAdditional')
            ->willReturn([]);

        $this->helperMock->method('mapCcTypeToMagento')
            ->willReturn('VI');

        $this->cardRepositoryMock->expects($this->once())
            ->method('save');

        $this->customerProfile->importPaymentProfile($this->cardMock, $paymentProfile);
    }

    public function testImportPaymentProfileSavesCard(): void
    {
        $paymentProfile = [
            'customerProfileId' => 'profile-abc',
            'customerPaymentProfileId' => 'payment-xyz',
            'payment' => ['creditCard' => [
                'cardNumber' => 'XXXX4444',
                'expirationDate' => '2028-12',
                'cardType' => 'Visa',
            ]],
        ];

        $this->cardMock->method('setProfileId')
            ->willReturnSelf();

        $this->cardMock->method('setPaymentId')
            ->willReturnSelf();

        $this->cardMock->method('getTypeInstance')
            ->willReturn($this->cardMock);

        $this->cardMock->method('setData')
            ->willReturnSelf();

        $this->cardMock->method('setAdditional')
            ->willReturnSelf();

        $this->cardMock->method('getAdditional')
            ->willReturn([]);

        $this->helperMock->method('mapCcTypeToMagento')
            ->willReturn('VI');

        $this->cardRepositoryMock->expects($this->once())
            ->method('save')
            ->with($this->cardMock);

        $this->customerProfile->importPaymentProfile($this->cardMock, $paymentProfile);
    }

    public function testSetPaymentProfileDataExtractsCcType(): void
    {
        $paymentProfile = [
            'payment' => ['creditCard' => [
                'cardNumber' => 'XXXX1234',
                'expirationDate' => '2030-06',
                'cardType' => 'MasterCard',
            ]],
        ];

        $this->helperMock->expects($this->once())
            ->method('mapCcTypeToMagento')
            ->with('MasterCard')
            ->willReturn('MC');

        $this->cardMock->method('setData')
            ->willReturnSelf();

        $capturedData = [];
        $this->cardMock->method('setAdditional')
            ->willReturnCallback(function ($data) use (&$capturedData) {
                $capturedData = $data;

                return $this->cardMock;
            });

        $this->cardMock->method('getAdditional')
            ->willReturn([]);

        $this->customerProfile->setPaymentProfileDataOnCard($paymentProfile, $this->cardMock);

        $this->assertSame('MC', $capturedData['cc_type']);
    }

    public function testSetPaymentProfileDataExtractsLast4(): void
    {
        $paymentProfile = [
            'payment' => ['creditCard' => [
                'cardNumber' => 'XXXX5678',
                'expirationDate' => '2030-06',
                'cardType' => 'Visa',
            ]],
        ];

        $this->helperMock->method('mapCcTypeToMagento')
            ->willReturn('VI');

        $this->cardMock->method('setData')
            ->willReturnSelf();

        $capturedData = [];
        $this->cardMock->method('setAdditional')
            ->willReturnCallback(function ($data) use (&$capturedData) {
                $capturedData = $data;

                return $this->cardMock;
            });

        $this->cardMock->method('getAdditional')
            ->willReturn([]);

        $this->customerProfile->setPaymentProfileDataOnCard($paymentProfile, $this->cardMock);

        $this->assertSame('5678', $capturedData['cc_last4']);
    }

    public function testSetPaymentProfileDataExtractsExpiration(): void
    {
        $paymentProfile = [
            'payment' => ['creditCard' => [
                'cardNumber' => 'XXXX9999',
                'expirationDate' => '2032-11',
                'cardType' => 'Visa',
            ]],
        ];

        $this->helperMock->method('mapCcTypeToMagento')
            ->willReturn('VI');

        $expiresSet = null;
        $this->cardMock->method('setData')
            ->willReturnCallback(function ($key, $value = null) use (&$expiresSet) {
                if ($key === 'expires') {
                    $expiresSet = $value;
                }

                return $this->cardMock;
            });

        $capturedData = [];
        $this->cardMock->method('setAdditional')
            ->willReturnCallback(function ($data) use (&$capturedData) {
                $capturedData = $data;

                return $this->cardMock;
            });

        $this->cardMock->method('getAdditional')
            ->willReturn([]);

        $this->customerProfile->setPaymentProfileDataOnCard($paymentProfile, $this->cardMock);

        $this->assertSame('2032', $capturedData['cc_exp_year']);
        $this->assertSame('11', $capturedData['cc_exp_month']);
        $this->assertStringContainsString('2032-11-', $expiresSet);
        $this->assertStringContainsString('23:59:59', $expiresSet);
    }

    public function testSetPaymentProfileDataHandlesBankAccount(): void
    {
        $paymentProfile = [
            'payment' => ['bankAccount' => [
                'accountType' => 'savings',
                'nameOnAccount' => 'John Doe',
                'bankName' => 'Test Bank',
                'routingNumber' => 'XXXX6789',
                'accountNumber' => 'XXXX4321',
                'echeckType' => 'PPD',
            ]],
        ];

        $this->cardMock->method('setData')
            ->willReturnSelf();

        $capturedData = [];
        $this->cardMock->method('setAdditional')
            ->willReturnCallback(function ($data) use (&$capturedData) {
                $capturedData = $data;

                return $this->cardMock;
            });

        $this->cardMock->method('getAdditional')
            ->willReturn([]);

        $this->customerProfile->setPaymentProfileDataOnCard($paymentProfile, $this->cardMock);

        $this->assertSame('savings', $capturedData['echeck_account_type']);
        $this->assertSame('John Doe', $capturedData['echeck_account_name']);
        $this->assertSame('Test Bank', $capturedData['echeck_bank_name']);
        $this->assertSame('6789', $capturedData['echeck_routing_number_last4']);
        $this->assertSame('4321', $capturedData['echeck_account_number_last4']);
        $this->assertSame('PPD', $capturedData['echeck_type']);
        $this->assertSame('4321', $capturedData['cc_last4']);
    }

    public function testSetPaymentProfileDataExtractsBin(): void
    {
        $paymentProfile = [
            'payment' => ['creditCard' => [
                'cardNumber' => 'XXXX1234',
                'expirationDate' => '2030-06',
                'cardType' => 'Visa',
                'issuerNumber' => '411111',
            ]],
        ];

        $this->helperMock->method('mapCcTypeToMagento')
            ->willReturn('VI');

        $this->cardMock->method('setData')
            ->willReturnSelf();

        $capturedData = [];
        $this->cardMock->method('setAdditional')
            ->willReturnCallback(function ($data) use (&$capturedData) {
                $capturedData = $data;

                return $this->cardMock;
            });

        $this->cardMock->method('getAdditional')
            ->willReturn([]);

        $this->customerProfile->setPaymentProfileDataOnCard($paymentProfile, $this->cardMock);

        $this->assertSame('411111', $capturedData['cc_bin']);
    }

    public function testSetPaymentProfileDataPreservesExistingAdditional(): void
    {
        $paymentProfile = [
            'payment' => ['creditCard' => [
                'cardNumber' => 'XXXX1234',
                'expirationDate' => '2030-06',
                'cardType' => 'Visa',
            ]],
        ];

        $this->helperMock->method('mapCcTypeToMagento')
            ->willReturn('VI');

        $this->cardMock->method('setData')
            ->willReturnSelf();

        $capturedData = [];
        $this->cardMock->method('setAdditional')
            ->willReturnCallback(function ($data) use (&$capturedData) {
                $capturedData = $data;

                return $this->cardMock;
            });

        $this->cardMock->method('getAdditional')
            ->willReturn(['existing_key' => 'existing_value']);

        $this->customerProfile->setPaymentProfileDataOnCard($paymentProfile, $this->cardMock);

        $this->assertSame('existing_value', $capturedData['existing_key']);
        $this->assertSame('VI', $capturedData['cc_type']);
    }
}
