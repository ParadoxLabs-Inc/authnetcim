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

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Command\CommandException;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\Repository as TransactionRepository;
use ParadoxLabs\Authnetcim\Gateway\Wallet\Command\CaptureCommand;
use ParadoxLabs\Authnetcim\Model\Wallet\Config;
use ParadoxLabs\Authnetcim\Model\Wallet\Gateway;
use ParadoxLabs\Authnetcim\Model\Wallet\GatewayProvider;
use ParadoxLabs\Authnetcim\Model\Wallet\PaymentResponseHandler;
use ParadoxLabs\TokenBase\Helper\Data as Helper;
use ParadoxLabs\TokenBase\Model\Gateway\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CaptureCommandTest extends TestCase
{
    private Config|MockObject $config;

    private Gateway|MockObject $gateway;

    private CaptureCommand $command;

    /**
     * Payment additional_information backing store.
     *
     * @var array
     */
    private array $info = [];

    protected function setUp(): void
    {
        $this->config  = $this->createMock(Config::class);
        $this->gateway = $this->createMock(Gateway::class);

        $provider = $this->createMock(GatewayProvider::class);
        $provider->method('get')->willReturn($this->gateway);

        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getByTransactionId')->willReturn(false);

        $this->command = new CaptureCommand(
            $provider,
            $this->config,
            new PaymentResponseHandler($transactionRepository),
            $this->createMock(Helper::class)
        );
    }

    /**
     * @return void
     */
    public function testFullCaptureOfAuthorizationIsSent(): void
    {
        $this->gateway->expects($this->once())
            ->method('capture')
            ->with($this->anything(), 30.0, '60012345')
            ->willReturn($this->buildResponse());

        $this->command->execute($this->buildSubject(30.0, 30.0, '60012345'));
    }

    /**
     * @return void
     */
    public function testCaptureWithinEpsilonOfAuthorizationIsSent(): void
    {
        $this->gateway->expects($this->once())
            ->method('capture')
            ->willReturn($this->buildResponse());

        $this->command->execute($this->buildSubject(29.99999, 30.0, '60012345'));
    }

    /**
     * @return void
     */
    public function testPartialCaptureOfAuthorizationIsRejected(): void
    {
        $this->gateway->expects($this->never())->method('capture');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'Apple Pay / Google Pay payments must be captured in full in a single invoice.'
        );

        $this->command->execute($this->buildSubject(10.0, 30.0, '60012345'));
    }

    /**
     * Authorize and capture has no prior authorization to compare against: the partial-capture guard must not apply.
     *
     * @return void
     */
    public function testAuthCaptureIsNotSubjectToFullCaptureCheck(): void
    {
        $this->config->method('isWalletEnabled')->willReturn(true);
        $this->info = [
            'wallet_type' => 'googlepay',
            'wallet_token' => 'dG9rZW4=',
        ];

        $this->gateway->expects($this->once())
            ->method('capture')
            ->with($this->anything(), 10.0)
            ->willReturn($this->buildResponse());

        $this->command->execute($this->buildSubject(10.0, 30.0, null));
    }

    /**
     * @return void
     */
    public function testClosedAuthorizationWithoutTokenIsNotCapturedAsPartial(): void
    {
        $this->config->method('isWalletEnabled')->willReturn(true);
        $this->info = [
            'wallet_type' => 'googlepay',
        ];

        $this->gateway->expects($this->never())->method('capture');

        // Falls through to auth+capture, which needs the (spent) token: a command error, not the partial message.
        $this->expectException(CommandException::class);

        $this->command->execute($this->buildSubject(10.0, 30.0, '60012345', true));
    }

    /**
     * @return Response
     */
    private function buildResponse(): Response
    {
        return new Response([
            'transaction_id' => '60012345',
            'acc_number' => 'XXXX1111',
            'card_type' => 'Visa',
        ]);
    }

    /**
     * Build a command subject for an order payment.
     *
     * @param float $amount
     * @param float $authorized
     * @param string|null $authTxnId
     * @param bool $authClosed
     * @return array
     */
    private function buildSubject(
        float $amount,
        float $authorized,
        ?string $authTxnId,
        bool $authClosed = false,
    ): array {
        $order = $this->createMock(Order::class);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('100000123');
        $order->method('getAllVisibleItems')->willReturn([]);

        $authTransaction = false;
        if ($authTxnId !== null) {
            $authTransaction = $this->createMock(Transaction::class);
            $authTransaction->method('getTxnId')->willReturn($authTxnId);
            $authTransaction->method('getIsClosed')->willReturn($authClosed ? 1 : 0);
        }

        $payment = $this->createMock(Payment::class);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getBaseAmountAuthorized')->willReturn($authorized);
        $payment->method('getAuthorizationTransaction')->willReturn($authTransaction);
        $payment->method('getParentTransactionId')->willReturn($authTxnId);
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

        $paymentDataObject = $this->createMock(PaymentDataObjectInterface::class);
        $paymentDataObject->method('getPayment')->willReturn($payment);

        return [
            'payment' => $paymentDataObject,
            'amount' => $amount,
        ];
    }
}
