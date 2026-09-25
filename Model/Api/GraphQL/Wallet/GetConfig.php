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

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\QuoteGraphQl\Model\Cart\GetCartForUser;
use Magento\Store\Model\StoreManagerInterface;
use ParadoxLabs\Authnetcim\Model\Wallet\CheckoutConfig;

/**
 * Query authnetcimWalletConfig: client wallet configuration for a cart, in the shape the wallet client.js expects.
 */
class GetConfig implements ResolverInterface
{
    /**
     * @param GetCartForUser $getCartForUser
     * @param CheckoutConfig $checkoutConfig
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly GetCartForUser $getCartForUser,
        private readonly CheckoutConfig $checkoutConfig,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * Get the wallet config for the given cart. Disabled wallets return only enabled=false and no wallets.
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
        $cartId = $args['cart_id'] ?? null;
        if (!is_string($cartId) || trim($cartId) === '') {
            throw new GraphQlInputException(__('Required parameter "cart_id" is missing.'));
        }

        $cart = $this->getCartForUser->execute(
            $cartId,
            (int)$context->getUserId(),
            (int)$this->storeManager->getStore()->getId()
        );

        $config = $this->checkoutConfig->getConfig((int)$cart->getStoreId());
        if (empty($config)) {
            return [
                'enabled' => false,
                'wallets' => [],
            ];
        }

        // The Luma controller endpoint is session-bound; headless clients use authnetcimApplePayValidateMerchant.
        if (isset($config['applePay'])) {
            unset($config['applePay']['validateUrl']);
        }

        $config['enabled'] = true;
        $config['amount'] = (float)$cart->getBaseGrandTotal();

        // Authorize.net charges the base amount, so the sheet shows the cart's base currency.
        $baseCurrency = (string)$cart->getBaseCurrencyCode();
        if ($baseCurrency !== '') {
            $config['currencyCode'] = $baseCurrency;
        }

        return $config;
    }
}
