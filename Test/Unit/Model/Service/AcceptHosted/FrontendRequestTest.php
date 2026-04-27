<?php

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Test\Unit\Model\Service\AcceptHosted;

use Magento\Framework\App\Request\Http;
use ParadoxLabs\Authnetcim\Model\Method;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Url;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ResourceModel\Quote\Payment as PaymentResource;
use ParadoxLabs\Authnetcim\Helper\Data;
use ParadoxLabs\Authnetcim\Model\ConfigProvider;
use ParadoxLabs\Authnetcim\Model\Service\AcceptHosted\Context;
use ParadoxLabs\Authnetcim\Model\Service\AcceptHosted\FrontendRequest;
use ParadoxLabs\TokenBase\Helper\Address as AddressHelper;
use ParadoxLabs\TokenBase\Model\Method\Factory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FrontendRequestTest extends TestCase
{
    private FrontendRequest $frontendRequest;
    private Context|MockObject $contextMock;
    private CheckoutSession|MockObject $checkoutSessionMock;
    private CustomerSession|MockObject $customerSessionMock;
    private RequestInterface|MockObject $requestMock;
    private PaymentResource|MockObject $paymentResourceMock;
    private Url|MockObject $urlBuilderMock;
    private Factory|MockObject $methodFactoryMock;
    private Data|MockObject $helperMock;
    private CartRepositoryInterface|MockObject $quoteRepositoryMock;
    private AddressHelper|MockObject $addressHelperMock;

    protected function setUp(): void
    {
        $this->contextMock = $this->createMock(Context::class);
        $this->checkoutSessionMock = $this->createMock(CheckoutSession::class);
        $this->customerSessionMock = $this->createMock(CustomerSession::class);
        $this->requestMock = $this->createMock(Http::class);
        $this->paymentResourceMock = $this->createMock(PaymentResource::class);

        $this->urlBuilderMock = $this->createMock(Url::class);
        $this->methodFactoryMock = $this->createMock(Factory::class);
        $this->helperMock = $this->createMock(Data::class);
        $this->quoteRepositoryMock = $this->createMock(CartRepositoryInterface::class);
        $this->addressHelperMock = $this->createMock(AddressHelper::class);

        $this->contextMock->method('getUrlBuilder')
            ->willReturn($this->urlBuilderMock);
        $this->contextMock->method('getMethodFactory')
            ->willReturn($this->methodFactoryMock);
        $this->contextMock->method('getHelper')
            ->willReturn($this->helperMock);
        $this->contextMock->method('getQuoteRepository')
            ->willReturn($this->quoteRepositoryMock);
        $this->contextMock->method('getAddressHelper')
            ->willReturn($this->addressHelperMock);

        $this->frontendRequest = new FrontendRequest(
            $this->contextMock,
            $this->checkoutSessionMock,
            $this->customerSessionMock,
            $this->requestMock,
            $this->paymentResourceMock,
        );
    }

    public function testGetOrderIncrementIdReservesIdWhenEmpty(): void
    {
        $quoteMock = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['reserveOrderId', 'getReservedOrderId'])
            ->getMock();

        $callCount = 0;
        $quoteMock->method('getReservedOrderId')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                // First call returns empty, subsequent calls return the reserved ID
                if ($callCount === 1) {
                    return '';
                }

                return '100000001';
            });

        $quoteMock->expects($this->once())
            ->method('reserveOrderId');

        $this->quoteRepositoryMock->expects($this->once())
            ->method('save')
            ->with($quoteMock);

        $result = $this->frontendRequest->getOrderIncrementId($quoteMock);

        $this->assertSame('100000001', $result);
    }

    public function testGetOrderIncrementIdUsesExistingId(): void
    {
        $quoteMock = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['reserveOrderId', 'getReservedOrderId'])
            ->getMock();

        $quoteMock->method('getReservedOrderId')
            ->willReturn('100000002');

        $quoteMock->expects($this->never())
            ->method('reserveOrderId');

        $this->quoteRepositoryMock->expects($this->never())
            ->method('save');

        $result = $this->frontendRequest->getOrderIncrementId($quoteMock);

        $this->assertSame('100000002', $result);
    }

    public function testGetCustomerIdReturnsFromQuoteWhenSessionHasQuote(): void
    {
        $quoteMock = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->addMethods(['getCustomerId'])
            ->getMock();

        $quoteMock->method('getCustomerId')
            ->willReturn(123);

        $this->checkoutSessionMock->method('getQuoteId')
            ->willReturn(1);

        $this->checkoutSessionMock->method('getQuote')
            ->willReturn($quoteMock);

        $result = $this->frontendRequest->getCustomerId();

        $this->assertSame('123', $result);
    }

    public function testGetCustomerIdReturnsFromCustomerSessionWhenNoQuote(): void
    {
        $this->checkoutSessionMock->method('getQuoteId')
            ->willReturn(null);

        $this->customerSessionMock->method('getCustomerId')
            ->willReturn(456);

        $result = $this->frontendRequest->getCustomerId();

        $this->assertSame('456', $result);
    }

    public function testSetMethodCodeSetsCode(): void
    {
        $this->helperMock->method('getCurrentStoreId')
            ->willReturn(1);

        $methodMock = $this->createMock(Method::class);

        // Expect ACH code when setMethodCode sets it
        $this->methodFactoryMock->expects($this->once())
            ->method('getMethodInstance')
            ->with('authnetcim_ach')
            ->willReturn($methodMock);

        $this->frontendRequest->setMethodCode('authnetcim_ach');
        $this->frontendRequest->getMethod();
    }

    public function testGetMethodCodeDefaultsToAuthnetcimCc(): void
    {
        $this->helperMock->method('getCurrentStoreId')
            ->willReturn(1);

        $this->requestMock->method('getParam')
            ->willReturn(null);

        $methodMock = $this->createMock(Method::class);

        $this->methodFactoryMock->expects($this->once())
            ->method('getMethodInstance')
            ->with(ConfigProvider::CODE)
            ->willReturn($methodMock);

        $this->frontendRequest->getMethod();
    }

    public function testGetMethodCodeUsesAuthnetcimFromRequest(): void
    {
        $this->helperMock->method('getCurrentStoreId')
            ->willReturn(1);

        $this->requestMock->method('getParam')
            ->willReturnCallback(function ($key) {
                if ($key === 'method') {
                    return ConfigProvider::CODE;
                }

                return null;
            });

        $methodMock = $this->createMock(Method::class);

        $this->methodFactoryMock->expects($this->once())
            ->method('getMethodInstance')
            ->with(ConfigProvider::CODE)
            ->willReturn($methodMock);

        $this->frontendRequest->getMethod();
    }

    public function testGetMethodReturnsSameInstance(): void
    {
        $this->helperMock->method('getCurrentStoreId')
            ->willReturn(1);

        $this->requestMock->method('getParam')
            ->willReturn(ConfigProvider::CODE);

        $methodMock = $this->createMock(Method::class);

        $this->methodFactoryMock->expects($this->once())
            ->method('getMethodInstance')
            ->willReturn($methodMock);

        $result1 = $this->frontendRequest->getMethod();
        $result2 = $this->frontendRequest->getMethod();

        $this->assertSame($result1, $result2);
    }
}
