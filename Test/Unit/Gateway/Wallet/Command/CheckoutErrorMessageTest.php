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

use Magento\Framework\Phrase;
use Magento\Payment\Gateway\Command\CommandException;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\Repository as TransactionRepository;
use ParadoxLabs\Authnetcim\Gateway\Wallet\Command\AuthorizeCommand;
use ParadoxLabs\Authnetcim\Gateway\Wallet\Command\CaptureCommand;
use ParadoxLabs\Authnetcim\Model\Wallet\Config;
use ParadoxLabs\Authnetcim\Model\Wallet\Gateway;
use ParadoxLabs\Authnetcim\Model\Wallet\GatewayProvider;
use ParadoxLabs\Authnetcim\Model\Wallet\PaymentResponseHandler;
use ParadoxLabs\TokenBase\Helper\Data as Helper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Checkout transaction failures show the customer a short message; the gateway detail stays with the merchant.
 */
class CheckoutErrorMessageTest extends TestCase
{
    private const GATEWAY_ERROR = 'Authorize.Net CIM Gateway Connection error: Authorize.Net CIM Gateway: The '
        . 'transaction was unsuccessful. There was an error processing the payment data. Unable to decrypt data. '
        . '(E00027)';

    private Gateway|MockObject $gateway;
    private GatewayProvider|MockObject $provider;
    private Config|MockObject $config;
    private Helper|MockObject $helper;
    private PaymentResponseHandler $responseHandler;

    /**
     * Log messages written.
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

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(Gateway::class);

        $this->provider = $this->createMock(GatewayProvider::class);
        $this->provider->method('get')->willReturn($this->gateway);

        $this->config = $this->createMock(Config::class);
        $this->config->method('isWalletEnabled')->willReturn(true);

        $this->helper = $this->createMock(Helper::class);
        $this->helper->method('log')->willReturnCallback(
            function ($code, $message) {
                $this->messages[] = $message;

                return $this->helper;
            }
        );

        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getByTransactionId')->willReturn(false);

        $this->responseHandler = new PaymentResponseHandler($transactionRepository);
    }

    /**
     * @return void
     */
    public function testGatewayErrorShowsGenericWalletMessage(): void
    {
        $gatewayException = $this->createGatewayException();

        $this->gateway->method('authorize')->willThrowException($gatewayException);
        $this->gateway->method('getLastResponse')->willReturn($this->buildLastResponse(3));

        $exception = $this->runAuthorize('googlepay');

        $this->assertSame(
            'Your Google Pay payment could not be completed. Please try again or choose another payment method.',
            $exception->getMessage()
        );
        $this->assertSame($gatewayException, $exception->getPrevious());
        $this->assertGatewayErrorLogged();
    }

    /**
     * @return void
     */
    public function testDeclineShowsDeclineMessage(): void
    {
        $this->gateway->method('authorize')->willThrowException(
            new CommandException(
                __('Authorize.Net CIM Gateway: The transaction was unsuccessful. This transaction has been declined.')
            )
        );
        $this->gateway->method('getLastResponse')->willReturn($this->buildLastResponse(2));

        $exception = $this->runAuthorize('applepay');

        $this->assertSame(
            'Your Apple Pay payment was declined. Please try another card or payment method.',
            $exception->getMessage()
        );
    }

    /**
     * An unexpected (non-gateway) failure gets the generic message too, and its detail is logged.
     *
     * @return void
     */
    public function testUnexpectedExceptionShowsGenericWalletMessage(): void
    {
        $cause = new RuntimeException('cURL error 28: Operation timed out');

        $this->gateway->method('authorize')->willThrowException($cause);
        $this->gateway->method('getLastResponse')->willReturn(null);

        $exception = $this->runAuthorize('googlepay');

        $this->assertSame(
            'Your Google Pay payment could not be completed. Please try again or choose another payment method.',
            $exception->getMessage()
        );
        $this->assertSame($cause, $exception->getPrevious());
        $this->assertContains('Wallet payment failed for order 000002512: ' . $cause->getMessage(), $this->messages);
    }

    /**
     * @return void
     */
    public function testAuthCaptureErrorShowsGenericWalletMessage(): void
    {
        $this->info = [
            'wallet_type' => 'googlepay',
            'wallet_token' => 'dG9rZW4=',
        ];

        $this->gateway->method('capture')->willThrowException($this->createGatewayException());
        $this->gateway->method('getLastResponse')->willReturn($this->buildLastResponse(3));

        try {
            $this->createCommand(CaptureCommand::class)->execute($this->buildSubject());
            $this->fail('Expected CommandException');
        } catch (CommandException $exception) {
            $this->assertStringStartsWith('Your Google Pay payment could not be completed.', $exception->getMessage());
        }

        $this->assertGatewayErrorLogged();
    }

    /**
     * Prior-auth capture is an admin action: the merchant sees the gateway's own error.
     *
     * @return void
     */
    public function testPriorAuthCaptureErrorIsNotReplaced(): void
    {
        $gatewayException = $this->createGatewayException();
        $this->gateway->method('capture')->willThrowException($gatewayException);

        try {
            $this->createCommand(CaptureCommand::class)->execute($this->buildSubject(true));
            $this->fail('Expected CommandException');
        } catch (CommandException $exception) {
            $this->assertSame($gatewayException, $exception);
        }
    }

    /**
     * @param string $walletType
     * @return CommandException
     */
    private function runAuthorize(string $walletType): CommandException
    {
        $this->info = [
            'wallet_type' => $walletType,
            'wallet_token' => 'dG9rZW4=',
        ];

        try {
            $this->createCommand(AuthorizeCommand::class)->execute($this->buildSubject());
        } catch (CommandException $exception) {
            $this->assertStringNotContainsString('Authorize.Net', $exception->getMessage());

            return $exception;
        }

        $this->fail('Expected CommandException');
    }

    /**
     * The exception core Model\Gateway::runTransaction() throws for an E00027 'Unable to decrypt data' response.
     *
     * @return CommandException
     */
    private function createGatewayException(): CommandException
    {
        $message = self::GATEWAY_ERROR;

        return new CommandException(new Phrase($message));
    }

    /**
     * @return void
     */
    private function assertGatewayErrorLogged(): void
    {
        $this->assertContains('Wallet payment failed for order 000002512: ' . self::GATEWAY_ERROR, $this->messages);
    }

    /**
     * @param int $responseCode
     * @return array
     */
    private function buildLastResponse(int $responseCode): array
    {
        return [
            'messages' => [
                'resultCode' => 'Error',
                'message' => [
                    'code' => 'E00027',
                    'text' => 'The transaction was unsuccessful.',
                ],
            ],
            'transactionResponse' => [
                'responseCode' => (string)$responseCode,
                'transId' => '0',
            ],
        ];
    }

    /**
     * @param string $class
     * @return AuthorizeCommand|CaptureCommand
     */
    private function createCommand(string $class): AuthorizeCommand|CaptureCommand
    {
        return new $class(
            $this->provider,
            $this->config,
            $this->responseHandler,
            $this->helper,
        );
    }

    /**
     * @param bool $withOpenAuthorization
     * @return array
     */
    private function buildSubject(bool $withOpenAuthorization = false): array
    {
        $order = $this->createMock(Order::class);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('000002512');
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

        if ($withOpenAuthorization === true) {
            $authTransaction = $this->createMock(Transaction::class);
            $authTransaction->method('getIsClosed')->willReturn(0);
            $authTransaction->method('getTxnId')->willReturn('60012345');

            $payment->method('getAuthorizationTransaction')->willReturn($authTransaction);
        }

        $paymentDataObject = $this->createMock(PaymentDataObjectInterface::class);
        $paymentDataObject->method('getPayment')->willReturn($payment);

        return [
            'payment' => $paymentDataObject,
            'amount' => 6.0,
        ];
    }
}
