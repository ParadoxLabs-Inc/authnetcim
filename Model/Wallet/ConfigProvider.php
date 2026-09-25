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

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Checkout config for the wallet payment method renderer.
 */
class ConfigProvider implements ConfigProviderInterface
{
    /**
     * @param CheckoutConfig $checkoutConfig
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly CheckoutConfig $checkoutConfig,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * @return array
     */
    public function getConfig()
    {
        $config = $this->checkoutConfig->getConfig((int)$this->storeManager->getStore()->getId());
        if (empty($config)) {
            return [];
        }

        return [
            'payment' => [
                WalletType::METHOD_CODE => $config,
            ],
        ];
    }
}
