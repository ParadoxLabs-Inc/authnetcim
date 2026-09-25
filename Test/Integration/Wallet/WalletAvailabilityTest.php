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
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\SpecificationInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Payment\Model\MethodList;
use Magento\Quote\Model\Quote;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use ParadoxLabs\Authnetcim\Model\Wallet\WalletType;
use ParadoxLabs\Subscriptions\Model\Service\QuoteManager;
use ParadoxLabs\Subscriptions\Observer\PaymentAvailableObserver;
use PHPUnit\Framework\TestCase;

/**
 * Where the Apple Pay / Google Pay method (authnetcim_wallet) may and may not be offered.
 *
 * Checks run through Magento's own gates: the storefront method list, the quote payment import with the checks each
 * entry point passes (storefront checkout vs. admin order create), the multishipping payment specification, and the
 * payment_method_is_active event that ParadoxLabs_Subscriptions listens to.
 *
 * NOTE: class-level @magentoConfigFixture is ignored by this harness; config fixtures are on the methods.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation enabled
 */
class WalletAvailabilityTest extends TestCase
{
    use WalletStubTrait;

    /**
     * Checks QuoteManagement::placeOrder and PaymentMethodManagement::set apply on the storefront.
     */
    private const CHECKOUT_CHECKS = [
        AbstractMethod::CHECK_USE_CHECKOUT,
        AbstractMethod::CHECK_USE_FOR_COUNTRY,
        AbstractMethod::CHECK_USE_FOR_CURRENCY,
        AbstractMethod::CHECK_ORDER_TOTAL_MIN_MAX,
        AbstractMethod::CHECK_ZERO_TOTAL,
    ];

    /**
     * Checks the admin order create Save controller applies.
     */
    private const ADMIN_CHECKS = [
        AbstractMethod::CHECK_USE_INTERNAL,
        AbstractMethod::CHECK_USE_FOR_COUNTRY,
        AbstractMethod::CHECK_USE_FOR_CURRENCY,
        AbstractMethod::CHECK_ORDER_TOTAL_MIN_MAX,
        AbstractMethod::CHECK_ZERO_TOTAL,
    ];

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
        if (class_exists(QuoteManager::class)) {
            $this->objectManager->removeSharedInstance(QuoteManager::class);
            $this->objectManager->removeSharedInstance(PaymentAvailableObserver::class);
        }

        parent::tearDown();
    }

    /**
     * With Google Pay configured (sandbox, so no Google merchant ID needed), the storefront lists the method and a
     * checkout can select it.
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
    public function testAvailableOnStorefrontWhenGooglePayConfigured(): void
    {
        $quote = $this->loadWalletQuote($this->objectManager);

        $this->assertContains(WalletType::METHOD_CODE, $this->getStorefrontMethodCodes($quote));

        $quote->getPayment()->importData(
            [
                'method' => WalletType::METHOD_CODE,
                'checks' => self::CHECKOUT_CHECKS,
            ]
        );

        $this->assertSame(WalletType::METHOD_CODE, $quote->getPayment()->getMethod());
    }

    /**
     * Disabled (the default): not listed, and cannot be selected.
     *
     * @magentoDataFixture ParadoxLabs_Authnetcim::Test/Integration/_files/wallet_quote.php
     * @magentoConfigFixture current_store payment/authnetcim/test 1
     * @magentoConfigFixture current_store payment/authnetcim/login integration_login
     * @magentoConfigFixture current_store payment/authnetcim/trans_key integrationkey00
     * @magentoConfigFixture current_store payment/authnetcim_wallet/active 0
     * @magentoConfigFixture current_store payment/authnetcim_wallet/googlepay_active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/googlepay_gateway_merchant_id 123456
     * @return void
     */
    public function testUnavailableWhenDisabled(): void
    {
        $quote = $this->loadWalletQuote($this->objectManager);

        $this->assertNotContains(WalletType::METHOD_CODE, $this->getStorefrontMethodCodes($quote));
        $this->assertImportRejected($quote, self::CHECKOUT_CHECKS);
    }

    /**
     * Enabled, but neither wallet has what it needs: the availability validator rejects it at selection.
     *
     * @magentoDataFixture ParadoxLabs_Authnetcim::Test/Integration/_files/wallet_quote.php
     * @magentoConfigFixture current_store payment/authnetcim/test 1
     * @magentoConfigFixture current_store payment/authnetcim/login integration_login
     * @magentoConfigFixture current_store payment/authnetcim/trans_key integrationkey00
     * @magentoConfigFixture current_store payment/authnetcim_wallet/active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/googlepay_active 1
     * @return void
     */
    public function testUnavailableWhenNoWalletIsConfigured(): void
    {
        $quote = $this->loadWalletQuote($this->objectManager);

        $this->assertImportRejected($quote, self::CHECKOUT_CHECKS);
    }

    /**
     * Enabled wallet, but no API credentials on Authorize.net CIM (login/trans_key are empty by default): not
     * selectable.
     *
     * @magentoDataFixture ParadoxLabs_Authnetcim::Test/Integration/_files/wallet_quote.php
     * @magentoConfigFixture current_store payment/authnetcim/test 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/googlepay_active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/googlepay_gateway_merchant_id 123456
     * @return void
     */
    public function testUnavailableWithoutApiCredentials(): void
    {
        $quote = $this->loadWalletQuote($this->objectManager);

        $this->assertImportRejected($quote, self::CHECKOUT_CHECKS);
    }

    /**
     * can_use_internal=0: admin order create (which imports payment with the 'internal' check) must reject it, even
     * though the same cart accepts it on the storefront.
     *
     * @magentoAppArea adminhtml
     * @magentoDataFixture ParadoxLabs_Authnetcim::Test/Integration/_files/wallet_quote.php
     * @magentoConfigFixture current_store payment/authnetcim/test 1
     * @magentoConfigFixture current_store payment/authnetcim/login integration_login
     * @magentoConfigFixture current_store payment/authnetcim/trans_key integrationkey00
     * @magentoConfigFixture current_store payment/authnetcim_wallet/active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/googlepay_active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/googlepay_gateway_merchant_id 123456
     * @return void
     */
    public function testUnavailableForAdminOrderCreate(): void
    {
        $method = $this->getMethodInstance(WalletType::METHOD_CODE);
        $this->assertFalse($method->canUseInternal(), 'The admin order create method form hides internal-only=0.');
        $this->assertTrue($method->canUseCheckout());

        $this->assertImportRejected($this->loadWalletQuote($this->objectManager), self::ADMIN_CHECKS);

        // Control: the same cart and config accept the wallet with the storefront checks.
        $quote = $this->loadWalletQuote($this->objectManager);
        $quote->getPayment()->importData(
            [
                'method' => WalletType::METHOD_CODE,
                'checks' => self::CHECKOUT_CHECKS,
            ]
        );
        $this->assertSame(WalletType::METHOD_CODE, $quote->getPayment()->getMethod());
    }

    /**
     * Multishipping only offers methods declaring allow_multiple_address in payment.xml; the wallet does not.
     *
     * @return void
     */
    public function testUnavailableForMultishipping(): void
    {
        if (!$this->objectManager->get(ModuleManager::class)->isEnabled('Magento_Multishipping')) {
            $this->markTestSkipped('Magento_Multishipping is not enabled.');
        }

        /** @var SpecificationInterface $specification */
        $specification = $this->objectManager->create('multishippingPaymentSpecification');

        $this->assertFalse($specification->isSatisfiedBy(WalletType::METHOD_CODE));
        $this->assertTrue(
            $specification->isSatisfiedBy('authnetcim'),
            'Control: the CIM card method is allowed for multishipping.'
        );
    }

    /**
     * ParadoxLabs_Subscriptions allows only TokenBase, offline, and Vault methods on carts with a subscription item.
     * The wallet is none of those (no tokenbase group, is_offline=0, no vault), so it must be hidden on those carts
     * and stay available on others.
     *
     * The subscription-item detection (QuoteManager) is doubled; the method classification and the event wiring
     * are real.
     *
     * @magentoDataFixture ParadoxLabs_Authnetcim::Test/Integration/_files/wallet_quote.php
     * @magentoConfigFixture current_store subscriptions/general/active 1
     * @magentoConfigFixture current_store payment/authnetcim/test 1
     * @magentoConfigFixture current_store payment/authnetcim/login integration_login
     * @magentoConfigFixture current_store payment/authnetcim/trans_key integrationkey00
     * @magentoConfigFixture current_store payment/authnetcim_wallet/active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/googlepay_active 1
     * @magentoConfigFixture current_store payment/authnetcim_wallet/googlepay_gateway_merchant_id 123456
     * @magentoConfigFixture current_store payment/checkmo/active 1
     * @return void
     */
    public function testExcludedFromSubscriptionCarts(): void
    {
        if (!$this->objectManager->get(ModuleManager::class)->isEnabled('ParadoxLabs_Subscriptions')) {
            $this->markTestSkipped('ParadoxLabs_Subscriptions is not enabled.');
        }

        $containsSubscription = true;
        $quoteManager         = $this->createMock(QuoteManager::class);
        $quoteManager->method('quoteContainsSubscription')
            ->willReturnCallback(
                static function () use (&$containsSubscription): bool {
                    return $containsSubscription;
                }
            );

        $this->objectManager->addSharedInstance($quoteManager, QuoteManager::class);
        $this->objectManager->removeSharedInstance(PaymentAvailableObserver::class);

        $quote = $this->loadWalletQuote($this->objectManager);

        $this->assertFalse(
            (bool)$this->getMethodInstance(WalletType::METHOD_CODE)->isAvailable($quote),
            'The wallet must be hidden on a cart with a subscription.'
        );
        $this->assertTrue(
            (bool)$this->getMethodInstance('checkmo')->isAvailable($quote),
            'Control: an offline method stays available on the subscription cart.'
        );

        $containsSubscription = false;

        $this->assertTrue(
            (bool)$this->getMethodInstance(WalletType::METHOD_CODE)->isAvailable($quote),
            'The wallet must stay available on a cart without a subscription.'
        );
    }

    /**
     * Get the codes of the payment methods the storefront would offer for the cart.
     *
     * @param Quote $quote
     * @return string[]
     */
    private function getStorefrontMethodCodes(Quote $quote): array
    {
        return array_map(
            static fn (MethodInterface $method): string => $method->getCode(),
            $this->objectManager->get(MethodList::class)->getAvailableMethods($quote)
        );
    }

    /**
     * Assert the cart rejects the wallet method on payment import with the given checks.
     *
     * @param Quote $quote
     * @param array $checks
     * @return void
     */
    private function assertImportRejected(
        Quote $quote,
        array $checks,
    ): void {
        try {
            $quote->getPayment()->importData(
                [
                    'method' => WalletType::METHOD_CODE,
                    'checks' => $checks,
                ]
            );
        } catch (LocalizedException $exception) {
            $this->assertSame('The requested Payment Method is not available.', $exception->getMessage());

            return;
        }

        $this->fail('The wallet payment method must not be selectable here.');
    }

    /**
     * Get a fresh payment method instance.
     *
     * @param string $code
     * @return MethodInterface
     */
    private function getMethodInstance(string $code): MethodInterface
    {
        return $this->objectManager->get(PaymentHelper::class)->getMethodInstance($code);
    }
}
