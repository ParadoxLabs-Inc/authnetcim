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

namespace ParadoxLabs\Authnetcim\Test\Integration\Wallet;

use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\PaymentMethodManagementInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use ParadoxLabs\Authnetcim\Model\Wallet\WalletType;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Apple Pay / Google Pay checkout through the real quote-to-order submit, with the Authorize.net HTTP boundary
 * stubbed ({@see WalletStubTrait}).
 *
 * Assertions are on what reached the (stubbed) gateway and on the persisted sales records, never on intermediate
 * return values.
 *
 * NOTE: class-level @magentoConfigFixture is ignored by this harness; config fixtures are on the methods.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation enabled
 */
class WalletCheckoutTest extends TestCase
{
    use WalletStubTrait;

    /**
     * @var ObjectManager|null
     */
    private ?ObjectManager $objectManager = null;

    /**
     * @var TestHandler|null
     */
    private ?TestHandler $logHandler = null;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = Bootstrap::getObjectManager();
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        $this->unregisterWalletStub($this->objectManager);

        // Runtime config isn't rolled back like config fixtures are.
        $this->setApplePayIdentity(null, null);

        if ($this->logHandler !== null) {
            $this->getTokenbaseLogger()->popHandler();
            $this->logHandler = null;
        }

        parent::tearDown();
    }

    /**
     * Google Pay, authorize only: a processing order with an open authorization, card details from the gateway,
     * and the single-use token gone from both the order and the cart.
     *
     * @magentoDataFixture ParadoxLabs_Authnetcim::Test/Integration/_files/wallet_quote.php
     * @magentoConfigFixture current_store payment/authnetcim/test 1
     * @magentoConfigFixture current_store payment/authnetcim/login integration_login
     * @magentoConfigFixture current_store payment/authnetcim/trans_key integrationkey00
     * @magentoConfigFixture current_store payment/authnetcim_wallet/active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/payment_action authorize
     * @magentoConfigFixture current_store payment/authnetcim_wallet/googlepay_active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/googlepay_gateway_merchant_id 123456
     * @return void
     */
    public function testGooglePayAuthorizePlacesProcessingOrder(): void
    {
        $this->registerWalletStub($this->objectManager);

        $order = $this->placeWalletOrder($this->objectManager, $this->googlePayData());

        $this->assertAuthorizedWalletOrder(
            $order,
            WalletType::GOOGLE_PAY,
            'COMMON.GOOGLE.INAPP.PAYMENT',
            $this->googlePayToken()
        );
    }

    /**
     * Apple Pay, authorize only: same contract, with the Apple descriptor on the opaqueData.
     *
     * @magentoDataFixture ParadoxLabs_Authnetcim::Test/Integration/_files/wallet_quote.php
     * @magentoConfigFixture current_store payment/authnetcim/test 1
     * @magentoConfigFixture current_store payment/authnetcim/login integration_login
     * @magentoConfigFixture current_store payment/authnetcim/trans_key integrationkey00
     * @magentoConfigFixture current_store payment/authnetcim_wallet/active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/payment_action authorize
     * @magentoConfigFixture current_store payment/authnetcim_wallet/applepay_active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/applepay_merchant_identifier merchant.com.example
     * @return void
     */
    public function testApplePayAuthorizePlacesProcessingOrder(): void
    {
        $this->configureApplePayIdentity();
        $this->registerWalletStub($this->objectManager);

        $order = $this->placeWalletOrder($this->objectManager, $this->applePayData());

        $this->assertAuthorizedWalletOrder(
            $order,
            WalletType::APPLE_PAY,
            'COMMON.APPLE.INAPP.PAYMENT',
            $this->applePayToken()
        );
    }

    /**
     * Authorize and capture: one authCaptureTransaction with the wallet token, and a paid invoice at checkout.
     *
     * @magentoDataFixture ParadoxLabs_Authnetcim::Test/Integration/_files/wallet_quote.php
     * @magentoConfigFixture current_store payment/authnetcim/test 1
     * @magentoConfigFixture current_store payment/authnetcim/login integration_login
     * @magentoConfigFixture current_store payment/authnetcim/trans_key integrationkey00
     * @magentoConfigFixture current_store payment/authnetcim_wallet/active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/payment_action authorize_capture
     * @magentoConfigFixture current_store payment/authnetcim_wallet/googlepay_active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/googlepay_gateway_merchant_id 123456
     * @return void
     */
    public function testAuthorizeCapturePlacesInvoicedPaidOrder(): void
    {
        $this->registerWalletStub($this->objectManager);

        $order = $this->placeWalletOrder($this->objectManager, $this->googlePayData());

        $this->assertSame(
            ['authCaptureTransaction'],
            $this->getTransactionTypes(),
            'Authorize and capture must be a single authCaptureTransaction.'
        );

        $request = $this->getTransactionRequests('authCaptureTransaction')[0];
        $this->assertSame('COMMON.GOOGLE.INAPP.PAYMENT', $this->readTag($request, 'dataDescriptor'));
        $this->assertSame($this->googlePayToken(), $this->readTag($request, 'dataValue'));
        $this->assertSame(
            number_format((float)$order->getBaseGrandTotal(), 2, '.', ''),
            $this->readTag($request, 'amount')
        );

        $this->assertSame(Order::STATE_PROCESSING, $order->getState());
        $this->assertEquals(
            (float)$order->getBaseGrandTotal(),
            (float)$order->getBaseTotalPaid(),
            'The order must be fully paid at checkout.'
        );

        $invoices = $order->getInvoiceCollection();
        $this->assertCount(1, $invoices, 'Checkout must create exactly one invoice.');

        /** @var Invoice $invoice */
        $invoice = $invoices->getFirstItem();
        $this->assertSame(Invoice::STATE_PAID, (int)$invoice->getState());

        $this->assertCount(
            1,
            $this->loadTransactions($this->objectManager, $order, Transaction::TYPE_CAPTURE),
            'A capture transaction must be recorded.'
        );
        $this->assertNull($order->getPayment()->getAdditionalInformation('wallet_token'));
    }

    /**
     * GraphQL/Hyvä placeOrder re-imports the cart payment's own data, which carries the token only nested under
     * additional_information. That replay must not wipe the token assigned by the earlier setPaymentMethod request.
     *
     * @magentoDataFixture ParadoxLabs_Authnetcim::Test/Integration/_files/wallet_quote.php
     * @magentoConfigFixture current_store payment/authnetcim/test 1
     * @magentoConfigFixture current_store payment/authnetcim/login integration_login
     * @magentoConfigFixture current_store payment/authnetcim/trans_key integrationkey00
     * @magentoConfigFixture current_store payment/authnetcim_wallet/active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/payment_action authorize
     * @magentoConfigFixture current_store payment/authnetcim_wallet/googlepay_active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/googlepay_gateway_merchant_id 123456
     * @return void
     */
    public function testPlaceOrderReplayKeepsAssignedToken(): void
    {
        $this->registerWalletStub($this->objectManager);

        // Request 1: set the payment method on the cart (REST/GraphQL setPaymentMethodOnCart).
        $cartId = (int)$this->loadWalletQuote($this->objectManager)->getId();
        $this->objectManager->create(PaymentMethodManagementInterface::class)->set(
            $cartId,
            $this->buildWalletPayment($this->objectManager, $this->googlePayData())
        );

        $this->simulateNewRequest($this->objectManager);

        // Request 2: placeOrder passes the cart's own payment back in, which QuoteManagement re-imports.
        $quote = $this->loadWalletQuote($this->objectManager);
        $this->assertSame(
            $this->googlePayToken(),
            $quote->getPayment()->getAdditionalInformation('wallet_token'),
            'Precondition: the token is on the saved cart payment.'
        );

        $orderId = $this->objectManager->create(CartManagementInterface::class)->placeOrder(
            (int)$quote->getId(),
            $quote->getPayment()
        );

        $this->simulateNewRequest($this->objectManager);
        $order = $this->loadWalletOrder($this->objectManager, (int)$orderId);

        $requests = $this->getTransactionRequests('authOnlyTransaction');
        $this->assertCount(1, $requests, 'The replayed checkout must still authorize.');
        $this->assertSame(
            $this->googlePayToken(),
            $this->readTag($requests[0], 'dataValue'),
            'The re-import must leave the assigned wallet token intact.'
        );
        $this->assertSame(Order::STATE_PROCESSING, $order->getState());
        $this->assertNull($order->getPayment()->getAdditionalInformation('wallet_token'));
        $this->assertNull(
            $this->loadWalletQuote($this->objectManager)->getPayment()->getAdditionalInformation('wallet_token')
        );
    }

    /**
     * An unknown wallet_type is rejected at payment assignment, before any gateway traffic.
     *
     * @magentoDataFixture ParadoxLabs_Authnetcim::Test/Integration/_files/wallet_quote.php
     * @magentoConfigFixture current_store payment/authnetcim/test 1
     * @magentoConfigFixture current_store payment/authnetcim/login integration_login
     * @magentoConfigFixture current_store payment/authnetcim/trans_key integrationkey00
     * @magentoConfigFixture current_store payment/authnetcim_wallet/active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/googlepay_active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/googlepay_gateway_merchant_id 123456
     * @return void
     */
    public function testInvalidWalletTypeIsRejected(): void
    {
        $stub = $this->registerWalletStub($this->objectManager);

        $data                = $this->googlePayData();
        $data['wallet_type'] = 'samsungpay';

        $exception = $this->placeExpectingFailure($data);

        $this->assertInstanceOf(LocalizedException::class, $exception);
        $this->assertSame('Invalid wallet type.', $exception->getMessage());
        $this->assertSame([], $stub->requests, 'An invalid wallet type must never reach the gateway.');
        $this->assertNoOrderPlaced();
    }

    /**
     * A token that is not base64 is rejected at payment assignment, before any gateway traffic.
     *
     * @magentoDataFixture ParadoxLabs_Authnetcim::Test/Integration/_files/wallet_quote.php
     * @magentoConfigFixture current_store payment/authnetcim/test 1
     * @magentoConfigFixture current_store payment/authnetcim/login integration_login
     * @magentoConfigFixture current_store payment/authnetcim/trans_key integrationkey00
     * @magentoConfigFixture current_store payment/authnetcim_wallet/active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/googlepay_active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/googlepay_gateway_merchant_id 123456
     * @return void
     */
    public function testMalformedTokenIsRejected(): void
    {
        $stub = $this->registerWalletStub($this->objectManager);

        $data                 = $this->googlePayData();
        $data['wallet_token'] = '<script>not-base64</script>';

        $exception = $this->placeExpectingFailure($data);

        $this->assertInstanceOf(LocalizedException::class, $exception);
        $this->assertSame('Invalid wallet payment data. Please try again.', $exception->getMessage());
        $this->assertSame([], $stub->requests);
        $this->assertNoOrderPlaced();
    }

    /**
     * A valid wallet type that is not configured (Apple Pay here) fails at authorize without a gateway call, and the
     * checkout-failure observer clears the token from the cart.
     *
     * @magentoDataFixture ParadoxLabs_Authnetcim::Test/Integration/_files/wallet_quote.php
     * @magentoConfigFixture current_store payment/authnetcim/test 1
     * @magentoConfigFixture current_store payment/authnetcim/login integration_login
     * @magentoConfigFixture current_store payment/authnetcim/trans_key integrationkey00
     * @magentoConfigFixture current_store payment/authnetcim_wallet/active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/googlepay_active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/googlepay_gateway_merchant_id 123456
     * @return void
     */
    public function testUnconfiguredWalletTypeFailsWithoutGatewayCall(): void
    {
        $stub = $this->registerWalletStub($this->objectManager);

        $exception = $this->placeExpectingFailure($this->applePayData());

        $this->assertInstanceOf(LocalizedException::class, $exception);
        $this->assertStringContainsString('The selected wallet is not available', $exception->getMessage());
        $this->assertSame([], $stub->requests, 'An unconfigured wallet must never reach the gateway.');
        $this->assertNoOrderPlaced();

        $this->simulateNewRequest($this->objectManager);
        $this->assertNull(
            $this->loadWalletQuote($this->objectManager)->getPayment()->getAdditionalInformation('wallet_token'),
            'A failed checkout must not leave the single-use token on the cart.'
        );
    }

    /**
     * A declined authorization fails checkout, and the token is cleared from the cart.
     *
     * @magentoDataFixture ParadoxLabs_Authnetcim::Test/Integration/_files/wallet_quote.php
     * @magentoConfigFixture current_store payment/authnetcim/test 1
     * @magentoConfigFixture current_store payment/authnetcim/login integration_login
     * @magentoConfigFixture current_store payment/authnetcim/trans_key integrationkey00
     * @magentoConfigFixture current_store payment/authnetcim_wallet/active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/googlepay_active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/googlepay_gateway_merchant_id 123456
     * @return void
     */
    public function testDeclinedAuthorizationFailsCheckoutAndClearsToken(): void
    {
        $this->registerWalletStub(
            $this->objectManager,
            fn (string $type, string $xml): ?string => $type === 'createTransactionRequest'
                ? $this->buildTransactionErrorXml(2, 2, 'This transaction has been declined.')
                : null
        );

        $exception = $this->placeExpectingFailure($this->googlePayData());

        $this->assertInstanceOf(LocalizedException::class, $exception);
        $this->assertSame(
            'Your Google Pay payment was declined. Please try another card or payment method.',
            $exception->getMessage(),
            'The customer must get a readable decline message, not the raw gateway error.'
        );
        $this->assertSame(['authOnlyTransaction'], $this->getTransactionTypes());
        $this->assertNoOrderPlaced();

        $this->simulateNewRequest($this->objectManager);
        $this->assertNull(
            $this->loadWalletQuote($this->objectManager)->getPayment()->getAdditionalInformation('wallet_token'),
            'A declined checkout must not leave the single-use token on the cart.'
        );
    }

    /**
     * The gateway debug log (sandbox mode) records the request with the wallet token masked, and the method log
     * records the gateway response after the transaction.
     *
     * @magentoDataFixture ParadoxLabs_Authnetcim::Test/Integration/_files/wallet_quote.php
     * @magentoConfigFixture current_store payment/authnetcim/test 1
     * @magentoConfigFixture current_store payment/authnetcim/login integration_login
     * @magentoConfigFixture current_store payment/authnetcim/trans_key integrationkey00
     * @magentoConfigFixture current_store payment/authnetcim_wallet/active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/googlepay_active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/googlepay_gateway_merchant_id 123456
     * @return void
     */
    public function testGatewayLogMasksWalletToken(): void
    {
        $this->registerWalletStub($this->objectManager);

        $this->logHandler = new TestHandler();
        $this->getTokenbaseLogger()->pushHandler($this->logHandler);

        $this->placeWalletOrder($this->objectManager, $this->googlePayData());

        $messages = array_map(
            static fn ($record): string => (string)$record['message'],
            $this->logHandler->getRecords()
        );
        $allMessages = implode("\n", $messages);

        $this->assertStringContainsString(
            '<dataValue>XXX</dataValue>',
            $allMessages,
            'The logged request must show the masked wallet token.'
        );
        $this->assertStringContainsString('COMMON.GOOGLE.INAPP.PAYMENT', $allMessages);
        $this->assertStringNotContainsString(
            $this->googlePayToken(),
            $allMessages,
            'The wallet token must never be written to the log.'
        );

        $this->assertTrue(
            $this->logHandler->hasInfoThatContains('"transaction_id":"8800000101"'),
            'The authorization response must be logged (INFO) after the transaction.'
        );
    }

    /**
     * Assert the full authorize-only contract for a placed wallet order.
     *
     * @param Order $order
     * @param string $walletType
     * @param string $descriptor
     * @param string $token
     * @return void
     */
    private function assertAuthorizedWalletOrder(
        Order $order,
        string $walletType,
        string $descriptor,
        string $token,
    ): void {
        $this->assertSame(
            ['authOnlyTransaction'],
            $this->getTransactionTypes(),
            'Authorize must be a single authOnlyTransaction.'
        );

        $request = $this->getTransactionRequests('authOnlyTransaction')[0];
        $this->assertSame($descriptor, $this->readTag($request, 'dataDescriptor'));
        $this->assertSame($token, $this->readTag($request, 'dataValue'));
        $this->assertSame(
            number_format((float)$order->getBaseGrandTotal(), 2, '.', ''),
            $this->readTag($request, 'amount')
        );
        // invoiceNumber is a noSymbols gateway field, so the fixture's '_' goes out as ' '.
        $this->assertSame('test wallet quote', $this->readTag($request, 'invoiceNumber'));
        $this->assertStringNotContainsString('<createProfile>', $request, 'A wallet must never create a CIM profile.');
        $this->assertStringNotContainsString('<customerProfileId>', $request);
        $this->assertStringNotContainsString(
            '<processingOptions>',
            $request,
            'A wallet token is not a stored credential: no card-on-file indicators.'
        );
        $this->assertStringNotContainsString('<creditCard>', $request);

        $this->assertSame(Order::STATE_PROCESSING, $order->getState());
        $this->assertSame(WalletType::METHOD_CODE, $order->getPayment()->getMethod());
        $this->assertEquals(
            (float)$order->getBaseGrandTotal(),
            (float)$order->getPayment()->getBaseAmountAuthorized()
        );

        $authorizations = $this->loadTransactions($this->objectManager, $order, Transaction::TYPE_AUTH);
        $this->assertCount(1, $authorizations, 'An authorization transaction must be recorded.');
        $this->assertSame(0, (int)$authorizations->getFirstItem()->getIsClosed(), 'The authorization must stay open.');
        $this->assertCount(0, $order->getInvoiceCollection(), 'Authorize only must not invoice.');

        $payment = $order->getPayment();
        $this->assertSame($walletType, $payment->getAdditionalInformation('wallet_type'));
        $this->assertSame('Visa 1111', $payment->getAdditionalInformation('card_display'));
        $this->assertNull(
            $payment->getAdditionalInformation('wallet_token'),
            'The single-use token must not be persisted on the order.'
        );
        $this->assertSame('1111', $payment->getCcLast4());
        $this->assertSame('VI', $payment->getCcType());
        $this->assertSame((string)$authorizations->getFirstItem()->getTxnId(), (string)$payment->getLastTransId());

        $this->assertNull(
            $this->loadWalletQuote($this->objectManager)->getPayment()->getAdditionalInformation('wallet_token'),
            'The single-use token must not be persisted on the cart.'
        );
    }

    /**
     * Place the fixture cart and return the exception it failed with.
     *
     * @param array $additionalData
     * @return Throwable
     */
    private function placeExpectingFailure(array $additionalData): Throwable
    {
        try {
            $this->placeWalletOrder($this->objectManager, $additionalData);
        } catch (Throwable $exception) {
            return $exception;
        }

        $this->fail('Checkout was expected to fail.');
    }

    /**
     * Assert the fixture cart did not become an order.
     *
     * @return void
     */
    private function assertNoOrderPlaced(): void
    {
        $orders = $this->objectManager->create(OrderCollectionFactory::class)
            ->create()
            ->addFieldToFilter('increment_id', 'test_wallet_quote');

        $this->assertCount(0, $orders, 'No order may be created.');
    }

    /**
     * Store a real, currently-valid Apple Pay identity certificate and key for the current store.
     *
     * Apple Pay is hidden unless the certificate parses and hasn't expired, so a placeholder string (as a config
     * fixture would have to be) leaves the method unavailable.
     *
     * @return void
     */
    private function configureApplePayIdentity(): void
    {
        $key  = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $csr  = openssl_csr_new(['commonName' => 'merchant.com.example'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $certificatePem);
        openssl_pkey_export($key, $keyPem);

        $this->setApplePayIdentity($certificatePem, $keyPem);
    }

    /**
     * Set (or clear, with null) the Apple Pay identity certificate and key on the current store.
     *
     * @param string|null $certificate
     * @param string|null $key
     * @return void
     */
    private function setApplePayIdentity(?string $certificate, ?string $key): void
    {
        $config = $this->objectManager->get(MutableScopeConfigInterface::class);

        $config->setValue(
            'payment/authnetcim_wallet/applepay_identity_cert',
            $certificate,
            ScopeInterface::SCOPE_STORE,
            'default'
        );
        $config->setValue(
            'payment/authnetcim_wallet/applepay_identity_key',
            $key,
            ScopeInterface::SCOPE_STORE,
            'default'
        );
    }

    /**
     * Get the shared TokenBase logger (Monolog) that the gateway and commands write to.
     *
     * @return Logger
     */
    private function getTokenbaseLogger(): Logger
    {
        return $this->objectManager->get('ParadoxLabs\TokenBase\Model\Logger\LoggerVirtualType');
    }
}
