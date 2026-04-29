<?php

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Test\Unit\Model\Service\AcceptCustomer;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Payment;
use Magento\Quote\Model\ResourceModel\Quote\Payment as PaymentResource;
use ParadoxLabs\Authnetcim\Helper\Data;
use ParadoxLabs\Authnetcim\Model\ConfigProvider as ConfigProviderCc;
use ParadoxLabs\Authnetcim\Model\Service\AcceptCustomer\Context;
use ParadoxLabs\Authnetcim\Model\Service\AcceptCustomer\FrontendRequest;
use ParadoxLabs\TokenBase\Api\CardRepositoryInterface;
use ParadoxLabs\TokenBase\Api\Data\CardInterface;
use ParadoxLabs\TokenBase\Model\Method\Factory as MethodFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class FrontendRequestTest extends TestCase
{
    private FrontendRequest $frontendRequest;
    private Context|MockObject $contextMock;
    private CheckoutSession|MockObject $checkoutSessionMock;
    private CustomerSession|MockObject $customerSessionMock;
    private RequestInterface|MockObject $requestMock;
    private PaymentResource|MockObject $paymentResourceMock;
    private Data|MockObject $helperMock;
    private MethodFactory|MockObject $methodFactoryMock;
    private CardRepositoryInterface|MockObject $cardRepositoryMock;

    protected function setUp(): void
    {
        $this->helperMock = $this->createMock(Data::class);
        $this->methodFactoryMock = $this->createMock(MethodFactory::class);
        $this->cardRepositoryMock = $this->createMock(CardRepositoryInterface::class);

        $this->contextMock = $this->createMock(Context::class);
        $this->contextMock->method('getHelper')
            ->willReturn($this->helperMock);
        $this->contextMock->method('getMethodFactory')
            ->willReturn($this->methodFactoryMock);
        $this->contextMock->method('getCardRepository')
            ->willReturn($this->cardRepositoryMock);

        $this->checkoutSessionMock = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuote', 'getQuoteId'])
            ->getMock();

        $this->customerSessionMock = $this->createMock(CustomerSession::class);

        $this->requestMock = $this->createMock(RequestInterface::class);
        $this->paymentResourceMock = $this->createMock(PaymentResource::class);

        $this->frontendRequest = new FrontendRequest(
            $this->contextMock,
            $this->checkoutSessionMock,
            $this->customerSessionMock,
            $this->requestMock,
            $this->paymentResourceMock,
        );
    }

    public function testGetCustomerProfileIdReturnsFromPayment(): void
    {
        $paymentMock = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['hasAdditionalInformation', 'getAdditionalInformation'])
            ->getMock();
        $paymentMock->method('hasAdditionalInformation')
            ->with('profile_id')
            ->willReturn(true);
        $paymentMock->method('getAdditionalInformation')
            ->with('profile_id')
            ->willReturn('payment_profile_123');

        $quoteMock = $this->createMock(Quote::class);
        $quoteMock->method('getPayment')
            ->willReturn($paymentMock);

        $this->checkoutSessionMock->method('getQuote')
            ->willReturn($quoteMock);

        $this->requestMock->method('getParam')
            ->willReturn(null);

        $result = $this->frontendRequest->getCustomerProfileId();

        $this->assertSame('payment_profile_123', $result);
    }

    public function testGetCustomerProfileIdLoadsFromCard(): void
    {
        $this->requestMock->method('getParam')
            ->willReturnCallback(function ($key) {
                if ($key === 'source') {
                    return 'paymentinfo';
                }

                if ($key === 'card_id') {
                    return 'card_hash_123';
                }

                return null;
            });

        $quoteMock = $this->createMock(Quote::class);
        $this->checkoutSessionMock->method('getQuote')
            ->willReturn($quoteMock);
        $this->checkoutSessionMock->method('getQuoteId')
            ->willReturn(null);

        $this->customerSessionMock->method('getCustomerId')
            ->willReturn(100);

        $cardMock = $this->createMock(CardInterface::class);
        $cardMock->method('hasOwner')
            ->with(100)
            ->willReturn(true);
        $cardMock->method('getProfileId')
            ->willReturn('card_profile_456');

        $this->cardRepositoryMock->method('getByHash')
            ->with('card_hash_123')
            ->willReturn($cardMock);

        $result = $this->frontendRequest->getCustomerProfileId();

        $this->assertSame('card_profile_456', $result);
    }

    public function testGetCustomerProfileIdThrowsForUnownedCard(): void
    {
        $this->requestMock->method('getParam')
            ->willReturnCallback(function ($key) {
                if ($key === 'source') {
                    return 'paymentinfo';
                }

                if ($key === 'card_id') {
                    return 'card_hash_123';
                }

                return null;
            });

        $quoteMock = $this->createMock(Quote::class);
        $this->checkoutSessionMock->method('getQuote')
            ->willReturn($quoteMock);
        $this->checkoutSessionMock->method('getQuoteId')
            ->willReturn(null);

        $this->customerSessionMock->method('getCustomerId')
            ->willReturn(100);

        $cardMock = $this->createMock(CardInterface::class);
        $cardMock->method('hasOwner')
            ->with(100)
            ->willReturn(false);

        $this->cardRepositoryMock->method('getByHash')
            ->with('card_hash_123')
            ->willReturn($cardMock);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Could not load payment profile');

        $this->frontendRequest->getCustomerProfileId();
    }

    public function testGetCustomerPaymentIdReturnsForPaymentInfo(): void
    {
        $this->requestMock->method('getParam')
            ->willReturnCallback(function ($key) {
                if ($key === 'source') {
                    return 'paymentinfo';
                }

                if ($key === 'card_id') {
                    return 'card_hash_123';
                }

                return null;
            });

        $this->checkoutSessionMock->method('getQuoteId')
            ->willReturn(null);

        $this->customerSessionMock->method('getCustomerId')
            ->willReturn(100);

        $cardMock = $this->createMock(CardInterface::class);
        $cardMock->method('hasOwner')
            ->with(100)
            ->willReturn(true);
        $cardMock->method('getPaymentId')
            ->willReturn('payment_id_789');

        $this->cardRepositoryMock->method('getByHash')
            ->with('card_hash_123')
            ->willReturn($cardMock);

        $result = $this->frontendRequest->getCustomerPaymentId();

        $this->assertSame('payment_id_789', $result);
    }

    public function testGetCustomerPaymentIdReturnsNullForCheckout(): void
    {
        $this->requestMock->method('getParam')
            ->willReturnCallback(function ($key) {
                if ($key === 'source') {
                    return 'checkout';
                }

                return null;
            });

        $result = $this->frontendRequest->getCustomerPaymentId();

        $this->assertNull($result);
    }

    public function testGetEmailReturnsFromCustomerForPaymentInfo(): void
    {
        $this->requestMock->method('getParam')
            ->willReturnCallback(function ($key) {
                if ($key === 'source') {
                    return 'paymentinfo';
                }

                return null;
            });

        $customerDataMock = $this->createMock(CustomerInterface::class);
        $customerDataMock->method('getEmail')
            ->willReturn('customer@example.com');

        $this->customerSessionMock->method('getCustomerData')
            ->willReturn($customerDataMock);

        $result = $this->frontendRequest->getEmail();

        $this->assertSame('customer@example.com', $result);
    }

    public function testGetEmailReturnsFromQuoteForCheckout(): void
    {
        $this->requestMock->method('getParam')
            ->willReturnCallback(function ($key) {
                if ($key === 'source') {
                    return 'checkout';
                }

                return null;
            });

        $billingAddressMock = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEmail'])
            ->getMock();
        $billingAddressMock->method('getEmail')
            ->willReturn('billing@example.com');

        $quoteMock = $this->createMock(Quote::class);
        $quoteMock->method('getBillingAddress')
            ->willReturn($billingAddressMock);

        $this->checkoutSessionMock->method('getQuote')
            ->willReturn($quoteMock);

        $result = $this->frontendRequest->getEmail();

        $this->assertSame('billing@example.com', $result);
    }

    public function testGetEmailReturnsGuestEmailFallback(): void
    {
        $billingAddressMock = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEmail'])
            ->getMock();
        $billingAddressMock->method('getEmail')
            ->willReturn(null);

        $quoteMock = $this->createMock(Quote::class);
        $quoteMock->method('getBillingAddress')
            ->willReturn($billingAddressMock);

        $this->checkoutSessionMock->method('getQuote')
            ->willReturn($quoteMock);

        $this->requestMock->method('getParam')
            ->willReturnCallback(function ($key) {
                if ($key === 'source') {
                    return 'checkout';
                }

                if ($key === 'guest_email') {
                    return 'guest@example.com';
                }

                return null;
            });

        $result = $this->frontendRequest->getEmail();

        $this->assertSame('guest@example.com', $result);
    }

    public function testGetCustomerIdReturnsFromQuote(): void
    {
        $quoteMock = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->addMethods(['getCustomerId'])
            ->getMock();
        $quoteMock->method('getCustomerId')
            ->willReturn(123);

        $checkoutSessionMock = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuoteId', 'getQuote'])
            ->getMock();
        $checkoutSessionMock->method('getQuoteId')
            ->willReturn(1);
        $checkoutSessionMock->method('getQuote')
            ->willReturn($quoteMock);

        $frontendRequest = new FrontendRequest(
            $this->contextMock,
            $checkoutSessionMock,
            $this->customerSessionMock,
            $this->requestMock,
            $this->paymentResourceMock,
        );

        $result = $frontendRequest->getCustomerId();

        $this->assertSame('123', $result);
    }

    public function testGetCustomerIdFallsBackToSession(): void
    {
        $customerSessionMock = $this->getMockBuilder(CustomerSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCustomerId'])
            ->getMock();
        $customerSessionMock->method('getCustomerId')
            ->willReturn(456);

        $checkoutSessionMock = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuoteId'])
            ->getMock();
        $checkoutSessionMock->method('getQuoteId')
            ->willReturn(null);

        $frontendRequest = new FrontendRequest(
            $this->contextMock,
            $checkoutSessionMock,
            $customerSessionMock,
            $this->requestMock,
            $this->paymentResourceMock,
        );

        $result = $frontendRequest->getCustomerId();

        $this->assertSame('456', $result);
    }

    public function testGetMethodCodeReturnsValidCode(): void
    {
        $this->requestMock->method('getParam')
            ->willReturnCallback(function ($key) {
                if ($key === 'method') {
                    return ConfigProviderCc::CODE;
                }

                return null;
            });

        $reflection = new ReflectionMethod($this->frontendRequest, 'getMethodCode');
        $reflection->setAccessible(true);

        $result = $reflection->invoke($this->frontendRequest);

        $this->assertSame(ConfigProviderCc::CODE, $result);
    }

    public function testGetMethodCodeDefaultsToAuthnetcim(): void
    {
        $this->requestMock->method('getParam')
            ->willReturnCallback(function ($key) {
                if ($key === 'method') {
                    return 'invalid_method';
                }

                return null;
            });

        $reflection = new ReflectionMethod($this->frontendRequest, 'getMethodCode');
        $reflection->setAccessible(true);

        $result = $reflection->invoke($this->frontendRequest);

        $this->assertSame(ConfigProviderCc::CODE, $result);
    }

    public function testGetStoreIdReturnsFromHelper(): void
    {
        $this->helperMock->method('getCurrentStoreId')
            ->willReturn(5);

        $reflection = new ReflectionMethod($this->frontendRequest, 'getStoreId');
        $reflection->setAccessible(true);

        $result = $reflection->invoke($this->frontendRequest);

        $this->assertSame(5, $result);
    }

    public function testSaveCardToQuoteSkipsPaymentInfo(): void
    {
        $this->requestMock->method('getParam')
            ->willReturnCallback(function ($key) {
                if ($key === 'source') {
                    return 'paymentinfo';
                }

                return null;
            });

        $cardMock = $this->createMock(CardInterface::class);

        $this->paymentResourceMock->expects($this->never())
            ->method('save');

        $reflection = new ReflectionMethod($this->frontendRequest, 'saveCardToQuote');
        $reflection->setAccessible(true);

        $reflection->invoke($this->frontendRequest, $cardMock);
    }

    public function testClearProfileIdForPaymentInfo(): void
    {
        $requestMock = $this->createMock(RequestInterface::class);
        $requestMock->method('getParam')
            ->willReturnCallback(function ($key) {
                if ($key === 'source') {
                    return 'paymentinfo';
                }

                return null;
            });

        $customerSessionMock = $this->getMockBuilder(CustomerSession::class)
            ->disableOriginalConstructor()
            ->addMethods(['unsetData'])
            ->getMock();
        $customerSessionMock->expects($this->once())
            ->method('unsetData')
            ->with('authnetcim_profile_id');

        $frontendRequest = new FrontendRequest(
            $this->contextMock,
            $this->checkoutSessionMock,
            $customerSessionMock,
            $requestMock,
            $this->paymentResourceMock,
        );

        $reflection = new ReflectionMethod($frontendRequest, 'clearProfileId');
        $reflection->setAccessible(true);

        $reflection->invoke($frontendRequest);
    }

    public function testClearProfileIdForCheckout(): void
    {
        $this->requestMock->method('getParam')
            ->willReturnCallback(function ($key) {
                if ($key === 'source') {
                    return 'checkout';
                }

                return null;
            });

        $paymentMock = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['unsAdditionalInformation'])
            ->getMock();
        $paymentMock->expects($this->once())
            ->method('unsAdditionalInformation')
            ->with('profile_id');

        $quoteMock = $this->createMock(Quote::class);
        $quoteMock->method('getPayment')
            ->willReturn($paymentMock);

        $this->checkoutSessionMock->method('getQuote')
            ->willReturn($quoteMock);

        $reflection = new ReflectionMethod($this->frontendRequest, 'clearProfileId');
        $reflection->setAccessible(true);

        $reflection->invoke($this->frontendRequest);
    }
}
