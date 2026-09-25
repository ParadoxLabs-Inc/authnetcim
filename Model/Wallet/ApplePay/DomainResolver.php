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

namespace ParadoxLabs\Authnetcim\Model\Wallet\ApplePay;

use Magento\Store\Model\StoreManagerInterface;
use ParadoxLabs\Authnetcim\Model\Wallet\Config;

/**
 * Decide which storefront domain to send Apple as the merchant validation initiativeContext.
 *
 * Only the store's own base URL hosts, or explicitly configured headless domains, are allowed.
 */
class DomainResolver
{
    /**
     * @param StoreManagerInterface $storeManager
     * @param Config $config
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
    ) {
    }

    /**
     * Get the domain to validate for. Falls back to the store's secure base URL host if $requested is not allowed.
     *
     * @param int|null $storeId
     * @param string|null $requested
     * @return string
     */
    public function resolve(?int $storeId, ?string $requested = null): string
    {
        $allowed   = $this->getAllowedDomains($storeId);
        $requested = strtolower(trim((string)$requested));

        if ($requested !== '' && in_array($requested, $allowed, true)) {
            return $requested;
        }

        return $allowed[0] ?? '';
    }

    /**
     * Check whether the given domain is allowed for the store.
     *
     * @param int|null $storeId
     * @param string $domain
     * @return bool
     */
    public function isAllowed(?int $storeId, string $domain): bool
    {
        return in_array(strtolower(trim($domain)), $this->getAllowedDomains($storeId), true);
    }

    /**
     * Get allowed domains: secure base URL host first, then unsecure, then configured headless domains.
     *
     * @param int|null $storeId
     * @return string[]
     */
    private function getAllowedDomains(?int $storeId): array
    {
        $store   = $this->storeManager->getStore($storeId);
        // phpcs:disable Magento2.Functions.DiscouragedFunction -- host extraction only
        $domains = [
            (string)parse_url((string)$store->getBaseUrl('link', true), PHP_URL_HOST),
            (string)parse_url((string)$store->getBaseUrl('link', false), PHP_URL_HOST),
        ];
        // phpcs:enable Magento2.Functions.DiscouragedFunction

        $domains = array_merge(
            array_map('strtolower', $domains),
            $this->config->getApplePayInitiativeDomains($storeId)
        );

        return array_values(array_unique(array_filter($domains)));
    }
}
