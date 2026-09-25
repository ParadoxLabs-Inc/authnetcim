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
use Magento\Store\Model\ScopeInterface;

/**
 * Typed reader for wallet configuration, plus the Authorize.net CIM settings the wallet shares.
 */
class Config
{
    private const WALLET_PATH = 'payment/authnetcim_wallet/';
    private const AUTHNETCIM_PATH = 'payment/authnetcim/';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Pem $pem
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Pem $pem,
    ) {
    }

    /**
     * Whether the wallet payment method is enabled at all.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isActive(?int $storeId = null): bool
    {
        return $this->getFlag('active', $storeId)
            && $this->getAuthnetcimValue('login', $storeId) !== ''
            && $this->getAuthnetcimValue('trans_key', $storeId) !== '';
    }

    /**
     * Whether Apple Pay is enabled and has everything it needs to work.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isApplePayEnabled(?int $storeId = null): bool
    {
        $certificate = $this->getApplePayIdentityCert($storeId);

        // An expired identity certificate fails every merchant validation, so hide Apple Pay instead of
        // showing a button that can never work.
        return $this->isActive($storeId)
            && $this->getFlag('applepay_active', $storeId)
            && $this->getApplePayMerchantIdentifier($storeId) !== ''
            && $certificate !== ''
            && $this->getApplePayIdentityKey($storeId) !== ''
            && !$this->pem->isCertificateExpired($certificate);
    }

    /**
     * Whether Google Pay is enabled and has everything it needs to work.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isGooglePayEnabled(?int $storeId = null): bool
    {
        return $this->isActive($storeId)
            && $this->getFlag('googlepay_active', $storeId)
            && $this->getGooglePayGatewayMerchantId($storeId) !== ''
            && ($this->isSandbox($storeId) || $this->getGooglePayMerchantId($storeId) !== '');
    }

    /**
     * Whether the given wallet type is enabled.
     *
     * @param string $walletType
     * @param int|null $storeId
     * @return bool
     */
    public function isWalletEnabled(string $walletType, ?int $storeId = null): bool
    {
        return match ($walletType) {
            WalletType::APPLE_PAY => $this->isApplePayEnabled($storeId),
            WalletType::GOOGLE_PAY => $this->isGooglePayEnabled($storeId),
            default => false,
        };
    }

    /**
     * Get all enabled wallet types.
     *
     * @param int|null $storeId
     * @return string[]
     */
    public function getEnabledWallets(?int $storeId = null): array
    {
        return array_values(
            array_filter(
                array_keys(WalletType::DESCRIPTORS),
                fn (string $type): bool => $this->isWalletEnabled($type, $storeId)
            )
        );
    }

    /**
     * Get the payment action (authorize or authorize_capture).
     *
     * @param int|null $storeId
     * @return string
     */
    public function getPaymentAction(?int $storeId = null): string
    {
        return $this->getValue('payment_action', $storeId);
    }

    /**
     * Get the Apple Pay merchant identifier.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApplePayMerchantIdentifier(?int $storeId = null): string
    {
        return $this->getValue('applepay_merchant_identifier', $storeId);
    }

    /**
     * Get the Apple Pay display name (merchant validation + payment sheet).
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApplePayDisplayName(?int $storeId = null): string
    {
        $name = $this->getValue('applepay_display_name', $storeId);
        if ($name === '') {
            $name = (string)$this->scopeConfig->getValue(
                'general/store_information/name',
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
        }

        // Apple caps displayName at 64 characters.
        return mb_substr($name, 0, 64);
    }

    /**
     * Get the Apple Pay merchant identity certificate (PEM).
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApplePayIdentityCert(?int $storeId = null): string
    {
        return trim($this->getValue('applepay_identity_cert', $storeId));
    }

    /**
     * Get the Apple Pay merchant identity private key (PEM). Decrypted by the config backend model metadata.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApplePayIdentityKey(?int $storeId = null): string
    {
        return trim($this->getValue('applepay_identity_key', $storeId));
    }

    /**
     * Get the Apple Pay domain association file contents.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApplePayDomainAssociation(?int $storeId = null): string
    {
        return trim($this->getValue('applepay_domain_association', $storeId));
    }

    /**
     * Get additional storefront domains allowed as the Apple Pay initiativeContext (headless storefronts).
     *
     * @param int|null $storeId
     * @return string[]
     */
    public function getApplePayInitiativeDomains(?int $storeId = null): array
    {
        $domains = array_map(
            static fn (string $domain): string => strtolower(trim($domain)),
            explode(',', $this->getValue('applepay_initiative_domains', $storeId))
        );

        return array_values(array_filter($domains));
    }

    /**
     * Get the Apple Pay button style.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApplePayButtonStyle(?int $storeId = null): string
    {
        return $this->getValue('applepay_button_style', $storeId) ?: 'black';
    }

    /**
     * Get the Apple Pay button type.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApplePayButtonType(?int $storeId = null): string
    {
        return $this->getValue('applepay_button_type', $storeId) ?: 'plain';
    }

    /**
     * Get the Authorize.net Payment Gateway ID, used as Google Pay's gatewayMerchantId.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getGooglePayGatewayMerchantId(?int $storeId = null): string
    {
        return trim($this->getValue('googlepay_gateway_merchant_id', $storeId));
    }

    /**
     * Get the Google merchant ID (required for the PRODUCTION environment).
     *
     * @param int|null $storeId
     * @return string
     */
    public function getGooglePayMerchantId(?int $storeId = null): string
    {
        return trim($this->getValue('googlepay_merchant_id', $storeId));
    }

    /**
     * Get the Google Pay merchant name.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getGooglePayMerchantName(?int $storeId = null): string
    {
        $name = $this->getValue('googlepay_merchant_name', $storeId);

        return $name !== '' ? $name : $this->getApplePayDisplayName($storeId);
    }

    /**
     * Get the allowed Google Pay card auth methods.
     *
     * @param int|null $storeId
     * @return string[]
     */
    public function getGooglePayAuthMethods(?int $storeId = null): array
    {
        $methods = array_intersect(
            explode(',', $this->getValue('googlepay_auth_methods', $storeId)),
            [
                'PAN_ONLY',
                'CRYPTOGRAM_3DS',
            ]
        );

        return !empty($methods) ? array_values($methods) : ['CRYPTOGRAM_3DS'];
    }

    /**
     * Get the Google Pay button color.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getGooglePayButtonColor(?int $storeId = null): string
    {
        return $this->getValue('googlepay_button_color', $storeId) ?: 'default';
    }

    /**
     * Get the Google Pay button type.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getGooglePayButtonType(?int $storeId = null): string
    {
        return $this->getValue('googlepay_button_type', $storeId) ?: 'plain';
    }

    /**
     * Get the card types enabled on Authorize.net CIM, as Magento cc type codes.
     *
     * @param int|null $storeId
     * @return string[]
     */
    public function getCardTypes(?int $storeId = null): array
    {
        return array_values(array_filter(explode(',', $this->getAuthnetcimValue('cctypes', $storeId))));
    }

    /**
     * Get wallet networks for the given wallet type, limited to the enabled card types.
     *
     * @param string $walletType
     * @param int|null $storeId
     * @return string[]
     */
    public function getNetworks(string $walletType, ?int $storeId = null): array
    {
        $networks = [];
        foreach ($this->getCardTypes($storeId) as $ccType) {
            if (isset(WalletType::NETWORK_MAP[$ccType][$walletType])) {
                $networks[] = WalletType::NETWORK_MAP[$ccType][$walletType];
            }
        }

        return $networks;
    }

    /**
     * Whether Authorize.net CIM is in sandbox mode.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isSandbox(?int $storeId = null): bool
    {
        return (bool)$this->getAuthnetcimValue('test', $storeId);
    }

    /**
     * Whether to send line items with transactions (shared Authorize.net CIM setting).
     *
     * @param int|null $storeId
     * @return bool
     */
    public function shouldSendLineItems(?int $storeId = null): bool
    {
        return (bool)$this->getAuthnetcimValue('send_line_items', $storeId);
    }

    /**
     * Whether to send the shipping address with transactions (shared Authorize.net CIM setting).
     *
     * @param int|null $storeId
     * @return bool
     */
    public function shouldSendShippingAddress(?int $storeId = null): bool
    {
        return (bool)$this->getAuthnetcimValue('send_shipping_address', $storeId);
    }

    /**
     * Get gateway init parameters from the Authorize.net CIM configuration.
     *
     * @param int|null $storeId
     * @return array
     */
    public function getGatewayParameters(?int $storeId = null): array
    {
        return [
            'login' => $this->getAuthnetcimValue('login', $storeId),
            'password' => $this->getAuthnetcimValue('trans_key', $storeId),
            'secret_key' => $this->getAuthnetcimValue('secret_key', $storeId),
            'test_mode' => $this->getAuthnetcimValue('test', $storeId),
            'verify_ssl' => $this->getAuthnetcimValue('verify_ssl', $storeId),
        ];
    }

    /**
     * Get a wallet config value.
     *
     * @param string $field
     * @param int|null $storeId
     * @return string
     */
    public function getValue(string $field, ?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::WALLET_PATH . $field,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get a wallet config flag.
     *
     * @param string $field
     * @param int|null $storeId
     * @return bool
     */
    private function getFlag(string $field, ?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::WALLET_PATH . $field,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get an Authorize.net CIM config value.
     *
     * @param string $field
     * @param int|null $storeId
     * @return string
     */
    private function getAuthnetcimValue(string $field, ?int $storeId = null): string
    {
        return trim(
            (string)$this->scopeConfig->getValue(
                self::AUTHNETCIM_PATH . $field,
                ScopeInterface::SCOPE_STORE,
                $storeId
            )
        );
    }
}
