<?php

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Test\Unit\Model\Ach;

use Magento\Payment\Model\Info;
use ParadoxLabs\Authnetcim\Model\Ach\Method;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for the ACH Method class
 *
 * Note: Most methods are protected and require complex parent class mocking.
 * These tests use reflection to test the protected paymentContainsCard method.
 */
class MethodTest extends TestCase
{
    public function testPaymentContainsCardValidatesRoutingLength(): void
    {
        $method = $this->createPartialMethodMock();

        $paymentMock = $this->createPaymentMock();
        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                if ($key === 'echeck_routing_no') {
                    return '123456789';
                }

                if ($key === 'echeck_account_no') {
                    return '12345678';
                }

                return null;
            });

        $reflection = new ReflectionMethod($method, 'paymentContainsCard');

        $result = $reflection->invoke($method, $paymentMock);

        $this->assertTrue($result);
    }

    public function testPaymentContainsCardRejectsShortRouting(): void
    {
        $method = $this->createPartialMethodMock();

        $paymentMock = $this->createPaymentMock();
        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                if ($key === 'echeck_routing_no') {
                    return '12345678';
                }

                if ($key === 'echeck_account_no') {
                    return '12345678';
                }

                return null;
            });

        $reflection = new ReflectionMethod($method, 'paymentContainsCard');

        $result = $reflection->invoke($method, $paymentMock);

        $this->assertFalse($result);
    }

    public function testPaymentContainsCardRejectsShortAccount(): void
    {
        $method = $this->createPartialMethodMock();

        $paymentMock = $this->createPaymentMock();
        $paymentMock->method('getData')
            ->willReturnCallback(function ($key) {
                if ($key === 'echeck_routing_no') {
                    return '123456789';
                }

                if ($key === 'echeck_account_no') {
                    return '1234';
                }

                return null;
            });

        $reflection = new ReflectionMethod($method, 'paymentContainsCard');

        $result = $reflection->invoke($method, $paymentMock);

        $this->assertFalse($result);
    }

    public function testPaymentContainsCardRejectsEmptyFields(): void
    {
        $method = $this->createPartialMethodMock();

        $paymentMock = $this->createPaymentMock();
        $paymentMock->method('getData')
            ->willReturn(null);

        $reflection = new ReflectionMethod($method, 'paymentContainsCard');

        $result = $reflection->invoke($method, $paymentMock);

        $this->assertFalse($result);
    }

    private function createPartialMethodMock(): Method
    {
        return $this->getMockBuilder(Method::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }

    private function createPaymentMock(): Info
    {
        return $this->getMockBuilder(Info::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
    }
}
