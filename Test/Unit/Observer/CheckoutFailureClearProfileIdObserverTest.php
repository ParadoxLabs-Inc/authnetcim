<?php

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Test\Unit\Observer;

use Exception;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Registry;
use ParadoxLabs\Authnetcim\Observer\CheckoutFailureClearProfileIdObserver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CheckoutFailureClearProfileIdObserverTest extends TestCase
{
    private CheckoutFailureClearProfileIdObserver $observer;
    private Registry|MockObject $registryMock;
    private CustomerRepositoryInterface|MockObject $customerRepositoryMock;

    protected function setUp(): void
    {
        $this->registryMock = $this->createMock(Registry::class);
        $this->customerRepositoryMock = $this->createMock(CustomerRepositoryInterface::class);

        $this->observer = new CheckoutFailureClearProfileIdObserver(
            $this->registryMock,
            $this->customerRepositoryMock,
        );
    }

    public function testExecuteClearsQueuedProfileId(): void
    {
        $observerMock = $this->createMock(Observer::class);

        $customerMock = $this->createMock(CustomerInterface::class);
        $customerMock->method('getId')
            ->willReturn(123);
        $customerMock->expects($this->once())
            ->method('setCustomAttribute')
            ->with('authnetcim_profile_id', '');

        $this->registryMock->method('registry')
            ->with('queue_profileid_deletion')
            ->willReturn($customerMock);

        $this->customerRepositoryMock->expects($this->once())
            ->method('save')
            ->with($customerMock);

        $this->observer->execute($observerMock);
    }

    public function testExecuteSkipsMissingRegistryKey(): void
    {
        $observerMock = $this->createMock(Observer::class);

        $this->registryMock->method('registry')
            ->with('queue_profileid_deletion')
            ->willReturn(null);

        $this->customerRepositoryMock->expects($this->never())
            ->method('save');

        $this->observer->execute($observerMock);
    }

    public function testExecuteSkipsInvalidCustomer(): void
    {
        $observerMock = $this->createMock(Observer::class);

        $customerMock = $this->createMock(CustomerInterface::class);
        $customerMock->method('getId')
            ->willReturn(0);

        $this->registryMock->method('registry')
            ->with('queue_profileid_deletion')
            ->willReturn($customerMock);

        $this->customerRepositoryMock->expects($this->never())
            ->method('save');

        $this->observer->execute($observerMock);
    }

    public function testExecuteCatchesExceptions(): void
    {
        $observerMock = $this->createMock(Observer::class);

        $customerMock = $this->createMock(CustomerInterface::class);
        $customerMock->method('getId')
            ->willReturn(123);
        $customerMock->method('setCustomAttribute')
            ->willReturnSelf();

        $this->registryMock->method('registry')
            ->with('queue_profileid_deletion')
            ->willReturn($customerMock);

        $this->customerRepositoryMock->method('save')
            ->willThrowException(new Exception('Save failed'));

        // Should not throw - exception is caught
        $this->observer->execute($observerMock);
        $this->assertTrue(true);
    }
}
