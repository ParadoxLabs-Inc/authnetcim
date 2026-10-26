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

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Checkout that fails after the wallet payment went through (Authorize.net HTTP boundary stubbed by
 * {@see WalletStubTrait}): the transaction must be voided unless the order was saved.
 *
 * The failure is forced at the order save inside the real quote submit: the order service gets an order repository
 * whose save() throws, after Payment::place() has run against the stubbed gateway.
 *
 * NOTE: class-level @magentoConfigFixture is ignored by this harness; config fixtures are on the methods.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation enabled
 */
class WalletCheckoutFailureTest extends TestCase
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

        if ($this->logHandler !== null) {
            $this->getTokenbaseLogger()->popHandler();
            $this->logHandler = null;
        }

        parent::tearDown();
    }

    /**
     * Authorize only: the order save fails after the authorization, so the authorization is voided and no order
     * exists. The original checkout exception still reaches the customer.
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
    public function testOrderSaveFailureVoidsAuthorization(): void
    {
        $this->registerWalletStub($this->objectManager);

        $this->logHandler = new TestHandler();
        $this->getTokenbaseLogger()->pushHandler($this->logHandler);

        $exception = $this->placeWithOrderSave(
            static function (): OrderInterface {
                throw new LocalizedException(__('Simulated order save failure.'));
            }
        );

        $this->assertSame(
            'Simulated order save failure.',
            $exception->getMessage(),
            'The checkout exception must reach the customer unchanged.'
        );
        $this->assertSame(
            [
                'authOnlyTransaction',
                'voidTransaction',
            ],
            $this->getTransactionTypes(),
            'The authorization must be voided after the order failed to save.'
        );
        $this->assertSame(
            '8800000101',
            $this->readTag($this->getTransactionRequests('voidTransaction')[0], 'refTransId'),
            'The void must reference the authorization.'
        );
        $this->assertNoOrderPlaced();

        $this->assertTrue(
            $this->logHandler->hasInfoThatContains(
                'Auto-voided transaction 8800000101 for order test_wallet_quote because checkout failed.'
            ),
            'The auto-void must be written to the wallet log.'
        );
    }

    /**
     * Authorize and capture: the unsettled capture is voided.
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
    public function testOrderSaveFailureVoidsAuthCapture(): void
    {
        $this->registerWalletStub($this->objectManager);

        $exception = $this->placeWithOrderSave(
            static function (): OrderInterface {
                throw new LocalizedException(__('Simulated order save failure.'));
            }
        );

        $this->assertSame('Simulated order save failure.', $exception->getMessage());
        $this->assertSame(
            [
                'authCaptureTransaction',
                'voidTransaction',
            ],
            $this->getTransactionTypes(),
            'The unsettled capture must be voided after the order failed to save.'
        );
        $this->assertSame(
            '8800000101',
            $this->readTag($this->getTransactionRequests('voidTransaction')[0], 'refTransId'),
            'The void must reference the capture.'
        );
        $this->assertNoOrderPlaced();
    }

    /**
     * A failed save rolls back the order insert but leaves the new entity_id on the order. That order does not
     * exist, so the authorization is still voided.
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
    public function testRolledBackOrderSaveVoidsAuthorization(): void
    {
        $this->registerWalletStub($this->objectManager);

        $exception = $this->placeWithOrderSave(
            static function (OrderInterface $order): OrderInterface {
                // What AbstractDb::save() leaves behind when it rolls back after inserting the order row.
                $order->setEntityId(2147483000);

                throw new LocalizedException(__('Simulated order save failure.'));
            }
        );

        $this->assertSame('Simulated order save failure.', $exception->getMessage());
        $this->assertSame(
            [
                'authOnlyTransaction',
                'voidTransaction',
            ],
            $this->getTransactionTypes()
        );
        $this->assertSame(
            '8800000101',
            $this->readTag($this->getTransactionRequests('voidTransaction')[0], 'refTransId')
        );
        $this->assertNoOrderPlaced();
    }

    /**
     * Checkout fails after the order was saved (e.g. a later cart save or success observer throws): the order is
     * real, so its authorization must stay open.
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
    public function testFailureAfterOrderSaveKeepsAuthorization(): void
    {
        $this->registerWalletStub($this->objectManager);

        $exception = $this->placeWithOrderSave(
            function (OrderInterface $order): OrderInterface {
                $this->objectManager->get(OrderRepositoryInterface::class)->save($order);

                throw new LocalizedException(__('Simulated failure after the order was saved.'));
            }
        );

        $this->assertSame('Simulated failure after the order was saved.', $exception->getMessage());
        $this->assertSame(
            ['authOnlyTransaction'],
            $this->getTransactionTypes(),
            'A saved order\'s authorization must not be voided.'
        );

        $this->simulateNewRequest($this->objectManager);
        $order = $this->loadWalletOrder($this->objectManager);
        $this->assertSame(Order::STATE_PROCESSING, $order->getState());
        $this->assertSame('8800000101', (string)$order->getPayment()->getLastTransId());
    }

    /**
     * Place the fixture cart with Google Pay through the real quote submit, with the order save replaced by $save.
     * Returns the exception checkout failed with.
     *
     * @param callable $save fn(OrderInterface $order): OrderInterface, called where the order would be saved.
     * @return Throwable
     */
    private function placeWithOrderSave(callable $save): Throwable
    {
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('save')->willReturnCallback($save);

        $orderManagement = $this->objectManager->create(
            OrderManagementInterface::class,
            [
                'orderRepository' => $orderRepository,
            ]
        );
        $cartManagement = $this->objectManager->create(
            CartManagementInterface::class,
            [
                'orderManagement' => $orderManagement,
            ]
        );

        $quote = $this->loadWalletQuote($this->objectManager);

        try {
            $cartManagement->placeOrder(
                (int)$quote->getId(),
                $this->buildWalletPayment($this->objectManager, $this->googlePayData())
            );
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
     * Get the shared TokenBase logger (Monolog) that the gateway, commands and observers write to.
     *
     * @return Logger
     */
    private function getTokenbaseLogger(): Logger
    {
        return $this->objectManager->get('ParadoxLabs\TokenBase\Model\Logger\LoggerVirtualType');
    }
}
