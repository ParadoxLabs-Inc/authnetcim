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

namespace ParadoxLabs\Authnetcim\Gateway\Wallet;

use Magento\Payment\Gateway\Config\ValueHandlerInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Store\Model\StoreManagerInterface;
use ParadoxLabs\Authnetcim\Model\Wallet\Config;
use Throwable;

/**
 * The wallet method is active only when at least one wallet (Apple Pay or Google Pay) is fully configured.
 *
 * Magento's storefront method list (and GraphQL available_payment_methods) checks isActive() on a method instance
 * with no payment attached, so the AvailabilityValidator never runs there. Answering 'active' here keeps an enabled
 * but unconfigured wallet out of those lists.
 */
class ActiveValueHandler implements ValueHandlerInterface
{
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
     * Whether the wallet method is active for the payment's (or given, or current) store.
     *
     * @param array $subject
     * @param int|string|null $storeId
     * @return bool
     */
    public function handle(array $subject, $storeId = null)
    {
        return !empty($this->config->getEnabledWallets($this->resolveStoreId($subject, $storeId)));
    }

    /**
     * Get the store to check: the payment's, else the one asked for, else the current store.
     *
     * @param array $subject
     * @param int|string|null $storeId
     * @return int|null
     */
    private function resolveStoreId(
        array $subject,
        $storeId,
    ): ?int {
        $payment = $subject['payment'] ?? null;
        if ($payment instanceof PaymentDataObjectInterface) {
            $paymentStoreId = $payment->getOrder()->getStoreId();

            if ($paymentStoreId !== null && $paymentStoreId !== '') {
                return (int)$paymentStoreId;
            }
        }

        if ($storeId !== null && $storeId !== '') {
            return (int)$storeId;
        }

        try {
            return (int)$this->storeManager->getStore()->getId();
        } catch (Throwable) {
            return null;
        }
    }
}
