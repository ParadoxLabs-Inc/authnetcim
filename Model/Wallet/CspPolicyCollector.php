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

use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use Magento\Store\Model\StoreManagerInterface;
use Throwable;

/**
 * Allow the Apple Pay and Google Pay SDK hosts, only for stores where that wallet is enabled.
 *
 * Wallets ship disabled; stores that never enable them should not allow these hosts.
 */
class CspPolicyCollector implements PolicyCollectorInterface
{
    /**
     * Wallet type => [policy id => hosts]
     */
    private const HOSTS = [
        WalletType::APPLE_PAY => [
            'script-src' => [
                'applepay.cdn-apple.com',
            ],
            'frame-src' => [
                'applepay.cdn-apple.com',
            ],
            'connect-src' => [
                'applepay.cdn-apple.com',
            ],
            'style-src' => [
                'applepay.cdn-apple.com',
            ],
            'img-src' => [
                'applepay.cdn-apple.com',
            ],
        ],
        WalletType::GOOGLE_PAY => [
            'script-src' => [
                'pay.google.com',
            ],
            'frame-src' => [
                'pay.google.com',
            ],
            'connect-src' => [
                'pay.google.com',
                'google.com',
            ],
            'img-src' => [
                'www.gstatic.com',
            ],
        ],
    ];

    /**
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * @param array $defaultPolicies
     * @return array
     */
    public function collect(array $defaultPolicies = []): array
    {
        try {
            $wallets = $this->config->getEnabledWallets((int)$this->storeManager->getStore()->getId());
        } catch (Throwable) {
            return $defaultPolicies;
        }

        $hostsByPolicy = [];
        foreach ($wallets as $wallet) {
            foreach (self::HOSTS[$wallet] ?? [] as $policyId => $hosts) {
                $hostsByPolicy[$policyId] = array_merge($hostsByPolicy[$policyId] ?? [], $hosts);
            }
        }

        foreach ($hostsByPolicy as $policyId => $hosts) {
            $defaultPolicies[] = new FetchPolicy($policyId, false, $hosts);
        }

        return $defaultPolicies;
    }
}
