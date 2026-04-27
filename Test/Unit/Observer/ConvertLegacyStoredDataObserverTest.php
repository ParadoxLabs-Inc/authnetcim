<?php

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Test\Unit\Observer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Customer;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Api\AttributeInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use ParadoxLabs\Authnetcim\Helper\Data;
use ParadoxLabs\Authnetcim\Model\Gateway;
use ParadoxLabs\Authnetcim\Model\Method;
use ParadoxLabs\Authnetcim\Observer\ConvertLegacyStoredDataObserver;
use ParadoxLabs\TokenBase\Api\CardRepositoryInterface;
use ParadoxLabs\TokenBase\Api\Data\CardSearchResultsInterface;
use ParadoxLabs\TokenBase\Model\CardFactory;
use ParadoxLabs\TokenBase\Model\Method\Factory as MethodFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConvertLegacyStoredDataObserverTest extends TestCase
{
    private ConvertLegacyStoredDataObserver $observer;
    private Data|MockObject $helperMock;
    private MethodFactory|MockObject $methodFactoryMock;
    private CardFactory|MockObject $cardFactoryMock;
    private CollectionFactory|MockObject $orderCollectionFactoryMock;
    private RegionFactory|MockObject $regionFactoryMock;
    private CustomerRepositoryInterface|MockObject $customerRepositoryMock;
    private OrderPaymentRepositoryInterface|MockObject $paymentRepositoryMock;
    private CardRepositoryInterface|MockObject $cardRepositoryMock;
    private SearchCriteriaBuilder|MockObject $searchCriteriaBuilderMock;

    protected function setUp(): void
    {
        $this->helperMock = $this->createMock(Data::class);
        $this->methodFactoryMock = $this->createMock(MethodFactory::class);
        $this->cardFactoryMock = $this->createMock(CardFactory::class);
        $this->orderCollectionFactoryMock = $this->createMock(CollectionFactory::class);
        $this->regionFactoryMock = $this->createMock(RegionFactory::class);
        $this->customerRepositoryMock = $this->createMock(CustomerRepositoryInterface::class);
        $this->paymentRepositoryMock = $this->createMock(OrderPaymentRepositoryInterface::class);
        $this->cardRepositoryMock = $this->createMock(CardRepositoryInterface::class);
        $this->searchCriteriaBuilderMock = $this->createMock(SearchCriteriaBuilder::class);

        $this->observer = new ConvertLegacyStoredDataObserver(
            $this->helperMock,
            $this->methodFactoryMock,
            $this->cardFactoryMock,
            $this->orderCollectionFactoryMock,
            $this->regionFactoryMock,
            $this->customerRepositoryMock,
            $this->paymentRepositoryMock,
            $this->cardRepositoryMock,
            $this->searchCriteriaBuilderMock,
        );
    }

    public function testExecuteSkipsNonAuthnetcimMethod(): void
    {
        $eventMock = $this->createMock(Event::class);
        $eventMock->method('getData')
            ->willReturnCallback(function ($key) {
                if ($key === 'method') {
                    return 'other_method';
                }

                return null;
            });

        $observerMock = $this->createMock(Observer::class);
        $observerMock->method('getEvent')
            ->willReturn($eventMock);

        $this->methodFactoryMock->expects($this->never())
            ->method('getMethodInstance');

        $this->observer->execute($observerMock);
    }

    public function testExecuteSkipsNullMethod(): void
    {
        $eventMock = $this->createMock(Event::class);
        $eventMock->method('getData')
            ->willReturnCallback(function ($key) {
                if ($key === 'method') {
                    return null;
                }

                return null;
            });

        $observerMock = $this->createMock(Observer::class);
        $observerMock->method('getEvent')
            ->willReturn($eventMock);

        $this->methodFactoryMock->expects($this->never())
            ->method('getMethodInstance');

        $this->observer->execute($observerMock);
    }

    public function testExecuteSkipsInvalidCustomer(): void
    {
        $customerMock = $this->createMock(CustomerInterface::class);
        $customerMock->method('getId')
            ->willReturn(0);

        $eventMock = $this->createMock(Event::class);
        $eventMock->method('getData')
            ->willReturnCallback(function ($key) use ($customerMock) {
                if ($key === 'method') {
                    return 'authnetcim';
                }

                if ($key === 'customer') {
                    return $customerMock;
                }

                return null;
            });

        $observerMock = $this->createMock(Observer::class);
        $observerMock->method('getEvent')
            ->willReturn($eventMock);

        $this->methodFactoryMock->expects($this->never())
            ->method('getMethodInstance');

        $this->observer->execute($observerMock);
    }

    public function testExecuteConvertsCustomerModelToDataModel(): void
    {
        $customerDataMock = $this->createMock(CustomerInterface::class);
        $customerDataMock->method('getId')
            ->willReturn(0);

        $customerModelMock = $this->getMockBuilder(Customer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getDataModel'])
            ->getMock();
        $customerModelMock->method('getDataModel')
            ->willReturn($customerDataMock);

        $eventMock = $this->createMock(Event::class);
        $eventMock->method('getData')
            ->willReturnCallback(function ($key) use ($customerModelMock) {
                if ($key === 'method') {
                    return 'authnetcim';
                }

                if ($key === 'customer') {
                    return $customerModelMock;
                }

                return null;
            });

        $observerMock = $this->createMock(Observer::class);
        $observerMock->method('getEvent')
            ->willReturn($eventMock);

        $this->methodFactoryMock->expects($this->never())
            ->method('getMethodInstance');

        $this->observer->execute($observerMock);
    }

    public function testExecuteSkipsAlreadyConvertedProfile(): void
    {
        $profileIdAttr = $this->createMock(AttributeInterface::class);
        $profileIdAttr->method('getValue')
            ->willReturn('12345');

        $profileVersionAttr = $this->createMock(AttributeInterface::class);
        $profileVersionAttr->method('getValue')
            ->willReturn(200);

        $customerMock = $this->createMock(CustomerInterface::class);
        $customerMock->method('getId')
            ->willReturn(123);
        $customerMock->method('getCustomAttribute')
            ->willReturnCallback(function ($key) use ($profileIdAttr, $profileVersionAttr) {
                if ($key === 'authnetcim_profile_id') {
                    return $profileIdAttr;
                }

                if ($key === 'authnetcim_profile_version') {
                    return $profileVersionAttr;
                }

                return null;
            });

        $eventMock = $this->createMock(Event::class);
        $eventMock->method('getData')
            ->willReturnCallback(function ($key) use ($customerMock) {
                if ($key === 'method') {
                    return 'authnetcim';
                }

                if ($key === 'customer') {
                    return $customerMock;
                }

                return null;
            });

        $observerMock = $this->createMock(Observer::class);
        $observerMock->method('getEvent')
            ->willReturn($eventMock);

        $this->methodFactoryMock->expects($this->never())
            ->method('getMethodInstance');

        $this->observer->execute($observerMock);
    }

    public function testExecuteSkipsMissingProfileId(): void
    {
        $customerMock = $this->createMock(CustomerInterface::class);
        $customerMock->method('getId')
            ->willReturn(123);
        $customerMock->method('getCustomAttribute')
            ->willReturn(null);

        $eventMock = $this->createMock(Event::class);
        $eventMock->method('getData')
            ->willReturnCallback(function ($key) use ($customerMock) {
                if ($key === 'method') {
                    return 'authnetcim';
                }

                if ($key === 'customer') {
                    return $customerMock;
                }

                return null;
            });

        $observerMock = $this->createMock(Observer::class);
        $observerMock->method('getEvent')
            ->willReturn($eventMock);

        $this->methodFactoryMock->expects($this->never())
            ->method('getMethodInstance');

        $this->observer->execute($observerMock);
    }

    public function testGetCardsFromProfileHandlesSingleProfile(): void
    {
        $profile = [
            'profile' => [
                'paymentProfiles' => [
                    'billTo' => ['firstName' => 'Test'],
                    'customerPaymentProfileId' => '12345',
                ],
            ],
        ];

        $reflection = new \ReflectionMethod($this->observer, 'getCardsFromProfile');
        $reflection->setAccessible(true);

        $result = $reflection->invoke($this->observer, $profile);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('12345', $result);
        $this->assertSame('Test', $result['12345']['billTo']['firstName']);
    }

    public function testGetCardsFromProfileHandlesMultipleProfiles(): void
    {
        $profile = [
            'profile' => [
                'paymentProfiles' => [
                    [
                        'customerPaymentProfileId' => '11111',
                        'billTo' => ['firstName' => 'John'],
                    ],
                    [
                        'customerPaymentProfileId' => '22222',
                        'billTo' => ['firstName' => 'Jane'],
                    ],
                ],
            ],
        ];

        $reflection = new \ReflectionMethod($this->observer, 'getCardsFromProfile');
        $reflection->setAccessible(true);

        $result = $reflection->invoke($this->observer, $profile);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('11111', $result);
        $this->assertArrayHasKey('22222', $result);
    }

    public function testGetCardsFromProfileReturnsEmptyForMissingProfiles(): void
    {
        $profile = [
            'profile' => [],
        ];

        $reflection = new \ReflectionMethod($this->observer, 'getCardsFromProfile');
        $reflection->setAccessible(true);

        $result = $reflection->invoke($this->observer, $profile);

        $this->assertEmpty($result);
    }

    public function testCardAlreadyExistsReturnsTrue(): void
    {
        $searchCriteriaMock = $this->createMock(SearchCriteriaInterface::class);

        $this->searchCriteriaBuilderMock->method('addFilter')
            ->willReturnSelf();
        $this->searchCriteriaBuilderMock->method('setPageSize')
            ->willReturnSelf();
        $this->searchCriteriaBuilderMock->method('create')
            ->willReturn($searchCriteriaMock);

        $searchResultsMock = $this->createMock(CardSearchResultsInterface::class);
        $searchResultsMock->method('getTotalCount')
            ->willReturn(1);

        $this->cardRepositoryMock->method('getList')
            ->with($searchCriteriaMock)
            ->willReturn($searchResultsMock);

        $reflection = new \ReflectionMethod($this->observer, 'cardAlreadyExists');
        $reflection->setAccessible(true);

        $result = $reflection->invoke($this->observer, 123, '12345', '67890');

        $this->assertTrue($result);
    }

    public function testCardAlreadyExistsReturnsFalse(): void
    {
        $searchCriteriaMock = $this->createMock(SearchCriteriaInterface::class);

        $this->searchCriteriaBuilderMock->method('addFilter')
            ->willReturnSelf();
        $this->searchCriteriaBuilderMock->method('setPageSize')
            ->willReturnSelf();
        $this->searchCriteriaBuilderMock->method('create')
            ->willReturn($searchCriteriaMock);

        $searchResultsMock = $this->createMock(CardSearchResultsInterface::class);
        $searchResultsMock->method('getTotalCount')
            ->willReturn(0);

        $this->cardRepositoryMock->method('getList')
            ->with($searchCriteriaMock)
            ->willReturn($searchResultsMock);

        $reflection = new \ReflectionMethod($this->observer, 'cardAlreadyExists');
        $reflection->setAccessible(true);

        $result = $reflection->invoke($this->observer, 123, '12345', '67890');

        $this->assertFalse($result);
    }

    public function testUpdateOrdersLinksOrdersToCards(): void
    {
        $cards = [
            '12345' => ['tokenbase_id' => 100],
            '67890' => ['tokenbase_id' => 200],
        ];

        $paymentMock1 = $this->createMock(OrderPayment::class);
        $paymentMock1->expects($this->once())
            ->method('setData')
            ->with('tokenbase_id', 100);

        $orderMock1 = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPayment', 'getExtCustomerId'])
            ->getMock();
        $orderMock1->method('getExtCustomerId')
            ->willReturn('12345');
        $orderMock1->method('getPayment')
            ->willReturn($paymentMock1);

        $orderCollectionMock = $this->getMockBuilder(OrderCollection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addFieldToFilter', 'getIterator'])
            ->getMock();
        $orderCollectionMock->method('addFieldToFilter')
            ->willReturnSelf();
        $orderCollectionMock->method('getIterator')
            ->willReturn(new \ArrayIterator([$orderMock1]));

        $this->orderCollectionFactoryMock->method('create')
            ->willReturn($orderCollectionMock);

        $this->paymentRepositoryMock->expects($this->once())
            ->method('save')
            ->with($paymentMock1);

        $reflection = new \ReflectionMethod($this->observer, 'updateOrders');
        $reflection->setAccessible(true);

        $result = $reflection->invoke($this->observer, $cards);

        $this->assertSame(1, $result);
    }

    public function testUpdateOrdersReturnsZeroForEmptyCards(): void
    {
        $cards = [];

        $reflection = new \ReflectionMethod($this->observer, 'updateOrders');
        $reflection->setAccessible(true);

        $result = $reflection->invoke($this->observer, $cards);

        $this->assertSame(0, $result);
    }

    public function testExecutePerformsFullConversion(): void
    {
        $profileIdAttr = $this->createMock(AttributeInterface::class);
        $profileIdAttr->method('getValue')
            ->willReturn('12345');

        $profileVersionAttr = $this->createMock(AttributeInterface::class);
        $profileVersionAttr->method('getValue')
            ->willReturn(100);

        $customerMock = $this->createMock(CustomerInterface::class);
        $customerMock->method('getId')
            ->willReturn(123);
        $customerMock->method('getFirstname')
            ->willReturn('John');
        $customerMock->method('getLastname')
            ->willReturn('Doe');
        $customerMock->method('getCustomAttribute')
            ->willReturnCallback(function ($key) use ($profileIdAttr, $profileVersionAttr) {
                if ($key === 'authnetcim_profile_id') {
                    return $profileIdAttr;
                }

                if ($key === 'authnetcim_profile_version') {
                    return $profileVersionAttr;
                }

                return null;
            });
        $customerMock->expects($this->once())
            ->method('setCustomAttribute')
            ->with('authnetcim_profile_version', 200);

        $eventMock = $this->createMock(Event::class);
        $eventMock->method('getData')
            ->willReturnCallback(function ($key) use ($customerMock) {
                if ($key === 'method') {
                    return 'authnetcim';
                }

                if ($key === 'customer') {
                    return $customerMock;
                }

                return null;
            });

        $observerMock = $this->createMock(Observer::class);
        $observerMock->method('getEvent')
            ->willReturn($eventMock);

        $gatewayMock = $this->createMock(Gateway::class);
        $gatewayMock->method('setParameter')
            ->willReturnSelf();
        $gatewayMock->method('getCustomerProfile')
            ->willReturn([
                'profile' => [
                    'paymentProfiles' => [],
                ],
            ]);

        $methodMock = $this->createMock(Method::class);
        $methodMock->method('gateway')
            ->willReturn($gatewayMock);

        $this->methodFactoryMock->method('getMethodInstance')
            ->with('authnetcim')
            ->willReturn($methodMock);

        $this->customerRepositoryMock->expects($this->once())
            ->method('save')
            ->with($customerMock);

        $this->observer->execute($observerMock);
    }
}
