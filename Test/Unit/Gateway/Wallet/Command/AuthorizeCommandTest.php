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

namespace ParadoxLabs\Authnetcim\Test\Unit\Gateway\Wallet\Command;

use Magento\Payment\Gateway\Command\CommandException;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction\Repository as TransactionRepository;
use ParadoxLabs\Authnetcim\Gateway\Wallet\Command\AuthorizeCommand;
use ParadoxLabs\Authnetcim\Model\Wallet\Config;
use ParadoxLabs\Authnetcim\Model\Wallet\Gateway;
use ParadoxLabs\Authnetcim\Model\Wallet\GatewayProvider;
use ParadoxLabs\Authnetcim\Model\Wallet\PaymentResponseHandler;
use ParadoxLabs\TokenBase\Helper\Data as Helper;
use ParadoxLabs\TokenBase\Model\Gateway\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AuthorizeCommandTest extends TestCase
{
    private Config|MockObject $config;
    private Gateway|MockObject $gateway;
    private GatewayProvider|MockObject $provider;
    private AuthorizeCommand $command;

    /**
     * Wallet log messages written.
     *
     * @var string[]
     */
    private array $messages = [];

    /**
     * Payment additional_information backing store.
     *
     * @var array
     */
    private array $info = [];

    /**
     * @var array
     */
    private array $paymentData = [];

    protected function setUp(): void
    {
        $this->config  = $this->createMock(Config::class);
        $this->gateway = $this->createMock(Gateway::class);

        $this->provider = $this->createMock(GatewayProvider::class);
        $this->provider->method('get')->willReturn($this->gateway);

        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getByTransactionId')->willReturn(false);

        $helper = $this->createMock(Helper::class);
        $helper->method('log')->willReturnCallback(
            function ($code, $message) {
                $this->messages[] = (string)$message;
            }
        );

        $this->command = new AuthorizeCommand(
            $this->provider,
            $this->config,
            new PaymentResponseHandler($transactionRepository),
            $helper
        );
    }

    /**
     * @return void
     */
    public function testAuthorizeSendsTokenAndNeverPersistsIt(): void
    {
        $this->config->method('isWalletEnabled')->willReturn(true);
        $this->info = [
            'wallet_type' => 'applepay',
            'wallet_token' => 'dG9rZW4=',
        ];

        $tokenSeenAtCall = null;
        $this->gateway->expects($this->once())
            ->method('setWalletToken')
            ->with('COMMON.APPLE.INAPP.PAYMENT', 'dG9rZW4=');
        $this->gateway->expects($this->once())
            ->method('authorize')
            ->willReturnCallback(
                function () use (&$tokenSeenAtCall) {
                    $tokenSeenAtCall = $this->info['wallet_token'] ?? null;

                    return new Response([
                        'transaction_id' => '60012345',
                        'acc_number' => 'XXXX4242',
                        'card_type' => 'Visa',
                        'auth_code' => 'ABC123',
                        'is_fraud' => false,
                    ]);
                }
            );

        $this->command->execute($this->buildSubject(25.0));

        $this->assertNull($tokenSeenAtCall, 'Token must be cleared from the payment before the API call.');
        $this->assertArrayNotHasKey('wallet_token', $this->info);
        $this->assertSame('applepay', $this->info['wallet_type']);
        $this->assertSame('60012345', $this->paymentData['transaction_id']);
        $this->assertSame('4242', $this->paymentData['cc_last4']);
        $this->assertSame('VI', $this->paymentData['cc_type']);
        $this->assertFalse($this->paymentData['is_transaction_closed']);
    }

    /**
     * @return void
     */
    public function testDisabledWalletIsRejectedAndTokenCleared(): void
    {
        $this->config->method('isWalletEnabled')->willReturn(false);
        $this->info = [
            'wallet_type' => 'googlepay',
            'wallet_token' => 'dG9rZW4=',
        ];

        $this->gateway->expects($this->never())->method('authorize');

        try {
            $this->command->execute($this->buildSubject(25.0));
            $this->fail('Expected CommandException');
        } catch (CommandException) {
            $this->assertArrayNotHasKey('wallet_token', $this->info);
        }
    }

    /**
     * @return void
     */
    public function testUnknownWalletTypeIsRejected(): void
    {
        $this->config->method('isWalletEnabled')->willReturn(true);
        $this->info = [
            'wallet_type' => 'paypal',
            'wallet_token' => 'dG9rZW4=',
        ];

        $this->expectException(CommandException::class);

        $this->command->execute($this->buildSubject(25.0));
    }

    /**
     * @return array
     */
    public static function matchingAmountProvider(): array
    {
        return [
            'exact' => [25.0, '25.00'],
            'no decimals' => [25.0, '25'],
            'one decimal' => [25.5, '25.5'],
            'rounds to the same cent' => [24.996, '25.00'],
        ];
    }

    /**
     * The customer approved the amount being charged: authorize as usual, and drop the amount with the token.
     *
     * @dataProvider matchingAmountProvider
     * @param float $amount
     * @param string $walletAmount
     * @return void
     */
    #[DataProvider('matchingAmountProvider')]
    public function testMatchingApprovedAmountIsAuthorized(float $amount, string $walletAmount): void
    {
        $this->config->method('isWalletEnabled')->willReturn(true);
        $this->info = [
            'wallet_type' => 'googlepay',
            'wallet_token' => 'dG9rZW4=',
            'wallet_amount' => $walletAmount,
        ];

        $this->gateway->expects($this->once())
            ->method('authorize')
            ->with($this->anything(), $amount)
            ->willReturn(
                new Response([
                    'transaction_id' => '60012345',
                ])
            );

        $this->command->execute($this->buildSubject($amount));

        $this->assertArrayNotHasKey('wallet_token', $this->info);
        $this->assertArrayNotHasKey('wallet_amount', $this->info, 'The approved amount must not be persisted.');
    }

    /**
     * @return array
     */
    public static function mismatchedAmountProvider(): array
    {
        return [
            'total went up' => [25.0, '24.99'],
            'total went down' => [20.0, '25.00'],
            'store credit applied' => [0.01, '25.00'],
        ];
    }

    /**
     * The order total changed after the customer approved the wallet sheet: never charge the new amount.
     *
     * @dataProvider mismatchedAmountProvider
     * @param float $amount
     * @param string $walletAmount
     * @return void
     */
    #[DataProvider('mismatchedAmountProvider')]
    public function testMismatchedApprovedAmountIsRejectedWithoutGatewayCall(float $amount, string $walletAmount): void
    {
        $this->config->method('isWalletEnabled')->willReturn(true);
        $this->info = [
            'wallet_type' => 'applepay',
            'wallet_token' => 'dG9rZW4=',
            'wallet_amount' => $walletAmount,
        ];

        $this->provider->expects($this->never())->method('get');
        $this->gateway->expects($this->never())->method('setWalletToken');
        $this->gateway->expects($this->never())->method('authorize');

        try {
            $this->command->execute($this->buildSubject($amount));
            $this->fail('Expected CommandException');
        } catch (CommandException $exception) {
            $this->assertSame(
                'The order total changed after you approved your Apple Pay payment. Please review your order and '
                . 'pay again.',
                $exception->getMessage()
            );
        }

        $this->assertArrayNotHasKey('wallet_token', $this->info, 'The spent token must still be cleared.');
        $this->assertArrayNotHasKey('wallet_amount', $this->info);

        $logged = implode("\n", $this->messages);
        $this->assertStringContainsString('100000123', $logged);
        $this->assertStringContainsString(number_format($amount, 2, '.', ''), $logged);
        $this->assertStringContainsString($walletAmount, $logged);
    }

    /**
     * Headless clients may omit the approved amount; the order is then authorized without the check.
     *
     * @return void
     */
    public function testAbsentApprovedAmountIsAuthorized(): void
    {
        $this->config->method('isWalletEnabled')->willReturn(true);
        $this->info = [
            'wallet_type' => 'googlepay',
            'wallet_token' => 'dG9rZW4=',
        ];

        $this->gateway->expects($this->once())
            ->method('authorize')
            ->with($this->anything(), 25.0)
            ->willReturn(
                new Response([
                    'transaction_id' => '60012345',
                ])
            );

        $this->command->execute($this->buildSubject(25.0));
    }

    /**
     * @return void
     */
    public function testFraudHoldMarksPaymentForReview(): void
    {
        $this->config->method('isWalletEnabled')->willReturn(true);
        $this->info = [
            'wallet_type' => 'googlepay',
            'wallet_token' => 'dG9rZW4=',
        ];

        $this->gateway->method('authorize')->willReturn(
            new Response([
                'transaction_id' => '60012345',
                'is_fraud' => true,
            ])
        );

        $this->command->execute($this->buildSubject(25.0));

        $this->assertTrue($this->paymentData['is_transaction_pending']);
        $this->assertTrue($this->paymentData['is_fraud_detected']);
    }

    /**
     * @param float $amount
     * @return array
     */
    private function buildSubject(float $amount): array
    {
        $order = $this->createMock(Order::class);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('100000123');
        $order->method('getAllVisibleItems')->willReturn([]);

        $payment = $this->createMock(Payment::class);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            fn ($key = null) => $key === null ? $this->info : ($this->info[$key] ?? null)
        );
        $payment->method('hasAdditionalInformation')->willReturnCallback(
            fn ($key = null) => $key === null ? !empty($this->info) : array_key_exists($key, $this->info)
        );
        $payment->method('unsAdditionalInformation')->willReturnCallback(
            function ($key = null) use ($payment) {
                unset($this->info[$key]);

                return $payment;
            }
        );
        $payment->method('setAdditionalInformation')->willReturnCallback(
            function ($key, $value = null) use ($payment) {
                if (is_array($key)) {
                    $this->info = $key;
                } else {
                    $this->info[$key] = $value;
                }

                return $payment;
            }
        );

        $setters = [
            'setTransactionId' => 'transaction_id',
            'setCcLast4' => 'cc_last4',
            'setCcType' => 'cc_type',
            'setIsTransactionClosed' => 'is_transaction_closed',
            'setIsTransactionPending' => 'is_transaction_pending',
            'setIsFraudDetected' => 'is_fraud_detected',
        ];
        foreach ($setters as $method => $key) {
            $payment->method($method)->willReturnCallback(
                function ($value) use ($payment, $key) {
                    $this->paymentData[$key] = $value;

                    return $payment;
                }
            );
        }

        $paymentDataObject = $this->createMock(PaymentDataObjectInterface::class);
        $paymentDataObject->method('getPayment')->willReturn($payment);

        return [
            'payment' => $paymentDataObject,
            'amount' => $amount,
        ];
    }
}
