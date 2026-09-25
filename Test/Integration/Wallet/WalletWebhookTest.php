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

use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider as AchConfigProvider;
use ParadoxLabs\Authnetcim\Model\Service\WebhookProcessor;
use ParadoxLabs\Authnetcim\Model\Wallet\WalletType;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Wallet payments are card transactions on the same Authorize.net account, so their webhooks arrive on the card
 * endpoint: its order lookup must match authnetcim_wallet orders, and the ACH endpoint's must not.
 *
 * NOTE: class-level @magentoConfigFixture is ignored by this harness; config fixtures are on the methods.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation enabled
 */
class WalletWebhookTest extends TestCase
{
    use WalletStubTrait;

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
     * The card endpoint handles authnetcim and authnetcim_wallet orders.
     *
     * @return void
     */
    public function testCardEndpointMethodCodesIncludeWallet(): void
    {
        $this->assertSame(
            [
                'authnetcim',
                WalletType::METHOD_CODE,
            ],
            $this->invoke($this->createProcessor(), 'getMethodCodes')
        );
    }

    /**
     * The ACH endpoint handles only authnetcim_ach orders.
     *
     * @return void
     */
    public function testAchEndpointMethodCodesExcludeWallet(): void
    {
        $this->assertSame(
            [
                'authnetcim_ach',
            ],
            $this->invoke($this->createProcessor(true), 'getMethodCodes')
        );
    }

    /**
     * A webhook for a wallet order's transaction finds that order on the card endpoint, and not on the ACH endpoint.
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
    public function testCardEndpointFindsWalletOrderByTransaction(): void
    {
        $this->registerWalletStub($this->objectManager);

        $order     = $this->placeWalletOrder($this->objectManager, $this->googlePayData());
        $authTxnId = (string)$order->getPayment()->getLastTransId();

        $this->assertNotSame('', $authTxnId, 'Precondition: the wallet order has a gateway transaction.');

        $cardMatch = $this->invoke($this->createProcessor(), 'getOrderByTxnId', $authTxnId);
        $this->assertSame((int)$order->getId(), (int)$cardMatch->getId());

        $achMatch = $this->invoke($this->createProcessor(true), 'getOrderByTxnId', $authTxnId);
        $this->assertEmpty($achMatch->getId(), 'The ACH endpoint must not match a wallet order.');
    }

    /**
     * Build a webhook processor for the card endpoint, or the ACH endpoint.
     *
     * @param bool $ach
     * @return WebhookProcessor
     */
    private function createProcessor(bool $ach = false): WebhookProcessor
    {
        $arguments = [];
        if ($ach === true) {
            // process() swaps in the ACH config provider for ?ach=1 requests.
            $arguments['configProvider'] = $this->objectManager->get(AchConfigProvider::class);
        }

        return $this->objectManager->create(WebhookProcessor::class, $arguments);
    }

    /**
     * Call a protected processor method.
     *
     * @param WebhookProcessor $processor
     * @param string $method
     * @param mixed ...$args
     * @return mixed
     */
    private function invoke(
        WebhookProcessor $processor,
        string $method,
        mixed ...$args,
    ): mixed {
        return (new ReflectionMethod($processor, $method))->invoke($processor, ...$args);
    }
}
