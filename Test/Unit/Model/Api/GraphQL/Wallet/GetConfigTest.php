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

namespace ParadoxLabs\Authnetcim\Test\Unit\Model\Api\GraphQL\Wallet;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\QuoteGraphQl\Model\Cart\GetCartForUser;
use Magento\Quote\Model\Quote;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ParadoxLabs\Authnetcim\Model\Api\GraphQL\Wallet\GetConfig;
use ParadoxLabs\Authnetcim\Model\Wallet\CheckoutConfig;

class GetConfigTest extends TestCase
{
    private const STORE_ID = 3;

    private GetCartForUser|MockObject $getCartForUser;
    private CheckoutConfig|MockObject $checkoutConfig;
    private ContextInterface|MockObject $context;
    private GetConfig $resolver;

    protected function setUp(): void
    {
        $this->getCartForUser = $this->createMock(GetCartForUser::class);
        $this->checkoutConfig = $this->createMock(CheckoutConfig::class);
        $this->context = $this->createMock(ContextInterface::class);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(self::STORE_ID);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $this->resolver = new GetConfig(
            $this->getCartForUser,
            $this->checkoutConfig,
            $storeManager,
        );
    }

    /**
     * @return void
     */
    public function testMissingCartIdThrows(): void
    {
        $this->getCartForUser->expects($this->never())->method('execute');

        $this->expectException(GraphQlInputException::class);

        $this->resolve([
            'cart_id' => ' ',
        ]);
    }

    /**
     * Cart ownership goes through GetCartForUser: guest (user 0) with the masked ID and the request store.
     *
     * @return void
     */
    public function testLoadsCartForGuestWithMaskedIdAndStore(): void
    {
        $this->context->method('getUserId')->willReturn(null);

        $this->getCartForUser->expects($this->once())
            ->method('execute')
            ->with('masked123', 0, self::STORE_ID)
            ->willReturn($this->createCart());

        $this->checkoutConfig->method('getConfig')->willReturn([]);

        $this->resolve([
            'cart_id' => 'masked123',
        ]);
    }

    /**
     * @return void
     */
    public function testCartOwnershipFailurePropagates(): void
    {
        $this->context->method('getUserId')->willReturn(7);

        $this->getCartForUser->method('execute')
            ->with('masked123', 7, self::STORE_ID)
            ->willThrowException(new GraphQlAuthorizationException(__('Not your cart.')));

        $this->checkoutConfig->expects($this->never())->method('getConfig');

        $this->expectException(GraphQlAuthorizationException::class);

        $this->resolve([
            'cart_id' => 'masked123',
        ]);
    }

    /**
     * Zero footprint when disabled: no merchant settings are returned.
     *
     * @return void
     */
    public function testDisabledReturnsMinimalPayload(): void
    {
        $this->getCartForUser->method('execute')->willReturn($this->createCart());

        $this->checkoutConfig->expects($this->once())
            ->method('getConfig')
            ->with(self::STORE_ID)
            ->willReturn([]);

        $this->assertSame(
            [
                'enabled' => false,
                'wallets' => [],
            ],
            $this->resolve([
                'cart_id' => 'masked123',
            ])
        );
    }

    /**
     * @return void
     */
    public function testEnabledReturnsClientConfigWithCartBaseTotal(): void
    {
        $this->getCartForUser->method('execute')->willReturn($this->createCart(123.456, 'CAD'));

        $this->checkoutConfig->method('getConfig')->willReturn([
            'wallets' => [
                'applepay',
                'googlepay',
            ],
            'countryCode' => 'US',
            'currencyCode' => 'USD',
            'sandbox' => true,
            'applePay' => [
                'merchantIdentifier' => 'merchant.com.example',
                'validateUrl' => 'https://example.com/authnetcim/wallet/applePayValidate/',
            ],
            'googlePay' => [
                'environment' => 'TEST',
            ],
        ]);

        $result = $this->resolve([
            'cart_id' => 'masked123',
        ]);

        $this->assertTrue($result['enabled']);
        $this->assertSame(
            [
                'applepay',
                'googlepay',
            ],
            $result['wallets']
        );
        $this->assertSame(123.456, $result['amount']);
        $this->assertSame('CAD', $result['currencyCode']);
        $this->assertSame('US', $result['countryCode']);
        $this->assertSame('merchant.com.example', $result['applePay']['merchantIdentifier']);
        $this->assertArrayNotHasKey('validateUrl', $result['applePay']);
        $this->assertSame('TEST', $result['googlePay']['environment']);
    }

    /**
     * @return void
     */
    public function testGooglePayOnlyKeepsStoreCurrencyWhenCartHasNone(): void
    {
        $this->getCartForUser->method('execute')->willReturn($this->createCart(10.0, null));

        $this->checkoutConfig->method('getConfig')->willReturn([
            'wallets' => [
                'googlepay',
            ],
            'currencyCode' => 'USD',
            'googlePay' => [
                'environment' => 'PRODUCTION',
            ],
        ]);

        $result = $this->resolve([
            'cart_id' => 'masked123',
        ]);

        $this->assertSame('USD', $result['currencyCode']);
        $this->assertSame(10.0, $result['amount']);
        $this->assertArrayNotHasKey('applePay', $result);
    }

    /**
     * @param array $args
     * @return array
     */
    private function resolve(array $args): array
    {
        return $this->resolver->resolve(
            $this->createMock(Field::class),
            $this->context,
            $this->createMock(ResolveInfo::class),
            null,
            $args
        );
    }

    /**
     * getBaseGrandTotal()/getBaseCurrencyCode() are magic on Quote; the getData() stub feeds them through __call.
     *
     * @param float $baseGrandTotal
     * @param string|null $baseCurrency
     * @return Quote|MockObject
     */
    private function createCart(float $baseGrandTotal = 0.0, ?string $baseCurrency = null): Quote|MockObject
    {
        $data = [
            'base_grand_total' => $baseGrandTotal,
            'base_currency_code' => $baseCurrency,
        ];

        $cart = $this->createPartialMock(
            Quote::class,
            [
                'getStoreId',
                'getData',
            ]
        );
        $cart->method('getStoreId')->willReturn(self::STORE_ID);
        $cart->method('getData')->willReturnCallback(
            static fn ($key = '', $index = null) => $data[$key] ?? null
        );

        return $cart;
    }
}
