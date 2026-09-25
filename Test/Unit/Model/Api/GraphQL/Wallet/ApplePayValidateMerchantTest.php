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

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Quote\Model\Quote;
use Magento\QuoteGraphQl\Model\Cart\GetCartForUser;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use ParadoxLabs\Authnetcim\Model\Api\GraphQL\Wallet\ApplePayValidateMerchant;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\DomainResolver;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\MerchantValidator;
use ParadoxLabs\Authnetcim\Model\Wallet\Config;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ApplePayValidateMerchantTest extends TestCase
{
    private const STORE_ID = 2;
    private const CART_ID = 55;
    private const VALIDATION_URL = 'https://apple-pay-gateway.apple.com/paymentservices/startSession';

    private GetCartForUser|MockObject $getCartForUser;
    private Config|MockObject $config;
    private MerchantValidator|MockObject $merchantValidator;
    private DomainResolver|MockObject $domainResolver;
    private CacheInterface|MockObject $cache;
    private ContextInterface|MockObject $context;
    private ApplePayValidateMerchant $resolver;

    /**
     * @var array
     */
    private array $cacheData = [];

    protected function setUp(): void
    {
        $this->getCartForUser = $this->createMock(GetCartForUser::class);
        $this->config = $this->createMock(Config::class);
        $this->merchantValidator = $this->createMock(MerchantValidator::class);
        $this->domainResolver = $this->createMock(DomainResolver::class);
        $this->context = $this->createMock(ContextInterface::class);

        $this->cache = $this->createMock(CacheInterface::class);
        $this->cache->method('load')->willReturnCallback(
            fn ($key) => $this->cacheData[$key] ?? false
        );
        $this->cache->method('save')->willReturnCallback(
            function ($data, $key) {
                $this->cacheData[$key] = $data;

                return true;
            }
        );

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(self::STORE_ID);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $this->resolver = new ApplePayValidateMerchant(
            $this->getCartForUser,
            $storeManager,
            $this->config,
            $this->merchantValidator,
            $this->domainResolver,
            $this->cache,
            new Json(),
        );
    }

    /**
     * @return void
     */
    public function testReturnsMerchantSessionAsJson(): void
    {
        $this->allowAll();
        $this->context->method('getUserId')->willReturn(9);

        $this->getCartForUser->expects($this->once())
            ->method('execute')
            ->with('masked123', 9, self::STORE_ID)
            ->willReturn($this->createCart());

        $this->merchantValidator->expects($this->once())
            ->method('getMerchantSession')
            ->with(self::VALIDATION_URL, 'shop.example.com', self::STORE_ID)
            ->willReturn([
                'merchantSessionIdentifier' => 'SSH123',
                'epochTimestamp' => 1700000000000,
            ]);

        $result = $this->resolve([
            'domain' => ' Shop.Example.com ',
        ]);

        $this->assertSame(
            [
                'merchantSessionIdentifier' => 'SSH123',
                'epochTimestamp' => 1700000000000,
            ],
            json_decode($result['merchant_session'], true)
        );
    }

    /**
     * @return void
     */
    public function testMissingInputThrows(): void
    {
        $keys = [
            'cart_id',
            'validation_url',
            'domain',
        ];

        foreach ($keys as $key) {
            $input = $this->getInput();
            unset($input[$key]);

            try {
                $this->resolver->resolve(
                    $this->createMock(Field::class),
                    $this->context,
                    $this->createMock(ResolveInfo::class),
                    null,
                    [
                        'input' => $input,
                    ]
                );
                $this->fail(sprintf('Expected an exception for missing %s.', $key));
            } catch (GraphQlInputException $exception) {
                $this->assertStringContainsString($key, $exception->getMessage());
            }
        }
    }

    /**
     * @return void
     */
    public function testDisabledThrowsBeforeLoadingCart(): void
    {
        $this->config->method('isApplePayEnabled')->with(self::STORE_ID)->willReturn(false);
        $this->getCartForUser->expects($this->never())->method('execute');
        $this->merchantValidator->expects($this->never())->method('getMerchantSession');

        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('Apple Pay is not available.');

        $this->resolve();
    }

    /**
     * @return void
     */
    public function testEmptyCartThrows(): void
    {
        $this->allowAll();
        $this->getCartForUser->method('execute')->willReturn($this->createCart(false));
        $this->merchantValidator->expects($this->never())->method('getMerchantSession');

        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('Your cart is empty.');

        $this->resolve();
    }

    /**
     * @return void
     */
    public function testDisallowedDomainThrows(): void
    {
        $this->config->method('isApplePayEnabled')->willReturn(true);
        $this->merchantValidator->method('isValidationUrlAllowed')->willReturn(true);
        $this->getCartForUser->method('execute')->willReturn($this->createCart());
        $this->domainResolver->expects($this->once())
            ->method('isAllowed')
            ->with(self::STORE_ID, 'evil.example.net')
            ->willReturn(false);
        $this->merchantValidator->expects($this->never())->method('getMerchantSession');

        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('is not allowed for Apple Pay');

        $this->resolve([
            'domain' => 'evil.example.net',
        ]);
    }

    /**
     * @return void
     */
    public function testDisallowedValidationUrlThrows(): void
    {
        $this->config->method('isApplePayEnabled')->willReturn(true);
        $this->domainResolver->method('isAllowed')->willReturn(true);
        $this->getCartForUser->method('execute')->willReturn($this->createCart());
        $this->merchantValidator->expects($this->once())
            ->method('isValidationUrlAllowed')
            ->with('https://169.254.169.254/latest/meta-data')
            ->willReturn(false);
        $this->merchantValidator->expects($this->never())->method('getMerchantSession');

        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('Invalid Apple Pay validation URL.');

        $this->resolve([
            'validation_url' => 'https://169.254.169.254/latest/meta-data',
        ]);
    }

    /**
     * @return void
     */
    public function testRateLimitPerCart(): void
    {
        $this->allowAll();
        $this->getCartForUser->method('execute')->willReturn($this->createCart());
        $this->merchantValidator->expects($this->exactly(ApplePayValidateMerchant::MAX_PER_CART))
            ->method('getMerchantSession')
            ->willReturn([
                'merchantSessionIdentifier' => 'SSH123',
            ]);

        for ($i = 0; $i < ApplePayValidateMerchant::MAX_PER_CART; $i++) {
            $this->resolve();
        }

        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('Too many Apple Pay attempts.');

        $this->resolve();
    }

    /**
     * @return void
     */
    public function testValidatorErrorBecomesInputError(): void
    {
        $this->allowAll();
        $this->getCartForUser->method('execute')->willReturn($this->createCart());
        $this->merchantValidator->method('getMerchantSession')->willThrowException(
            new LocalizedException(__('Apple Pay is unavailable right now. Please try again.'))
        );

        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('Apple Pay is unavailable right now. Please try again.');

        $this->resolve();
    }

    /**
     * Unexpected errors must not leak their message to the client.
     *
     * @return void
     */
    public function testUnexpectedErrorIsMasked(): void
    {
        $this->allowAll();
        $this->getCartForUser->method('execute')->willReturn($this->createCart());
        $this->merchantValidator->method('getMerchantSession')->willThrowException(
            new RuntimeException('curl secret detail')
        );

        try {
            $this->resolve();
            $this->fail('Expected an exception.');
        } catch (GraphQlInputException $exception) {
            $this->assertSame('Apple Pay is unavailable right now. Please try again.', $exception->getMessage());
        }
    }

    /**
     * @return void
     */
    private function allowAll(): void
    {
        $this->config->method('isApplePayEnabled')->willReturn(true);
        $this->domainResolver->method('isAllowed')->willReturn(true);
        $this->merchantValidator->method('isValidationUrlAllowed')->willReturn(true);
    }

    /**
     * @param array $overrides
     * @return array
     */
    private function resolve(array $overrides = []): array
    {
        return $this->resolver->resolve(
            $this->createMock(Field::class),
            $this->context,
            $this->createMock(ResolveInfo::class),
            null,
            [
                'input' => array_merge($this->getInput(), $overrides),
            ]
        );
    }

    /**
     * @return array
     */
    private function getInput(): array
    {
        return [
            'cart_id' => 'masked123',
            'validation_url' => self::VALIDATION_URL,
            'domain' => 'shop.example.com',
        ];
    }

    /**
     * @param bool $hasItems
     * @return Quote|MockObject
     */
    private function createCart(bool $hasItems = true): Quote|MockObject
    {
        $cart = $this->createPartialMock(
            Quote::class,
            [
                'getId',
                'getStoreId',
                'hasItems',
            ]
        );
        $cart->method('getId')->willReturn(self::CART_ID);
        $cart->method('getStoreId')->willReturn(self::STORE_ID);
        $cart->method('hasItems')->willReturn($hasItems);

        return $cart;
    }
}
