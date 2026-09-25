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

use Magento\Framework\DataObject;
use Magento\Framework\DB\Transaction as DbTransaction;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Service\CreditmemoService;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * Follow-up operations on Apple Pay / Google Pay (authnetcim_wallet) orders: online invoice, refund (including the
 * unsettled-refund void fallback), void, cancel, and payment review accept/deny.
 *
 * Every order is placed through the real checkout with the Authorize.net HTTP boundary stubbed
 * ({@see WalletStubTrait}); each later operation runs as its own simulated request, as it would in the admin.
 *
 * NOTE: class-level @magentoConfigFixture is ignored by this harness; config fixtures are on the methods.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation enabled
 */
class WalletPaymentOperationsTest extends TestCase
{
    use WalletStubTrait;

    private const ERROR_UNSETTLED = 'The referenced transaction does not meet the criteria for issuing a credit.';

    /**
     * @var ObjectManager|null
     */
    private ?ObjectManager $objectManager = null;

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

        parent::tearDown();
    }

    /**
     * Invoicing an authorized wallet order online captures the prior authorization, without the (spent) token.
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
    public function testInvoiceOnlineCapturesPriorAuthorization(): void
    {
        $this->registerWalletStub($this->objectManager);
        $authTxnId = $this->placeAuthorizedOrder();

        $order   = $this->loadWalletOrder($this->objectManager);
        $invoice = $this->invoiceOnline($order);

        $this->assertSame(['priorAuthCaptureTransaction'], $this->getTransactionTypes());

        $request = $this->getTransactionRequests('priorAuthCaptureTransaction')[0];
        $this->assertSame($authTxnId, $this->readTag($request, 'refTransId'));
        $this->assertSame($this->formatAmount((float)$order->getBaseGrandTotal()), $this->readTag($request, 'amount'));
        $this->assertStringNotContainsString('<opaqueData>', $request, 'A prior-auth capture must not send a token.');
        $this->assertSame(Invoice::STATE_PAID, (int)$invoice->getState());

        $this->simulateNewRequest($this->objectManager);
        $order = $this->loadWalletOrder($this->objectManager);

        $this->assertEquals((float)$order->getBaseGrandTotal(), (float)$order->getBaseTotalPaid());
        $this->assertCount(1, $this->loadTransactions($this->objectManager, $order, Transaction::TYPE_CAPTURE));
        $this->assertSame(
            1,
            (int)$this->loadTransactions($this->objectManager, $order, Transaction::TYPE_AUTH)
                ->getFirstItem()
                ->getIsClosed(),
            'The captured authorization must be closed.'
        );
        $this->assertFalse($order->getPayment()->canCapture(), 'A wallet authorization can only be captured once.');
    }

    /**
     * can_capture_partial=0 / can_capture_once=1 only gate the admin UI (the invoice qty inputs); Magento does not
     * enforce them server-side. A programmatic (or REST) partial invoice therefore captures the partial amount and
     * closes the authorization, and the remainder can never be captured online: a second online invoice sends
     * nothing to the gateway and is not paid.
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
    public function testPartialInvoiceCapturesOnceAndRemainderCannotBeCapturedOnline(): void
    {
        $this->registerWalletStub($this->objectManager);
        $authTxnId = $this->placeAuthorizedOrder();

        $order  = $this->loadWalletOrder($this->objectManager);
        $method = $order->getPayment()->getMethodInstance();
        $this->assertFalse($method->canCapturePartial(), 'The admin invoice form must not allow partial qtys.');
        $this->assertTrue($method->canCaptureOnce());

        $itemId  = (int)current($order->getAllVisibleItems())->getId();
        $invoice = $this->invoiceOnline(
            $order,
            [
                $itemId => 1,
            ]
        );

        $this->assertLessThan((float)$order->getBaseGrandTotal(), (float)$invoice->getBaseGrandTotal());
        $this->assertSame(['priorAuthCaptureTransaction'], $this->getTransactionTypes());

        $request = $this->getTransactionRequests('priorAuthCaptureTransaction')[0];
        $this->assertSame($authTxnId, $this->readTag($request, 'refTransId'));
        $this->assertSame(
            $this->formatAmount((float)$invoice->getBaseGrandTotal()),
            $this->readTag($request, 'amount')
        );

        $this->simulateNewRequest($this->objectManager);
        $order = $this->loadWalletOrder($this->objectManager);

        $this->assertFalse(
            $order->getPayment()->canCapture(),
            'After one capture, the wallet authorization is closed and cannot be captured again.'
        );

        // Second invoice for the rest, requested online.
        $this->clientStub->requests = [];
        $secondInvoice              = $this->invoiceOnline($order);

        $this->assertSame([], $this->clientStub->requests, 'The remainder must not be sent to the gateway.');
        $this->assertNotSame(Invoice::STATE_PAID, (int)$secondInvoice->getState());

        $this->simulateNewRequest($this->objectManager);
        $this->assertEquals(
            (float)$invoice->getBaseGrandTotal(),
            (float)$this->loadWalletOrder($this->objectManager)->getBaseTotalPaid(),
            'Only the first (captured) invoice may count as paid.'
        );
    }

    /**
     * A settled-path refund sends refundTransaction with the card last4 and closes the order.
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
    public function testRefundOnlineSendsRefundTransaction(): void
    {
        $this->registerWalletStub($this->objectManager);
        $authTxnId = $this->placeAuthorizedOrder();
        $this->invoiceOnline($this->loadWalletOrder($this->objectManager));

        $this->simulateNewRequest($this->objectManager);
        $this->clientStub->requests = [];

        $creditmemo = $this->refundOnline($this->loadWalletOrder($this->objectManager));

        $this->assertSame(['refundTransaction'], $this->getTransactionTypes());

        $request = $this->getTransactionRequests('refundTransaction')[0];
        $this->assertSame($authTxnId, $this->readTag($request, 'refTransId'));
        $this->assertSame('1111', $this->readTag($request, 'cardNumber'));
        $this->assertSame('XXXX', $this->readTag($request, 'expirationDate'));
        $this->assertStringNotContainsString('<opaqueData>', $request);
        $this->assertSame(Creditmemo::STATE_REFUNDED, (int)$creditmemo->getState());

        $this->simulateNewRequest($this->objectManager);
        $order = $this->loadWalletOrder($this->objectManager);

        $this->assertSame(Order::STATE_CLOSED, $order->getState());
        $this->assertEquals((float)$order->getBaseGrandTotal(), (float)$order->getBaseTotalRefunded());
        $this->assertCount(1, $this->loadTransactions($this->objectManager, $order, Transaction::TYPE_REFUND));
    }

    /**
     * A full refund of an unsettled capture gets error 54 and falls back to voiding the transaction.
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
    public function testFullRefundOfUnsettledCaptureFallsBackToVoid(): void
    {
        $this->registerWalletStub($this->objectManager, $this->unsettledRefundResponder());
        $authTxnId = $this->placeAuthorizedOrder();
        $this->invoiceOnline($this->loadWalletOrder($this->objectManager));

        $this->simulateNewRequest($this->objectManager);
        $this->clientStub->requests = [];

        $creditmemo = $this->refundOnline($this->loadWalletOrder($this->objectManager));

        $this->assertSame(['refundTransaction', 'voidTransaction'], $this->getTransactionTypes());

        $void = $this->getTransactionRequests('voidTransaction')[0];
        $this->assertSame($authTxnId, $this->readTag($void, 'refTransId'));
        $this->assertNull($this->readTag($void, 'cardNumber'), 'The void must not carry refund parameters.');
        $this->assertSame(Creditmemo::STATE_REFUNDED, (int)$creditmemo->getState());

        $this->simulateNewRequest($this->objectManager);
        $order = $this->loadWalletOrder($this->objectManager);

        $this->assertSame(Order::STATE_CLOSED, $order->getState());
        $this->assertEquals((float)$order->getBaseGrandTotal(), (float)$order->getBaseTotalRefunded());
    }

    /**
     * A partial refund of an unsettled capture cannot be voided, so it is rejected with an explanation.
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
    public function testPartialRefundOfUnsettledCaptureIsRejected(): void
    {
        $this->registerWalletStub($this->objectManager, $this->unsettledRefundResponder());
        $this->placeAuthorizedOrder();
        $this->invoiceOnline($this->loadWalletOrder($this->objectManager));

        $this->simulateNewRequest($this->objectManager);
        $this->clientStub->requests = [];

        $order  = $this->loadWalletOrder($this->objectManager);
        $itemId = (int)current($order->getAllVisibleItems())->getId();

        try {
            $this->refundOnline(
                $order,
                [
                    'qtys' => [
                        $itemId => 1,
                    ],
                ]
            );
            $this->fail('A partial refund of an unsettled wallet capture must be rejected.');
        } catch (LocalizedException $exception) {
            $this->assertStringContainsString('can only be refunded in full', $exception->getMessage());
        }

        $this->assertSame(['refundTransaction'], $this->getTransactionTypes(), 'A partial refund must never void.');
    }

    /**
     * Voiding an authorized wallet order sends voidTransaction and closes the authorization.
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
    public function testVoidSendsVoidTransactionAndClosesAuthorization(): void
    {
        $this->registerWalletStub($this->objectManager);
        $authTxnId = $this->placeAuthorizedOrder();

        $order = $this->loadWalletOrder($this->objectManager);
        $order->getPayment()->void(new DataObject());
        $this->objectManager->get(OrderRepositoryInterface::class)->save($order);

        $this->assertSame(['voidTransaction'], $this->getTransactionTypes());
        $void = $this->getTransactionRequests('voidTransaction')[0];
        $this->assertSame($authTxnId, $this->readTag($void, 'refTransId'));

        $this->simulateNewRequest($this->objectManager);
        $order = $this->loadWalletOrder($this->objectManager);

        $this->assertSame(
            1,
            (int)$this->loadTransactions($this->objectManager, $order, Transaction::TYPE_AUTH)
                ->getFirstItem()
                ->getIsClosed(),
            'The voided authorization must be closed.'
        );
        $this->assertCount(1, $this->loadTransactions($this->objectManager, $order, Transaction::TYPE_VOID));
    }

    /**
     * Cancelling an authorized wallet order voids it at the gateway.
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
    public function testCancelVoidsAuthorization(): void
    {
        $this->registerWalletStub($this->objectManager);
        $authTxnId = $this->placeAuthorizedOrder();

        $order = $this->loadWalletOrder($this->objectManager);
        $order->cancel();
        $this->objectManager->get(OrderRepositoryInterface::class)->save($order);

        $this->assertSame(['voidTransaction'], $this->getTransactionTypes());
        $void = $this->getTransactionRequests('voidTransaction')[0];
        $this->assertSame($authTxnId, $this->readTag($void, 'refTransId'));

        $this->simulateNewRequest($this->objectManager);
        $this->assertSame(Order::STATE_CANCELED, $this->loadWalletOrder($this->objectManager)->getState());
    }

    /**
     * Order cancellation is never blocked by the gateway: a rejected void still cancels, says so on the order, and
     * leaves the authorization open.
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
    public function testCancelCompletesWhenGatewayRejectsVoid(): void
    {
        $this->registerWalletStub(
            $this->objectManager,
            fn (string $type, string $xml): ?string => $this->readTag($xml, 'transactionType') === 'voidTransaction'
                ? $this->buildTransactionErrorXml(3, 310, 'This transaction has already been voided.')
                : null
        );
        $this->placeAuthorizedOrder();

        $order = $this->loadWalletOrder($this->objectManager);
        $order->cancel();
        $this->objectManager->get(OrderRepositoryInterface::class)->save($order);

        $this->assertSame(['voidTransaction'], $this->getTransactionTypes());

        $this->simulateNewRequest($this->objectManager);
        $order = $this->loadWalletOrder($this->objectManager);

        $this->assertSame(Order::STATE_CANCELED, $order->getState());
        $this->assertStringContainsString('Unable to void payment', $this->getHistoryText($order));
        $this->assertSame(
            0,
            (int)$this->loadTransactions($this->objectManager, $order, Transaction::TYPE_AUTH)
                ->getFirstItem()
                ->getIsClosed(),
            'Nothing was reversed at the gateway, so the authorization must stay open.'
        );
    }

    /**
     * Accepting a held wallet payment approves it at Authorize.net and releases the order.
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
    public function testAcceptPaymentApprovesHeldPayment(): void
    {
        $this->registerWalletStub($this->objectManager, $this->heldAuthorizationResponder());
        $authTxnId = $this->placeHeldOrder();

        $order = $this->loadWalletOrder($this->objectManager);
        $order->getPayment()->accept();
        $this->objectManager->get(OrderRepositoryInterface::class)->save($order);

        $held = $this->clientStub->getRequestsOfType('updateHeldTransactionRequest');
        $this->assertCount(1, $held);
        $this->assertSame('approve', $this->readTag($held[0], 'action'));
        $this->assertSame($authTxnId, $this->readTag($held[0], 'refTransId'));

        $this->simulateNewRequest($this->objectManager);
        $this->assertSame(Order::STATE_PROCESSING, $this->loadWalletOrder($this->objectManager)->getState());
    }

    /**
     * The Adapter's acceptPayment() is always truthy, so a gateway that does not approve must surface as an
     * exception, leaving the order in review.
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
    public function testAcceptPaymentThrowsWhenNotApproved(): void
    {
        $this->registerWalletStub(
            $this->objectManager,
            $this->heldAuthorizationResponder(
                fn (string $xml): string => $this->buildHeldTransactionReplyXml(
                    4,
                    (string)$this->readTag($xml, 'refTransId')
                )
            )
        );
        $this->placeHeldOrder();

        $order = $this->loadWalletOrder($this->objectManager);

        try {
            $order->getPayment()->accept();
            $this->fail('Accepting a payment the gateway did not approve must throw.');
        } catch (LocalizedException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertCount(1, $this->clientStub->getRequestsOfType('updateHeldTransactionRequest'));

        $this->simulateNewRequest($this->objectManager);
        $this->assertSame(Order::STATE_PAYMENT_REVIEW, $this->loadWalletOrder($this->objectManager)->getState());
    }

    /**
     * Denying a held wallet payment declines it at Authorize.net and cancels the order.
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
    public function testDenyPaymentDeclinesHeldPayment(): void
    {
        $this->registerWalletStub($this->objectManager, $this->heldAuthorizationResponder());
        $authTxnId = $this->placeHeldOrder();

        $order = $this->loadWalletOrder($this->objectManager);
        $order->getPayment()->deny();
        $this->objectManager->get(OrderRepositoryInterface::class)->save($order);

        $held = $this->clientStub->getRequestsOfType('updateHeldTransactionRequest');
        $this->assertCount(1, $held);
        $this->assertSame('decline', $this->readTag($held[0], 'action'));
        $this->assertSame($authTxnId, $this->readTag($held[0], 'refTransId'));

        $this->simulateNewRequest($this->objectManager);
        $this->assertSame(Order::STATE_CANCELED, $this->loadWalletOrder($this->objectManager)->getState());
    }

    /**
     * The Adapter's denyPayment() is always truthy, so a gateway that does not decline must surface as an
     * exception, leaving the order in review.
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
    public function testDenyPaymentThrowsWhenNotDeclined(): void
    {
        $this->registerWalletStub(
            $this->objectManager,
            $this->heldAuthorizationResponder(
                fn (string $xml): string => $this->buildHeldTransactionReplyXml(
                    1,
                    (string)$this->readTag($xml, 'refTransId')
                )
            )
        );
        $this->placeHeldOrder();

        $order = $this->loadWalletOrder($this->objectManager);

        try {
            $order->getPayment()->deny();
            $this->fail('Denying a payment the gateway did not decline must throw.');
        } catch (LocalizedException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertCount(1, $this->clientStub->getRequestsOfType('updateHeldTransactionRequest'));

        $this->simulateNewRequest($this->objectManager);
        $this->assertSame(Order::STATE_PAYMENT_REVIEW, $this->loadWalletOrder($this->objectManager)->getState());
    }

    /**
     * Place the fixture cart with Google Pay (authorize only) and return the authorization's gateway ID.
     *
     * @return string
     */
    private function placeAuthorizedOrder(): string
    {
        $order = $this->placeWalletOrder($this->objectManager, $this->googlePayData());

        $this->assertSame(Order::STATE_PROCESSING, $order->getState(), 'Precondition: an authorized order.');

        $authTxnId = (string)$order->getPayment()->getLastTransId();

        $this->clientStub->requests = [];

        return $authTxnId;
    }

    /**
     * Place the fixture cart with an authorization Authorize.net holds for review; return its gateway ID.
     *
     * @return string
     */
    private function placeHeldOrder(): string
    {
        $order = $this->placeWalletOrder($this->objectManager, $this->googlePayData());

        $this->assertSame(Order::STATE_PAYMENT_REVIEW, $order->getState(), 'Precondition: a held order.');

        $authTxnId = (string)$order->getPayment()->getLastTransId();

        $this->clientStub->requests = [];
        $this->simulateNewRequest($this->objectManager);

        return $authTxnId;
    }

    /**
     * Invoice the order online (for the given item qtys, or everything left) and persist it.
     *
     * @param Order $order
     * @param array $qtys
     * @return Invoice
     */
    private function invoiceOnline(
        Order $order,
        array $qtys = [],
    ): Invoice {
        $invoice = $this->objectManager->get(InvoiceService::class)->prepareInvoice($order, $qtys);
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
        $invoice->register();

        $order->setIsInProcess(true);
        $this->objectManager->create(DbTransaction::class)
            ->addObject($invoice)
            ->addObject($order)
            ->save();

        return $invoice;
    }

    /**
     * Refund the order's first invoice online (in full, or per $data) and return the credit memo.
     *
     * @param Order $order
     * @param array $data
     * @return Creditmemo
     */
    private function refundOnline(
        Order $order,
        array $data = [],
    ): Creditmemo {
        /** @var Invoice $invoice */
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $this->assertGreaterThan(0, (int)$invoice->getId(), 'Precondition: the order is invoiced.');

        $creditmemo = $this->objectManager->get(CreditmemoFactory::class)->createByInvoice($invoice, $data);
        $this->objectManager->get(CreditmemoService::class)->refund($creditmemo, false);

        return $creditmemo;
    }

    /**
     * Reply to refundTransaction with Authorize.net error 54 (transaction not settled).
     *
     * @return callable
     */
    private function unsettledRefundResponder(): callable
    {
        return function (string $type, string $xml): ?string {
            if ($this->readTag($xml, 'transactionType') !== 'refundTransaction') {
                return null;
            }

            return $this->buildTransactionErrorXml(
                3,
                54,
                self::ERROR_UNSETTLED,
                (string)$this->readTag($xml, 'refTransId')
            );
        };
    }

    /**
     * Hold authOnlyTransaction for review (responseCode 4); optionally shape updateHeldTransaction replies.
     *
     * @param callable|null $heldReply fn(string $requestXml): string
     * @return callable
     */
    private function heldAuthorizationResponder(?callable $heldReply = null): callable
    {
        return function (string $type, string $xml) use ($heldReply): ?string {
            if ($type === 'updateHeldTransactionRequest' && $heldReply !== null) {
                return $heldReply($xml);
            }

            if ($this->readTag($xml, 'transactionType') === 'authOnlyTransaction') {
                return $this->buildTransactionReplyXml($this->nextTransactionId(), 4);
            }

            return null;
        };
    }

    /**
     * Format an amount as the gateway sends it.
     *
     * @param float $amount
     * @return string
     */
    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Concatenate the order's status history comments.
     *
     * @param Order $order
     * @return string
     */
    private function getHistoryText(Order $order): string
    {
        $comments = [];
        foreach ($order->getStatusHistoryCollection() as $history) {
            $comments[] = (string)$history->getComment();
        }

        return implode("\n", $comments);
    }
}
