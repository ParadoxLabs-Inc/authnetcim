<?php

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Test\Unit\Model\Service\AcceptHosted;

use Exception;
use Magento\Backend\Model\Session\Quote as BackendQuoteSession;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Payment;
use Magento\Quote\Model\ResourceModel\Quote\Payment as PaymentResource;
use ParadoxLabs\Authnetcim\Helper\Data;
use ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider as ConfigProviderAch;
use ParadoxLabs\Authnetcim\Model\ConfigProvider as ConfigProviderCc;
use ParadoxLabs\Authnetcim\Model\Gateway;
use ParadoxLabs\Authnetcim\Model\Method;
use ParadoxLabs\Authnetcim\Model\Service\AcceptHosted\BackendRequest;
use ParadoxLabs\Authnetcim\Model\Service\AcceptHosted\Context;
use ParadoxLabs\TokenBase\Helper\Address as AddressHelper;
use ParadoxLabs\TokenBase\Model\Method\Factory as MethodFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class BackendRequestTest extends TestCase
{
    private BackendRequest $backendRequest;
    private Context|MockObject $contextMock;
    private BackendQuoteSession|MockObject $backendSessionMock;
    private HttpRequest|MockObject $requestMock;
    private PaymentResource|MockObject $paymentResourceMock;
    private Data|MockObject $helperMock;
    private MethodFactory|MockObject $methodFactoryMock;
    private CartRepositoryInterface|MockObject $quoteRepositoryMock;
    private AddressHelper|MockObject $addressHelperMock;

    protected function setUp(): void
    {
        $this->helperMock = $this->createMock(Data::class);
        $this->methodFactoryMock = $this->createMock(MethodFactory::class);
        $this->quoteRepositoryMock = $this->createMock(CartRepositoryInterface::class);
        $this->addressHelperMock = $this->createMock(AddressHelper::class);

        $this->contextMock = $this->createMock(Context::class);
        $this->contextMock->method('getHelper')
            ->willReturn($this->helperMock);
        $this->contextMock->method('getMethodFactory')
            ->willReturn($this->methodFactoryMock);
        $this->contextMock->method('getQuoteRepository')
            ->willReturn($this->quoteRepositoryMock);
        $this->contextMock->method('getAddressHelper')
            ->willReturn($this->addressHelperMock);

        $this->backendSessionMock = $this->createMock(BackendQuoteSession::class);
        $this->requestMock = $this->getMockBuilder(HttpRequest::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getParam', 'getPostValue'])
            ->getMock();
        $this->paymentResourceMock = $this->createMock(PaymentResource::class);

        $this->backendRequest = new BackendRequest(
            $this->contextMock,
            $this->backendSessionMock,
            $this->requestMock,
            $this->paymentResourceMock,
        );
    }

    public function testGetCustomerProfileIdReturnsExisting(): void
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
            ->willReturn('existing_profile_123');

        $quoteMock = $this->createMock(Quote::class);
        $quoteMock->method('getPayment')
            ->willReturn($paymentMock);

        $this->backendSessionMock->method('getQuote')
            ->willReturn($quoteMock);

        $result = $this->backendRequest->getCustomerProfileId();

        $this->assertSame('existing_profile_123', $result);
    }

    public function testGetCustomerProfileIdCreatesNew(): void
    {
        $paymentMock = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'hasAdditionalInformation',
                'getAdditionalInformation',
                'setAdditionalInformation',
            ])
            ->getMock();
        $paymentMock->method('hasAdditionalInformation')
            ->with('profile_id')
            ->willReturn(false);
        $paymentMock->expects($this->once())
            ->method('setAdditionalInformation')
            ->with('profile_id', 'new_profile_456');

        $billingAddressMock = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEmail'])
            ->getMock();
        $billingAddressMock->method('getEmail')
            ->willReturn('test@example.com');

        $quoteMock = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPayment', 'getBillingAddress', 'getStoreId'])
            ->getMock();
        $quoteMock->method('getPayment')
            ->willReturn($paymentMock);
        $quoteMock->method('getBillingAddress')
            ->willReturn($billingAddressMock);
        $quoteMock->method('getStoreId')
            ->willReturn(1);

        $this->backendSessionMock->method('getQuote')
            ->willReturn($quoteMock);

        $customerMock = $this->createMock(CustomerInterface::class);
        $customerMock->method('getId')
            ->willReturn(100);

        $this->helperMock->method('getCurrentCustomer')
            ->willReturn($customerMock);

        $gatewayMock = $this->createMock(Gateway::class);
        $gatewayMock->method('setParameter')
            ->willReturnSelf();
        $gatewayMock->method('createCustomerProfile')
            ->willReturn('new_profile_456');

        $methodMock = $this->createMock(Method::class);
        $methodMock->method('gateway')
            ->willReturn($gatewayMock);
        $methodMock->method('setStore')
            ->willReturnSelf();

        $this->methodFactoryMock->method('getMethodInstance')
            ->willReturn($methodMock);

        $this->paymentResourceMock->expects($this->once())
            ->method('save')
            ->with($paymentMock);

        $result = $this->backendRequest->getCustomerProfileId();

        $this->assertSame('new_profile_456', $result);
    }

    public function testGetEmailReturnsFromBillingAddress(): void
    {
        $billingAddressMock = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEmail'])
            ->getMock();
        $billingAddressMock->method('getEmail')
            ->willReturn('billing@example.com');

        $quoteMock = $this->createMock(Quote::class);
        $quoteMock->method('getBillingAddress')
            ->willReturn($billingAddressMock);

        $this->backendSessionMock->method('getQuote')
            ->willReturn($quoteMock);

        $result = $this->backendRequest->getEmail();

        $this->assertSame('billing@example.com', $result);
    }

    public function testGetEmailReturnsNullOnException(): void
    {
        $this->backendSessionMock->method('getQuote')
            ->willThrowException(new Exception('Session error'));

        $result = $this->backendRequest->getEmail();

        $this->assertNull($result);
    }

    public function testGetCustomerIdReturnsFromHelper(): void
    {
        $customerMock = $this->createMock(CustomerInterface::class);
        $customerMock->method('getId')
            ->willReturn(123);

        $this->helperMock->method('getCurrentCustomer')
            ->willReturn($customerMock);

        $result = $this->backendRequest->getCustomerId();

        $this->assertSame('123', $result);
    }

    public function testGetStoreIdReturnsFromQuote(): void
    {
        $quoteMock = $this->createMock(Quote::class);
        $quoteMock->method('getStoreId')
            ->willReturn(5);

        $this->backendSessionMock->method('getQuote')
            ->willReturn($quoteMock);

        $reflection = new ReflectionMethod($this->backendRequest, 'getStoreId');

        $result = $reflection->invoke($this->backendRequest);

        $this->assertSame(5, $result);
    }

    public function testGetStoreIdFallsBackToHelper(): void
    {
        $this->backendSessionMock->method('getQuote')
            ->willThrowException(new Exception('Session error'));

        $this->helperMock->method('getCurrentStoreId')
            ->willReturn(3);

        $reflection = new ReflectionMethod($this->backendRequest, 'getStoreId');

        $result = $reflection->invoke($this->backendRequest);

        $this->assertSame(3, $result);
    }

    public function testGetMethodCodeReturnsValidCode(): void
    {
        $this->requestMock->method('getParam')
            ->with('method')
            ->willReturn(ConfigProviderCc::CODE);

        $reflection = new ReflectionMethod($this->backendRequest, 'getMethodCode');

        $result = $reflection->invoke($this->backendRequest);

        $this->assertSame(ConfigProviderCc::CODE, $result);
    }

    public function testGetMethodCodeReturnsAchCode(): void
    {
        $this->requestMock->method('getParam')
            ->with('method')
            ->willReturn(ConfigProviderAch::CODE);

        $reflection = new ReflectionMethod($this->backendRequest, 'getMethodCode');

        $result = $reflection->invoke($this->backendRequest);

        $this->assertSame(ConfigProviderAch::CODE, $result);
    }

    public function testGetMethodCodeDefaultsToAuthnetcim(): void
    {
        $this->requestMock->method('getParam')
            ->with('method')
            ->willReturn('invalid_method');

        $reflection = new ReflectionMethod($this->backendRequest, 'getMethodCode');

        $result = $reflection->invoke($this->backendRequest);

        $this->assertSame(ConfigProviderCc::CODE, $result);
    }

    public function testGetMethodCodeUsesSetMethodCode(): void
    {
        $this->backendRequest->setMethodCode(ConfigProviderAch::CODE);

        $reflection = new ReflectionMethod($this->backendRequest, 'getMethodCode');

        $result = $reflection->invoke($this->backendRequest);

        $this->assertSame(ConfigProviderAch::CODE, $result);
    }

    public function testSetBillingParamsPrioritizesPostData(): void
    {
        $postData = [
            'firstname' => 'John',
            'lastname' => 'Doe',
            'street' => ['123 Main St'],
            'city' => 'Anytown',
            'countryId' => 'US',
            'regionId' => '12',
            'regionCode' => 'CA',
            'postcode' => '90210',
        ];

        $this->requestMock->method('getPostValue')
            ->with('billing')
            ->willReturn($postData);

        $builtAddressMock = $this->createMock(AddressInterface::class);
        $this->addressHelperMock->expects($this->once())
            ->method('buildAddressFromInput')
            ->willReturn($builtAddressMock);

        $gatewayMock = $this->createMock(Gateway::class);
        $gatewayMock->expects($this->once())
            ->method('setBillTo')
            ->with($builtAddressMock);

        $reflection = new ReflectionMethod($this->backendRequest, 'setBillingParams');

        $reflection->invoke($this->backendRequest, $gatewayMock);
    }

    public function testSetBillingParamsUsesQuoteBillingAddress(): void
    {
        $this->requestMock->method('getPostValue')
            ->with('billing')
            ->willReturn(null);

        $dataModelMock = $this->createMock(\Magento\Customer\Api\Data\AddressInterface::class);

        $billingAddressMock = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getDataModel'])
            ->getMock();
        $billingAddressMock->method('getDataModel')
            ->willReturn($dataModelMock);

        $quoteMock = $this->createMock(Quote::class);
        $quoteMock->method('getBillingAddress')
            ->willReturn($billingAddressMock);

        $this->backendSessionMock->method('getQuote')
            ->willReturn($quoteMock);

        $gatewayMock = $this->createMock(Gateway::class);
        $gatewayMock->expects($this->once())
            ->method('setBillTo')
            ->with($dataModelMock);

        $reflection = new ReflectionMethod($this->backendRequest, 'setBillingParams');

        $reflection->invoke($this->backendRequest, $gatewayMock);
    }
}
