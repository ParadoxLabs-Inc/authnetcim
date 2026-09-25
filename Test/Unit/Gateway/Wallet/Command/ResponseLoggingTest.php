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

use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\Repository as TransactionRepository;
use ParadoxLabs\Authnetcim\Gateway\Wallet\Command\AbstractCommand;
use ParadoxLabs\Authnetcim\Gateway\Wallet\Command\AcceptPaymentCommand;
use ParadoxLabs\Authnetcim\Gateway\Wallet\Command\AuthorizeCommand;
use ParadoxLabs\Authnetcim\Gateway\Wallet\Command\CaptureCommand;
use ParadoxLabs\Authnetcim\Gateway\Wallet\Command\DenyPaymentCommand;
use ParadoxLabs\Authnetcim\Gateway\Wallet\Command\FetchTransactionInfoCommand;
use ParadoxLabs\Authnetcim\Gateway\Wallet\Command\RefundCommand;
use ParadoxLabs\Authnetcim\Gateway\Wallet\Command\VoidCommand;
use ParadoxLabs\Authnetcim\Model\Wallet\Config;
use ParadoxLabs\Authnetcim\Model\Wallet\Gateway;
use ParadoxLabs\Authnetcim\Model\Wallet\GatewayProvider;
use ParadoxLabs\Authnetcim\Model\Wallet\PaymentResponseHandler;
use ParadoxLabs\TokenBase\Helper\Data as Helper;
use ParadoxLabs\TokenBase\Model\Gateway\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Every wallet command writes the gateway response to the log after the transaction, like the card method.
 */
class ResponseLoggingTest extends TestCase
{
    private const TOKEN = 'dG9rZW4tc2VjcmV0';

    private Gateway|MockObject $gateway;
    private GatewayProvider|MockObject $provider;
    private Config|MockObject $config;
    private Helper|MockObject $helper;
    private PaymentResponseHandler $responseHandler;

    /**
     * Log messages written, as [code, message].
     *
     * @var array
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
                $this->messages[] = [
                    $code,
                    $message,
                ];

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
    public function testAuthorizeLogsResponseWithoutToken(): void
    {
        $this->info = [
            'wallet_type' => 'googlepay',
            'wallet_token' => self::TOKEN,
        ];

        $data = $this->buildResponseData('auth_only');
        $this->gateway->expects($this->once())->method('authorize')->willReturn(new Response($data));

        $this->createCommand(AuthorizeCommand::class)->execute($this->buildSubject());

        $this->assertResponseLogged($data);
    }

    /**
     * @return void
     */
    public function testPriorAuthCaptureLogsResponse(): void
    {
        $data = $this->buildResponseData('prior_auth_capture');
        $this->gateway->expects($this->once())
            ->method('capture')
            ->with($this->anything(), 6.0, '60012345')
            ->willReturn(new Response($data));

        $this->createCommand(CaptureCommand::class)->execute($this->buildSubject(true));

        $this->assertResponseLogged($data);
    }

    /**
     * @return void
     */
    public function testAuthCaptureLogsResponseWithoutToken(): void
    {
        $this->info = [
            'wallet_type' => 'applepay',
            'wallet_token' => self::TOKEN,
        ];

        $data = $this->buildResponseData('auth_capture');
        $this->gateway->expects($this->once())->method('capture')->willReturn(new Response($data));

        $this->createCommand(CaptureCommand::class)->execute($this->buildSubject());

        $this->assertResponseLogged($data);
    }

    /**
     * The 54 -> void fallback happens inside the gateway; the command logs whichever response comes back.
     *
     * @return void
     */
    public function testRefundLogsResponseIncludingVoidFallback(): void
    {
        $data = $this->buildResponseData('void');
        $this->gateway->expects($this->once())
            ->method('refund')
            ->with($this->anything(), 6.0, '60012345')
            ->willReturn(new Response($data));

        $this->createCommand(RefundCommand::class)->execute($this->buildSubject());

        $this->assertResponseLogged($data);
    }

    /**
     * @return void
     */
    public function testVoidLogsResponse(): void
    {
        $data = $this->buildResponseData('void');
        $this->gateway->expects($this->once())
            ->method('void')
            ->with($this->anything(), '60012345')
            ->willReturn(new Response($data));

        $this->createCommand(VoidCommand::class)->execute($this->buildSubject());

        $this->assertResponseLogged($data);
    }

    /**
     * @return void
     */
    public function testAcceptPaymentLogsResponse(): void
    {
        $data = $this->buildResponseData('prior_auth_capture') + [
            'is_approved' => true,
            'is_denied' => false,
        ];
        $this->gateway->expects($this->once())->method('acceptPayment')->willReturn(new Response($data));

        $this->createCommand(AcceptPaymentCommand::class)->execute($this->buildSubject(true));

        $this->assertResponseLogged($data);
    }

    /**
     * @return void
     */
    public function testDenyPaymentLogsResponse(): void
    {
        $data = $this->buildResponseData('void') + [
            'is_approved' => false,
            'is_denied' => true,
        ];
        $this->gateway->expects($this->once())->method('denyPayment')->willReturn(new Response($data));

        $this->createCommand(DenyPaymentCommand::class)->execute($this->buildSubject());

        $this->assertResponseLogged($data);
    }

    /**
     * Transaction details carry the customer's email and IP; those are masked in the log.
     *
     * @return void
     */
    public function testFetchTransactionInfoLogsResponseWithCustomerPiiMasked(): void
    {
        $data = $this->buildResponseData('auth_only') + [
            'transaction_status' => 'authorizedPendingCapture',
            'customer_email' => 'jane@example.com',
            'customer_ip' => '203.0.113.5',
            'is_approved' => true,
            'is_denied' => false,
        ];
        $this->gateway->expects($this->once())
            ->method('fraudUpdate')
            ->with($this->anything(), '60012345')
            ->willReturn(new Response($data));

        $subject = $this->buildSubject(true) + [
            'transactionId' => '60012345',
        ];
        $this->createCommand(FetchTransactionInfoCommand::class)->execute($subject);

        $expected = array_replace(
            $data,
            [
                'customer_email' => 'XXX',
                'customer_ip' => 'XXX',
            ]
        );

        $this->assertResponseLogged($expected);

        foreach ($this->messages as [, $message]) {
            $this->assertStringNotContainsString('jane@example.com', $message);
            $this->assertStringNotContainsString('203.0.113.5', $message);
        }
    }

    /**
     * Assert the response JSON was logged to the wallet log, and that the wallet token never was.
     *
     * @param array $expectedData Response data as logged (Response flattens it and stringifies booleans).
     * @return void
     */
    private function assertResponseLogged(array $expectedData): void
    {
        $this->assertContains(
            [
                'authnetcim_wallet',
                json_encode((new Response($expectedData))->getData()),
            ],
            $this->messages
        );

        foreach ($this->messages as [, $message]) {
            $this->assertStringNotContainsString(self::TOKEN, $message);
        }
    }

    /**
     * @param string $class
     * @return AbstractCommand
     */
    private function createCommand(string $class): AbstractCommand
    {
        return new $class(
            $this->provider,
            $this->config,
            $this->responseHandler,
            $this->helper,
        );
    }

    /**
     * @param string $transactionType
     * @return array
     */
    private function buildResponseData(string $transactionType): array
    {
        return [
            'response_code' => 1,
            'response_reason_code' => 1,
            'response_reason_text' => 'This transaction has been approved.',
            'auth_code' => 'ABC123',
            'transaction_id' => '60012399',
            'invoice_number' => '000002512',
            'amount' => 6.0,
            'transaction_type' => $transactionType,
            'acc_number' => 'XXXX1111',
            'card_type' => 'Visa',
            'is_fraud' => false,
            'is_error' => false,
        ];
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
        $payment->method('getLastTransId')->willReturn('60012345');
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

        if ($withOpenAuthorization === true) {
            $authTransaction = $this->createMock(Transaction::class);
            $authTransaction->method('getIsClosed')->willReturn(0);
            $authTransaction->method('getTxnId')->willReturn('60012345');

            $payment->method('getAuthorizationTransaction')->willReturn($authTransaction);
        } else {
            $payment->method('getParentTransactionId')->willReturn('60012345');
        }

        $paymentDataObject = $this->createMock(PaymentDataObjectInterface::class);
        $paymentDataObject->method('getPayment')->willReturn($payment);

        return [
            'payment' => $paymentDataObject,
            'amount' => 6.0,
        ];
    }
}
