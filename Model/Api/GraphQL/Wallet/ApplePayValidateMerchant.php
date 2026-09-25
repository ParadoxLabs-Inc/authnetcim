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

namespace ParadoxLabs\Authnetcim\Model\Api\GraphQL\Wallet;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\QuoteGraphQl\Model\Cart\GetCartForUser;
use Magento\Store\Model\StoreManagerInterface;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\DomainResolver;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\MerchantValidator;
use ParadoxLabs\Authnetcim\Model\Wallet\Config;
use Throwable;

/**
 * Mutation authnetcimApplePayValidateMerchant: Apple Pay merchant validation for headless storefronts.
 *
 * Headless counterpart of Controller\Wallet\ApplePayValidate. The validation URL and domain come from the client, so
 * both are checked against allow-lists before the merchant identity certificate is used.
 */
class ApplePayValidateMerchant implements ResolverInterface
{
    /**
     * Merchant validation runs once per payment sheet; this bounds abuse of the outbound Apple call per cart.
     * GraphQL has no session, so the controller's per-session cap is applied per cart instead.
     */
    public const MAX_PER_CART = 30;

    public const RATE_LIMIT_LIFETIME = 3600;

    private const CACHE_KEY_PREFIX = 'authnetcim_applepay_validations_';

    /**
     * @param GetCartForUser $getCartForUser
     * @param StoreManagerInterface $storeManager
     * @param Config $config
     * @param MerchantValidator $merchantValidator
     * @param DomainResolver $domainResolver
     * @param CacheInterface $cache
     * @param Json $json
     */
    public function __construct(
        private readonly GetCartForUser $getCartForUser,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly MerchantValidator $merchantValidator,
        private readonly DomainResolver $domainResolver,
        private readonly CacheInterface $cache,
        private readonly Json $json,
    ) {
    }

    /**
     * Get an Apple Pay merchant session for the given cart, validation URL and storefront domain.
     *
     * @param Field $field
     * @param ContextInterface $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return array
     * @throws GraphQlInputException
     * @throws GraphQlAuthorizationException
     * @throws GraphQlNoSuchEntityException
     * @throws NoSuchEntityException
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null,
    ) {
        $input         = $args['input'] ?? null;
        $cartId        = $this->getInputString($input, 'cart_id');
        $validationUrl = $this->getInputString($input, 'validation_url');
        $domain        = strtolower($this->getInputString($input, 'domain'));

        $currentStoreId = (int)$this->storeManager->getStore()->getId();
        if ($this->config->isApplePayEnabled($currentStoreId) !== true) {
            throw new GraphQlInputException(__('Apple Pay is not available.'));
        }

        $cart = $this->getCartForUser->execute($cartId, (int)$context->getUserId(), $currentStoreId);

        if (!$cart->hasItems()) {
            throw new GraphQlInputException(__('Your cart is empty.'));
        }

        $storeId = (int)$cart->getStoreId();
        if ($this->domainResolver->isAllowed($storeId, $domain) !== true) {
            throw new GraphQlInputException(__('The domain "%1" is not allowed for Apple Pay.', $domain));
        }

        if ($this->merchantValidator->isValidationUrlAllowed($validationUrl) !== true) {
            throw new GraphQlInputException(__('Invalid Apple Pay validation URL.'));
        }

        $this->enforceRateLimit((int)$cart->getId());

        try {
            $session = $this->merchantValidator->getMerchantSession($validationUrl, $domain, $storeId);
        } catch (LocalizedException $exception) {
            throw new GraphQlInputException(__($exception->getMessage()), $exception);
        } catch (Throwable $exception) {
            throw new GraphQlInputException(
                __('Apple Pay is unavailable right now. Please try again.'),
                $exception
            );
        }

        return [
            'merchant_session' => $this->json->serialize($session),
        ];
    }

    /**
     * Get a required, non-empty string field from the mutation input.
     *
     * @param mixed $input
     * @param string $key
     * @return string
     * @throws GraphQlInputException
     */
    private function getInputString(mixed $input, string $key): string
    {
        $inputValue = is_array($input) && is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        if ($inputValue === '') {
            throw new GraphQlInputException(__('Required parameter "%1" is missing.', $key));
        }

        return $inputValue;
    }

    /**
     * Limit validation calls per cart.
     *
     * @param int $cartId
     * @return void
     * @throws GraphQlInputException
     */
    private function enforceRateLimit(int $cartId): void
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $cartId;
        $count    = (int)$this->cache->load($cacheKey) + 1;

        if ($count > self::MAX_PER_CART) {
            throw new GraphQlInputException(
                __('Too many Apple Pay attempts. Please use another payment method.')
            );
        }

        $this->cache->save((string)$count, $cacheKey, [], self::RATE_LIMIT_LIFETIME);
    }
}
