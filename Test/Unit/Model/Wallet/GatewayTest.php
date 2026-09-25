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

namespace ParadoxLabs\Authnetcim\Test\Unit\Model\Wallet;

use Magento\Framework\HTTP\ClientInterfaceFactory;
use Magento\Framework\Module\Dir;
use Magento\Framework\Registry;
use Magento\Payment\Gateway\Command\CommandException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use Magento\Store\Model\Store;
use ParadoxLabs\Authnetcim\Helper\Data;
use ParadoxLabs\Authnetcim\Model\Wallet\Gateway;
use ParadoxLabs\TokenBase\Model\Gateway\Response;
use ParadoxLabs\TokenBase\Model\Gateway\ResponseFactory;
use ParadoxLabs\TokenBase\Model\Gateway\Xml;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the wallet transaction request shape: opaqueData on the transaction, never a CIM profile, never COF flags.
 */
class GatewayTest extends TestCase
{
    private const TOKEN = 'eyJ2ZXJzaW9uIjoiRUNfdjEiLCJkYXRhIjoiYWJjIn0=';

    private Gateway $gateway;

    private Data|MockObject $helper;

    /**
     * Requests the gateway would have sent: [[request type, params], ...]
     *
     * @var array
     */
    private array $requests = [];

    /**
     * Canned API results, returned in order.
     *
     * @var array
     */
    private array $results = [];

    protected function setUp(): void
    {
        $helperMock   = $this->createMock(Data::class);
        $this->helper = $helperMock;
        $helperMock->method('getArrayValue')
            ->willReturnCallback(
                static function ($data, $path, $default = '') {
                    foreach (explode('/', (string)$path) as $key) {
                        if (!isset($data[$key])) {
                            return $default;
                        }

                        $data = $data[$key];
                    }

                    return $data;
                }
            );

        $storeMock = $this->createMock(Store::class);
        $storeMock->method('getName')->willReturn('Test Store');
        $storeMock->method('getBaseUrl')->willReturn('https://example.com/');
        $helperMock->method('getCurrentStore')->willReturn($storeMock);

        $responseFactoryMock = $this->createMock(ResponseFactory::class);
        $responseFactoryMock->method('create')
            ->willReturnCallback(
                static function () {
                    return new Response();
                }
            );

        $test = $this;

        $this->gateway = new class (
            $helperMock,
            $this->createMock(Xml::class),
            $responseFactoryMock,
            $this->createMock(ClientInterfaceFactory::class),
            $this->createMock(Dir::class),
            $this->createMock(Registry::class),
            $test
        ) extends Gateway {
            /**
             * @param Data $helper
             * @param Xml $xml
             * @param ResponseFactory $responseFactory
             * @param ClientInterfaceFactory $communicatorFactory
             * @param Dir $moduleDir
             * @param Registry $registry
             * @param GatewayTest $test
             */
            public function __construct(
                Data $helper,
                Xml $xml,
                ResponseFactory $responseFactory,
                ClientInterfaceFactory $communicatorFactory,
                Dir $moduleDir,
                Registry $registry,
                private readonly GatewayTest $test,
            ) {
                parent::__construct(
                    $helper,
                    $xml,
                    $responseFactory,
                    $communicatorFactory,
                    $moduleDir,
                    $registry,
                );
            }

            /**
             * @param string $request
             * @param array $params
             * @return array
             */
            protected function runTransaction($request, $params)
            {
                return $this->test->recordRequest($request, $params);
            }

            /**
             * @param string $string
             * @return string
             */
            public function sanitize(string $string): string
            {
                return $this->sanitizeLog($string);
            }
        };

        $this->gateway->init([
            'login' => 'login',
            'password' => 'key',
            'test_mode' => true,
        ]);
    }

    /**
     * Record a request and return the next canned result.
     *
     * @param string $request
     * @param array $params
     * @return array
     */
    public function recordRequest(string $request, array $params): array
    {
        $this->requests[] = [
            $request,
            $params,
        ];

        return array_shift($this->results) ?? $this->buildApprovedResult();
    }

    /**
     * @return void
     */
    public function testAuthorizeSendsOpaqueDataWithoutProfile(): void
    {
        $payment = $this->createPaymentMock();

        $this->gateway->setWalletToken('COMMON.APPLE.INAPP.PAYMENT', self::TOKEN);
        $this->gateway->setOrderContext($payment, true);
        $response = $this->gateway->authorize($payment, 50.25);

        $params = $this->getTransactionRequest();

        $this->assertSame('authOnlyTransaction', $params['transactionType']);
        $this->assertSame('50.25', $params['amount']);
        $this->assertSame(
            [
                'opaqueData' => [
                    'dataDescriptor' => 'COMMON.APPLE.INAPP.PAYMENT',
                    'dataValue' => self::TOKEN,
                ],
            ],
            $params['payment']
        );
        $this->assertArrayNotHasKey('profile', $params);
        $this->assertArrayNotHasKey('paymentProfile', $params);
        $this->assertArrayNotHasKey('processingOptions', $params);
        $this->assertArrayNotHasKey('subsequentAuthInformation', $params);
        $this->assertSame('Jane', $params['billTo']['firstName']);
        $this->assertSame('100000123', $params['order']['invoiceNumber']);
        $this->assertSame('1234567890', $response->getTransactionId());
    }

    /**
     * The payment key must be the first element after amount (Auth.Net XML is order-sensitive).
     *
     * @return void
     */
    public function testPaymentIsOrderedBeforeOrderInfo(): void
    {
        $payment = $this->createPaymentMock();

        $this->gateway->setWalletToken('COMMON.GOOGLE.INAPP.PAYMENT', self::TOKEN);
        $this->gateway->authorize($payment, 10);

        $keys = array_keys($this->getTransactionRequest());

        $this->assertSame(
            [
                'transactionType',
                'amount',
                'payment',
            ],
            array_slice($keys, 0, 3)
        );
    }

    /**
     * @return void
     */
    public function testAuthorizeWithoutTokenThrows(): void
    {
        $this->expectException(CommandException::class);

        $this->gateway->authorize($this->createPaymentMock(), 10);
    }

    /**
     * @return void
     */
    public function testCaptureWithAuthorizationIsPriorAuthCapture(): void
    {
        $this->gateway->capture($this->createPaymentMock(), 10, '1234567890');

        $params = $this->getTransactionRequest();

        $this->assertSame('priorAuthCaptureTransaction', $params['transactionType']);
        $this->assertSame('1234567890', $params['refTransId']);
        $this->assertArrayNotHasKey('payment', $params);
        $this->assertArrayNotHasKey('processingOptions', $params);
    }

    /**
     * @return void
     */
    public function testCaptureWithoutAuthorizationIsAuthCaptureWithToken(): void
    {
        $this->gateway->setWalletToken('COMMON.GOOGLE.INAPP.PAYMENT', self::TOKEN);
        $this->gateway->capture($this->createPaymentMock(), 10);

        $params = $this->getTransactionRequest();

        $this->assertSame('authCaptureTransaction', $params['transactionType']);
        $this->assertSame('COMMON.GOOGLE.INAPP.PAYMENT', $params['payment']['opaqueData']['dataDescriptor']);
        $this->assertArrayNotHasKey('profile', $params);
        $this->assertArrayNotHasKey('processingOptions', $params);
    }

    /**
     * An expired authorization cannot be recaptured: there is no stored card behind a wallet payment.
     *
     * @return void
     */
    public function testCaptureOfExpiredAuthorizationThrows(): void
    {
        $this->results[] = $this->buildErrorResult(16, 'The transaction cannot be found.');

        $this->expectException(CommandException::class);
        $this->expectExceptionMessageMatches('/expired/');

        try {
            $this->gateway->capture($this->createPaymentMock(), 10, '1234567890');
        } finally {
            $this->assertCount(1, $this->requests);
        }
    }

    /**
     * @return void
     */
    public function testRefundSendsLast4AndReference(): void
    {
        $this->results[] = $this->buildApprovedResult('2222222222');

        $this->gateway->refund($this->createPaymentMock('4321'), 5, '1234567890');

        $params = $this->getTransactionRequest();

        $this->assertSame('refundTransaction', $params['transactionType']);
        $this->assertSame(
            [
                'creditCard' => [
                    'cardNumber' => '4321',
                    'expirationDate' => 'XXXX',
                ],
            ],
            $params['payment']
        );
        $this->assertSame('1234567890', $params['refTransId']);
    }

    /**
     * @return void
     */
    public function testRefundWithoutLast4Throws(): void
    {
        $this->expectException(CommandException::class);

        $this->gateway->refund($this->createPaymentMock(''), 5, '1234567890');
    }

    /**
     * @return void
     */
    public function testFullRefundOfUnsettledTransactionFallsBackToVoidAndLogs(): void
    {
        $this->results[] = $this->buildErrorResult(
            54,
            'The referenced transaction does not meet the criteria for issuing a credit.'
        );
        $this->results[] = $this->buildApprovedResult('1234567890');

        $messages = [];
        $this->helper->method('log')->willReturnCallback(
            function ($code, $message) use (&$messages) {
                $messages[] = [
                    $code,
                    $message,
                ];

                return $this->helper;
            }
        );

        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getBaseGrandTotal')->willReturn(5.0);

        $creditmemo = $this->createMock(Creditmemo::class);
        $creditmemo->method('getInvoice')->willReturn($invoice);

        $payment = $this->createPaymentMock('4321');
        $payment->method('getCreditmemo')->willReturn($creditmemo);

        $response = $this->gateway->refund($payment, 5, '1234567890');

        $this->assertCount(2, $this->requests);
        $params = $this->getTransactionRequest();
        $this->assertSame('voidTransaction', $params['transactionType']);
        $this->assertSame('1234567890', $params['refTransId']);
        $this->assertSame('void', $response->getData('transaction_type'));

        $this->assertCount(1, $messages);
        $this->assertSame('authnetcim_wallet', $messages[0][0]);
        $this->assertStringContainsString('Transaction 1234567890 is unsettled; voiding it', $messages[0][1]);
        $this->assertStringContainsString('"response_reason_code":54', $messages[0][1]);
    }

    /**
     * @return void
     */
    public function testLogMasksWalletToken(): void
    {
        $sanitized = $this->gateway->sanitize(
            '<opaqueData><dataDescriptor>COMMON.APPLE.INAPP.PAYMENT</dataDescriptor><dataValue>'
            . self::TOKEN . '</dataValue></opaqueData>'
        );

        $this->assertStringNotContainsString(self::TOKEN, $sanitized);
        $this->assertStringContainsString('<dataValue>XXX</dataValue>', $sanitized);
    }

    /**
     * @return array
     */
    private function getTransactionRequest(): array
    {
        $this->assertNotEmpty($this->requests);

        [$request, $params] = end($this->requests);
        $this->assertSame('createTransactionRequest', $request);

        return $params['transactionRequest'];
    }

    /**
     * @param string $last4
     * @return Payment|MockObject
     */
    private function createPaymentMock(string $last4 = '1111'): Payment|MockObject
    {
        $address = $this->createMock(Address::class);
        $address->method('getFirstname')->willReturn('Jane');
        $address->method('getLastname')->willReturn('Doe');
        $address->method('getStreet')->willReturn(['1 Main St']);
        $address->method('getCity')->willReturn('Lancaster');
        $address->method('getRegionCode')->willReturn('PA');
        $address->method('getPostcode')->willReturn('17601');
        $address->method('getCountryId')->willReturn('US');

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('100000123');
        $order->method('getCustomerEmail')->willReturn('customer@example.com');
        $order->method('getBillingAddress')->willReturn($address);
        $order->method('getShippingAddress')->willReturn($address);

        $payment = $this->createMock(Payment::class);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getCcLast4')->willReturn($last4);

        return $payment;
    }

    /**
     * @param string $transactionId
     * @return array
     */
    private function buildApprovedResult(string $transactionId = '1234567890'): array
    {
        return [
            'messages' => [
                'resultCode' => 'Ok',
                'message' => [
                    'code' => 'I00001',
                    'text' => 'Successful.',
                ],
            ],
            'transactionResponse' => [
                'responseCode' => '1',
                'authCode' => 'ABC123',
                'avsResultCode' => 'Y',
                'transId' => $transactionId,
                'accountNumber' => 'XXXX1111',
                'accountType' => 'Visa',
            ],
        ];
    }

    /**
     * @param int $errorCode
     * @param string $errorText
     * @return array
     */
    private function buildErrorResult(int $errorCode, string $errorText): array
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
                'responseCode' => 3,
                'transId' => '0',
                'errors' => [
                    'error' => [
                        'errorCode' => $errorCode,
                        'errorText' => $errorText,
                    ],
                ],
            ],
        ];
    }
}
