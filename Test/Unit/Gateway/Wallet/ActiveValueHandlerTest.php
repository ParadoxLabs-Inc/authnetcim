<?php
/**
 * Copyright © 2015-present ParadoxLabs, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * Need help? Try our knowledgebase and support system:
 *
 * @link https://support.paradoxlabs.com
 */

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Test\Unit\Gateway\Wallet;

use Magento\Payment\Gateway\Data\OrderAdapterInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use ParadoxLabs\Authnetcim\Gateway\Wallet\ActiveValueHandler;
use ParadoxLabs\Authnetcim\Model\Wallet\Config;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ActiveValueHandlerTest extends TestCase
{
    private Config|MockObject $config;

    private StoreManagerInterface|MockObject $storeManager;

    private ActiveValueHandler $handler;

    protected function setUp(): void
    {
        $this->config       = $this->createMock(Config::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);

        $this->handler = new ActiveValueHandler(
            $this->config,
            $this->storeManager
        );
    }

    /**
     * @return void
     */
    public function testActiveWhenAWalletIsEnabled(): void
    {
        $this->config->expects($this->once())
            ->method('getEnabledWallets')
            ->with(3)
            ->willReturn(['googlepay']);

        $this->assertTrue(
            $this->handler->handle(
                [
                    'field' => 'active',
                ],
                3
            )
        );
    }

    /**
     * @return void
     */
    public function testInactiveWhenNoWalletIsEnabled(): void
    {
        $this->config->method('getEnabledWallets')->willReturn([]);

        $this->assertFalse(
            $this->handler->handle(
                [
                    'field' => 'active',
                ],
                1
            )
        );
    }

    /**
     * @return void
     */
    public function testPaymentStoreWinsOverGivenStore(): void
    {
        $order = $this->createMock(OrderAdapterInterface::class);
        $order->method('getStoreId')->willReturn(7);

        $payment = $this->createMock(PaymentDataObjectInterface::class);
        $payment->method('getOrder')->willReturn($order);

        $this->config->expects($this->once())
            ->method('getEnabledWallets')
            ->with(7)
            ->willReturn(['applepay']);

        $this->assertTrue(
            $this->handler->handle(
                [
                    'field' => 'active',
                    'payment' => $payment,
                ],
                1
            )
        );
    }

    /**
     * @return void
     */
    public function testGivenStoreUsedWhenPaymentHasNoStore(): void
    {
        $order = $this->createMock(OrderAdapterInterface::class);
        $order->method('getStoreId')->willReturn(null);

        $payment = $this->createMock(PaymentDataObjectInterface::class);
        $payment->method('getOrder')->willReturn($order);

        $this->config->expects($this->once())
            ->method('getEnabledWallets')
            ->with(2)
            ->willReturn([]);

        $this->assertFalse(
            $this->handler->handle(
                [
                    'field' => 'active',
                    'payment' => $payment,
                ],
                '2'
            )
        );
    }

    /**
     * @return void
     */
    public function testFallsBackToCurrentStore(): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(4);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->config->expects($this->once())
            ->method('getEnabledWallets')
            ->with(4)
            ->willReturn(['googlepay']);

        $this->assertTrue(
            $this->handler->handle(
                [
                    'field' => 'active',
                ]
            )
        );
    }

    /**
     * @return void
     */
    public function testUnresolvableStoreChecksDefaultScope(): void
    {
        $this->storeManager->method('getStore')->willThrowException(new RuntimeException('No store'));

        $this->config->expects($this->once())
            ->method('getEnabledWallets')
            ->with(null)
            ->willReturn([]);

        $this->assertFalse(
            $this->handler->handle(
                [
                    'field' => 'active',
                ]
            )
        );
    }
}
