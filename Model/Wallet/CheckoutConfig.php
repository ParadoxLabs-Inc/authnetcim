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

namespace ParadoxLabs\Authnetcim\Model\Wallet;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Build the client-side wallet configuration (checkout config provider, GraphQL, Hyvä).
 */
class CheckoutConfig
{
    public const APPLE_PAY_SDK_URL = 'https://applepay.cdn-apple.com/jsapi/1.latest/apple-pay-sdk.js';
    public const GOOGLE_PAY_SDK_URL = 'https://pay.google.com/gp/p/js/pay.js';

    /**
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly UrlInterface $urlBuilder,
    ) {
    }

    /**
     * Get the wallet config for the given store. Empty when no wallet is enabled, so nothing loads on the frontend.
     *
     * @param int|null $storeId
     * @return array
     */
    public function getConfig(?int $storeId = null): array
    {
        $wallets = $this->config->getEnabledWallets($storeId);
        if (empty($wallets)) {
            return [];
        }

        $store  = $this->storeManager->getStore($storeId);
        $result = [
            'wallets' => $wallets,
            'countryCode' => $this->getMerchantCountry($storeId),
            'currencyCode' => (string)$store->getBaseCurrencyCode(),
            'sandbox' => $this->config->isSandbox($storeId),
        ];

        if (in_array(WalletType::APPLE_PAY, $wallets, true)) {
            $result['applePay'] = [
                'sdkUrl' => self::APPLE_PAY_SDK_URL,
                'merchantIdentifier' => $this->config->getApplePayMerchantIdentifier($storeId),
                'displayName' => $this->config->getApplePayDisplayName($storeId),
                'supportedNetworks' => $this->config->getNetworks(WalletType::APPLE_PAY, $storeId),
                'merchantCapabilities' => [
                    'supports3DS',
                ],
                'buttonStyle' => $this->config->getApplePayButtonStyle($storeId),
                'buttonType' => $this->config->getApplePayButtonType($storeId),
                'validateUrl' => $this->urlBuilder->getUrl(
                    'authnetcim/wallet/applePayValidate',
                    [
                        '_secure' => true,
                    ]
                ),
            ];
        }

        if (in_array(WalletType::GOOGLE_PAY, $wallets, true)) {
            $result['googlePay'] = [
                'sdkUrl' => self::GOOGLE_PAY_SDK_URL,
                'environment' => $this->config->isSandbox($storeId) ? 'TEST' : 'PRODUCTION',
                'gateway' => 'authorizenet',
                'gatewayMerchantId' => $this->config->getGooglePayGatewayMerchantId($storeId),
                'merchantId' => $this->config->getGooglePayMerchantId($storeId),
                'merchantName' => $this->config->getGooglePayMerchantName($storeId),
                'allowedCardNetworks' => $this->config->getNetworks(WalletType::GOOGLE_PAY, $storeId),
                'allowedAuthMethods' => $this->config->getGooglePayAuthMethods($storeId),
                'buttonColor' => $this->config->getGooglePayButtonColor($storeId),
                'buttonType' => $this->config->getGooglePayButtonType($storeId),
            ];
        }

        return $result;
    }

    /**
     * Get the merchant's country (store information, falling back to the default country).
     *
     * @param int|null $storeId
     * @return string
     */
    private function getMerchantCountry(?int $storeId): string
    {
        $country = (string)$this->scopeConfig->getValue(
            'general/store_information/country_id',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($country === '') {
            $country = (string)$this->scopeConfig->getValue(
                'general/country/default',
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
        }

        return $country !== '' ? $country : 'US';
    }
}
