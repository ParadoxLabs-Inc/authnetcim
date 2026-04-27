<?php

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use ParadoxLabs\Authnetcim\Observer\CheckoutFailureClearAcceptjsObserver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CheckoutFailureClearAcceptjsObserverTest extends TestCase
{
    private CheckoutFailureClearAcceptjsObserver $observer;
    private OrderPaymentRepositoryInterface|MockObject $orderPaymentRepositoryMock;
    private CartRepositoryInterface|MockObject $quoteRepositoryMock;

    protected function setUp(): void
    {
        $this->orderPaymentRepositoryMock = $this->createMock(OrderPaymentRepositoryInterface::class);
        $this->quoteRepositoryMock = $this->createMock(CartRepositoryInterface::class);

        $this->observer = new CheckoutFailureClearAcceptjsObserver(
            $this->orderPaymentRepositoryMock,
            $this->quoteRepositoryMock,
        );
    }

    public function testExecuteClearsOrderTokens(): void
    {
        $paymentMock = $this->createOrderPaymentMock();
        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key) {
                if ($key === 'acceptjs_key') {
                    return 'my-key';
                }

                if ($key === 'acceptjs_value') {
                    return 'my-value';
                }

                return null;
            });
        $paymentMock->method('getId')
            ->willReturn(123);
        $paymentMock->expects($this->exactly(2))
            ->method('setAdditionalInformation');

        $orderMock = $this->createMock(Order::class);
        $orderMock->method('getPayment')
            ->willReturn($paymentMock);

        $eventMock = $this->createMock(Event::class);
        $eventMock->method('getData')
            ->willReturnCallback(function ($key) use ($orderMock) {
                if ($key === 'order') {
                    return $orderMock;
                }

                return null;
            });

        $observerMock = $this->createMock(Observer::class);
        $observerMock->method('getEvent')
            ->willReturn($eventMock);

        $this->orderPaymentRepositoryMock->expects($this->once())
            ->method('save')
            ->with($paymentMock);

        $this->observer->execute($observerMock);
    }

    public function testExecuteClearsQuoteTokens(): void
    {
        $quoteMock = $this->createMock(Quote::class);

        $paymentMock = $this->createQuotePaymentMock();
        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key) {
                if ($key === 'acceptjs_key') {
                    return 'my-key';
                }

                if ($key === 'acceptjs_value') {
                    return 'my-value';
                }

                return null;
            });
        $paymentMock->method('getId')
            ->willReturn(456);
        $paymentMock->method('getQuote')
            ->willReturn($quoteMock);
        $paymentMock->expects($this->exactly(2))
            ->method('setAdditionalInformation');

        $quoteMock->method('getPayment')
            ->willReturn($paymentMock);

        $eventMock = $this->createMock(Event::class);
        $eventMock->method('getData')
            ->willReturnCallback(function ($key) use ($quoteMock) {
                if ($key === 'quote') {
                    return $quoteMock;
                }

                return null;
            });

        $observerMock = $this->createMock(Observer::class);
        $observerMock->method('getEvent')
            ->willReturn($eventMock);

        $this->quoteRepositoryMock->expects($this->once())
            ->method('save')
            ->with($quoteMock);

        $this->observer->execute($observerMock);
    }

    public function testClearTokensSkipsUnpersistedPayment(): void
    {
        $paymentMock = $this->createOrderPaymentMock();
        $paymentMock->method('getAdditionalInformation')
            ->willReturnCallback(function ($key) {
                if ($key === 'acceptjs_key') {
                    return 'my-key';
                }

                if ($key === 'acceptjs_value') {
                    return 'my-value';
                }

                return null;
            });
        $paymentMock->method('getId')
            ->willReturn(0);

        $orderMock = $this->createMock(Order::class);
        $orderMock->method('getPayment')
            ->willReturn($paymentMock);

        $eventMock = $this->createMock(Event::class);
        $eventMock->method('getData')
            ->willReturnCallback(function ($key) use ($orderMock) {
                if ($key === 'order') {
                    return $orderMock;
                }

                return null;
            });

        $observerMock = $this->createMock(Observer::class);
        $observerMock->method('getEvent')
            ->willReturn($eventMock);

        $this->orderPaymentRepositoryMock->expects($this->never())
            ->method('save');

        $this->observer->execute($observerMock);
    }

    public function testClearTokensSkipsEmptyTokens(): void
    {
        $paymentMock = $this->createOrderPaymentMock();
        $paymentMock->method('getAdditionalInformation')
            ->willReturn(null);
        $paymentMock->method('getId')
            ->willReturn(123);

        $orderMock = $this->createMock(Order::class);
        $orderMock->method('getPayment')
            ->willReturn($paymentMock);

        $eventMock = $this->createMock(Event::class);
        $eventMock->method('getData')
            ->willReturnCallback(function ($key) use ($orderMock) {
                if ($key === 'order') {
                    return $orderMock;
                }

                return null;
            });

        $observerMock = $this->createMock(Observer::class);
        $observerMock->method('getEvent')
            ->willReturn($eventMock);

        $this->orderPaymentRepositoryMock->expects($this->never())
            ->method('save');

        $this->observer->execute($observerMock);
    }

    public function testClearTokensSkipsNonQuoteNonOrder(): void
    {
        $eventMock = $this->createMock(Event::class);
        $eventMock->method('getData')
            ->willReturnCallback(function ($key) {
                if ($key === 'order') {
                    return 'not an order';
                }

                if ($key === 'quote') {
                    return new \stdClass();
                }

                return null;
            });

        $observerMock = $this->createMock(Observer::class);
        $observerMock->method('getEvent')
            ->willReturn($eventMock);

        $this->orderPaymentRepositoryMock->expects($this->never())
            ->method('save');
        $this->quoteRepositoryMock->expects($this->never())
            ->method('save');

        $this->observer->execute($observerMock);
    }

    public function testExecuteCatchesExceptions(): void
    {
        $paymentMock = $this->createOrderPaymentMock();
        $paymentMock->method('getAdditionalInformation')
            ->willThrowException(new \Exception('Test exception'));

        $orderMock = $this->createMock(Order::class);
        $orderMock->method('getPayment')
            ->willReturn($paymentMock);

        $eventMock = $this->createMock(Event::class);
        $eventMock->method('getData')
            ->willReturnCallback(function ($key) use ($orderMock) {
                if ($key === 'order') {
                    return $orderMock;
                }

                return null;
            });

        $observerMock = $this->createMock(Observer::class);
        $observerMock->method('getEvent')
            ->willReturn($eventMock);

        // Should not throw - exception is caught
        $this->observer->execute($observerMock);
        $this->assertTrue(true);
    }

    private function createOrderPaymentMock(): OrderPayment|MockObject
    {
        return $this->getMockBuilder(OrderPayment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAdditionalInformation', 'setAdditionalInformation', 'getId'])
            ->getMock();
    }

    private function createQuotePaymentMock(): QuotePayment|MockObject
    {
        return $this->getMockBuilder(QuotePayment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAdditionalInformation', 'setAdditionalInformation', 'getId', 'getQuote'])
            ->getMock();
    }
}
