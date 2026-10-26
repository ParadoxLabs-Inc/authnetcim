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

namespace ParadoxLabs\Authnetcim\Test\Unit\Observer\Wallet;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Payment\Gateway\Data\PaymentDataObjectFactoryInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction\Repository as TransactionRepository;
use ParadoxLabs\Authnetcim\Gateway\Wallet\Command\VoidCommand;
use ParadoxLabs\Authnetcim\Model\Wallet\Config;
use ParadoxLabs\Authnetcim\Model\Wallet\Gateway;
use ParadoxLabs\Authnetcim\Model\Wallet\GatewayProvider;
use ParadoxLabs\Authnetcim\Model\Wallet\PaymentResponseHandler;
use ParadoxLabs\Authnetcim\Observer\Wallet\CheckoutFailureVoidObserver;
use ParadoxLabs\TokenBase\Helper\Data as Helper;
use ParadoxLabs\TokenBase\Model\Gateway\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Checkout failure after the wallet payment went through: void it, unless the order was saved.
 *
 * Runs the real 'void' command against a mocked wallet gateway, so the transaction ID the gateway gets is the one the
 * command normalizes.
 */
class CheckoutFailureVoidObserverTest extends TestCase
{
    private Gateway|MockObject $gateway;
    private OrderRepositoryInterface|MockObject $orderRepository;
    private CheckoutFailureVoidObserver $observer;

    /**
     * Log messages written, as [code, message].
     *
     * @var array
     */
    private array $messages = [];

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(Gateway::class);

        $provider = $this->createMock(GatewayProvider::class);
        $provider->method('get')->willReturn($this->gateway);

        $helper = $this->createMock(Helper::class);
        $helper->method('log')->willReturnCallback(
            function ($code, $message) use ($helper) {
                $this->messages[] = [
                    $code,
                    $message,
                ];

                return $helper;
            }
        );

        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getByTransactionId')->willReturn(false);

        $voidCommand = new VoidCommand(
            $provider,
            $this->createMock(Config::class),
            new PaymentResponseHandler($transactionRepository),
            $helper,
        );

        $paymentDataObjectFactory = $this->createMock(PaymentDataObjectFactoryInterface::class);
        $paymentDataObjectFactory->method('create')->willReturnCallback(
            function ($payment) {
                $paymentDataObject = $this->createMock(PaymentDataObjectInterface::class);
                $paymentDataObject->method('getPayment')->willReturn($payment);

                return $paymentDataObject;
            }
        );

        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);

        $this->observer = new CheckoutFailureVoidObserver(
            $voidCommand,
            $paymentDataObjectFactory,
            $this->orderRepository,
            $helper,
        );
    }

    /**
     * The order was never saved: void the authorization (or auth+capture) at Authorize.net.
     *
     * @return void
     */
    public function testVoidsTransactionOfUnsavedOrder(): void
    {
        $order = $this->buildOrder('authnetcim_wallet', '60012345');

        $this->gateway->expects($this->once())
            ->method('void')
            ->with($order->getPayment(), '60012345')
            ->willReturn(new Response($this->buildVoidResponseData()));
        $this->orderRepository->expects($this->never())->method('get');

        $this->execute($order);

        $this->assertLogged('Auto-voided transaction 60012345 for order 000002512 because checkout failed.');
    }

    /**
     * Magento transaction ID suffixes are stripped before the void goes to Authorize.net.
     *
     * @dataProvider transactionIdProvider
     * @param string $lastTransactionId
     * @return void
     */
    #[DataProvider('transactionIdProvider')]
    public function testVoidsNormalizedTransactionId(string $lastTransactionId): void
    {
        $order = $this->buildOrder('authnetcim_wallet', $lastTransactionId);

        $this->gateway->expects($this->once())
            ->method('void')
            ->with($this->anything(), '60012345')
            ->willReturn(new Response($this->buildVoidResponseData()));

        $this->execute($order);
    }

    /**
     * @return array
     */
    public static function transactionIdProvider(): array
    {
        return [
            'auth or auth+capture' => [
                '60012345',
            ],
            'capture suffix' => [
                '60012345-capture',
            ],
            'unique suffix' => [
                '60012345-1',
            ],
        ];
    }

    /**
     * Other payment methods are TokenBase's (or nobody's) business.
     *
     * @return void
     */
    public function testIgnoresOtherPaymentMethods(): void
    {
        $this->gateway->expects($this->never())->method('void');

        $this->execute($this->buildOrder('authnetcim', '60012345'));

        $this->assertSame([], $this->messages);
    }

    /**
     * No transaction went through (e.g. the authorization was declined): nothing to void.
     *
     * @return void
     */
    public function testIgnoresOrderWithoutTransaction(): void
    {
        $this->gateway->expects($this->never())->method('void');

        $this->execute($this->buildOrder('authnetcim_wallet', null));

        $this->assertSame([], $this->messages);
    }

    /**
     * @return void
     */
    public function testIgnoresEventWithoutOrder(): void
    {
        $this->gateway->expects($this->never())->method('void');

        $this->observer->execute(new Observer(['event' => new Event([])]));

        $this->assertSame([], $this->messages);
    }

    /**
     * The order was saved before checkout failed (e.g. the cart save threw): the transaction is the order's.
     *
     * @return void
     */
    public function testKeepsTransactionOfSavedOrder(): void
    {
        $order = $this->buildOrder('authnetcim_wallet', '60012345', 42, Order::STATE_PROCESSING);

        $this->orderRepository->expects($this->once())->method('get')->with(42)->willReturn($order);
        $this->gateway->expects($this->never())->method('void');

        $this->execute($order);

        $this->assertSame([], $this->messages);
    }

    /**
     * A failed order save rolls the insert back but leaves the ID on the order: still void.
     *
     * @return void
     */
    public function testVoidsWhenOrderSaveWasRolledBack(): void
    {
        $order = $this->buildOrder('authnetcim_wallet', '60012345', 42, Order::STATE_PROCESSING);

        $this->orderRepository->expects($this->once())
            ->method('get')
            ->with(42)
            ->willThrowException(new NoSuchEntityException(__('No such order.')));
        $this->gateway->expects($this->once())
            ->method('void')
            ->with($this->anything(), '60012345')
            ->willReturn(new Response($this->buildVoidResponseData()));

        $this->execute($order);

        $this->assertLogged('Auto-voided transaction 60012345 for order 000002512 because checkout failed.');
    }

    /**
     * A saved order that ended up canceled did not go through: void.
     *
     * @return void
     */
    public function testVoidsTransactionOfCanceledOrder(): void
    {
        $order = $this->buildOrder('authnetcim_wallet', '60012345', 42, Order::STATE_CANCELED);

        $this->orderRepository->expects($this->never())->method('get');
        $this->gateway->expects($this->once())
            ->method('void')
            ->willReturn(new Response($this->buildVoidResponseData()));

        $this->execute($order);
    }

    /**
     * A rejected void is logged, never thrown: Magento is already handling the checkout exception.
     *
     * @return void
     */
    public function testGatewayErrorIsLoggedNotThrown(): void
    {
        $this->gateway->expects($this->once())
            ->method('void')
            ->willThrowException(new LocalizedException(__('The transaction cannot be found. (E00027)')));

        $this->execute($this->buildOrder('authnetcim_wallet', '60012345'));

        $this->assertLogged('Void failed: The transaction cannot be found. (E00027)');
        $this->assertLogged(
            'Checkout failed, and voiding transaction 60012345 for order 000002512 failed: Unable to void payment: '
            . 'The transaction cannot be found. (E00027) The authorization may still be open at the payment gateway.'
        );
    }

    /**
     * An error while checking whether the order was saved is logged, never thrown, and nothing is voided.
     *
     * @return void
     */
    public function testOrderLookupErrorIsLoggedNotThrown(): void
    {
        $order = $this->buildOrder('authnetcim_wallet', '60012345', 42, Order::STATE_PROCESSING);

        $this->orderRepository->method('get')->willThrowException(new RuntimeException('MySQL server has gone away'));
        $this->gateway->expects($this->never())->method('void');

        $this->execute($order);

        $this->assertLogged(
            'Checkout failed, and voiding transaction 60012345 for order 000002512 failed: MySQL server has gone away'
        );
    }

    /**
     * @param Order $order
     * @return void
     */
    private function execute(Order $order): void
    {
        $this->observer->execute(
            new Observer(
                [
                    'event' => new Event(
                        [
                            'order' => $order,
                            'exception' => new RuntimeException('Saving the order failed.'),
                        ]
                    ),
                ]
            )
        );
    }

    /**
     * @param string $message
     * @return void
     */
    private function assertLogged(string $message): void
    {
        $this->assertContains(
            [
                'authnetcim_wallet',
                $message,
            ],
            $this->messages
        );
    }

    /**
     * @param string $method
     * @param string|null $lastTransactionId
     * @param int|null $orderId
     * @param string $state
     * @return Order
     */
    private function buildOrder(
        string $method,
        ?string $lastTransactionId,
        ?int $orderId = null,
        string $state = Order::STATE_PROCESSING,
    ): Order {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn($orderId);
        $order->method('getState')->willReturn($state);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('000002512');

        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getLastTransId')->willReturn($lastTransactionId);
        $payment->method('getOrder')->willReturn($order);

        $order->method('getPayment')->willReturn($payment);

        return $order;
    }

    /**
     * @return array
     */
    private function buildVoidResponseData(): array
    {
        return [
            'response_code' => 1,
            'response_reason_code' => 1,
            'response_reason_text' => 'This transaction has been approved.',
            'transaction_id' => '60012345',
            'transaction_type' => 'void',
            'is_fraud' => false,
            'is_error' => false,
        ];
    }
}
