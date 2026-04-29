<?php

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Test\Unit\Observer;

use Exception;
use Magento\Customer\Model\Customer;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Model\Entity\Attribute;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event\Observer;
use ParadoxLabs\Authnetcim\Helper\Data;
use ParadoxLabs\Authnetcim\Observer\PaymentConfigSaveObserver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PaymentConfigSaveObserverTest extends TestCase
{
    private PaymentConfigSaveObserver $observer;
    private RequestInterface|MockObject $requestMock;
    private AttributeRepositoryInterface|MockObject $attributeRepositoryMock;
    private ResourceConnection|MockObject $resourceConnectionMock;
    private Data|MockObject $helperMock;

    protected function setUp(): void
    {
        $this->requestMock = $this->createMock(RequestInterface::class);
        $this->attributeRepositoryMock = $this->createMock(AttributeRepositoryInterface::class);
        $this->resourceConnectionMock = $this->createMock(ResourceConnection::class);
        $this->helperMock = $this->createMock(Data::class);

        $this->observer = new PaymentConfigSaveObserver(
            $this->requestMock,
            $this->attributeRepositoryMock,
            $this->resourceConnectionMock,
            $this->helperMock,
        );
    }

    public function testExecuteSkipsWhenChangedPathsNull(): void
    {
        $observerMock = $this->createMock(Observer::class);
        $observerMock->method('getData')
            ->with('changed_paths')
            ->willReturn(null);

        $this->requestMock->expects($this->never())
            ->method('getParam');

        $this->observer->execute($observerMock);
    }

    public function testExecuteSkipsMaskedLoginId(): void
    {
        $observerMock = $this->createMock(Observer::class);
        $observerMock->method('getData')
            ->with('changed_paths')
            ->willReturn(['payment/authnetcim/login']);

        $this->requestMock->method('getParam')
            ->with('groups')
            ->willReturn([
                'authnetcim' => [
                    'fields' => [
                        'login' => [
                            'value' => '******',
                        ],
                    ],
                ],
            ]);

        $this->attributeRepositoryMock->expects($this->never())
            ->method('get');

        $this->observer->execute($observerMock);
    }

    public function testExecutePurgesOnLoginChange(): void
    {
        $observerMock = $this->createMock(Observer::class);
        $observerMock->method('getData')
            ->with('changed_paths')
            ->willReturn(['payment/authnetcim/login']);

        $this->requestMock->method('getParam')
            ->with('groups')
            ->willReturn([
                'authnetcim' => [
                    'fields' => [
                        'login' => [
                            'value' => 'new_login_id',
                        ],
                    ],
                ],
            ]);

        $attributeMock = $this->createMock(Attribute::class);
        $attributeMock->method('getAttributeCode')
            ->willReturn('authnetcim_profile_id');
        $attributeMock->method('getBackendTable')
            ->willReturn('customer_entity_varchar');
        $attributeMock->method('getId')
            ->willReturn(123);

        $this->attributeRepositoryMock->method('get')
            ->with(Customer::ENTITY, 'authnetcim_profile_id')
            ->willReturn($attributeMock);

        $adapterMock = $this->createMock(AdapterInterface::class);
        $adapterMock->method('getTableName')
            ->with('customer_entity_varchar')
            ->willReturn('customer_entity_varchar');
        $adapterMock->expects($this->once())
            ->method('delete')
            ->with('customer_entity_varchar', ['attribute_id=?' => 123])
            ->willReturn(5);

        $this->resourceConnectionMock->method('getConnection')
            ->willReturn($adapterMock);

        $this->helperMock->expects($this->once())
            ->method('log')
            ->with('authnetcim', $this->anything());

        $this->observer->execute($observerMock);
    }

    public function testExecutePurgesForAchMethod(): void
    {
        $observerMock = $this->createMock(Observer::class);
        $observerMock->method('getData')
            ->with('changed_paths')
            ->willReturn(['payment/authnetcim_ach/login']);

        $this->requestMock->method('getParam')
            ->with('groups')
            ->willReturn([
                'authnetcim' => [
                    'fields' => [
                        'login' => [
                            'value' => '******',
                        ],
                    ],
                ],
                'authnetcim_ach' => [
                    'fields' => [
                        'login' => [
                            'value' => 'new_ach_login_id',
                        ],
                    ],
                ],
            ]);

        $attributeMock = $this->createMock(Attribute::class);
        $attributeMock->method('getAttributeCode')
            ->willReturn('authnetcim_profile_id');
        $attributeMock->method('getBackendTable')
            ->willReturn('customer_entity_varchar');
        $attributeMock->method('getId')
            ->willReturn(123);

        $this->attributeRepositoryMock->method('get')
            ->willReturn($attributeMock);

        $adapterMock = $this->createMock(AdapterInterface::class);
        $adapterMock->method('getTableName')
            ->willReturn('customer_entity_varchar');
        $adapterMock->method('delete')
            ->willReturn(3);

        $this->resourceConnectionMock->method('getConnection')
            ->willReturn($adapterMock);

        $this->observer->execute($observerMock);
    }

    public function testExecuteCatchesExceptions(): void
    {
        $observerMock = $this->createMock(Observer::class);
        $observerMock->method('getData')
            ->with('changed_paths')
            ->willReturn(['payment/authnetcim/login']);

        $this->requestMock->method('getParam')
            ->with('groups')
            ->willReturn([
                'authnetcim' => [
                    'fields' => [
                        'login' => [
                            'value' => 'new_login_id',
                        ],
                    ],
                ],
            ]);

        $this->attributeRepositoryMock->method('get')
            ->willThrowException(new Exception('Attribute not found'));

        $this->helperMock->expects($this->once())
            ->method('log')
            ->with('authnetcim', $this->anything());

        $this->observer->execute($observerMock);
    }

    public function testExecuteSkipsWhenLoginNotInChangedPaths(): void
    {
        $observerMock = $this->createMock(Observer::class);
        $observerMock->method('getData')
            ->with('changed_paths')
            ->willReturn(['payment/authnetcim/active']);

        $this->requestMock->method('getParam')
            ->with('groups')
            ->willReturn([
                'authnetcim' => [
                    'fields' => [
                        'login' => [
                            'value' => 'new_login_id',
                        ],
                    ],
                ],
            ]);

        $this->attributeRepositoryMock->expects($this->never())
            ->method('get');

        $this->observer->execute($observerMock);
    }
}
