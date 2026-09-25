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

use ParadoxLabs\Authnetcim\Model\Wallet\Config;

/**
 * Creates a fresh, initialized wallet gateway for a store. Gateways hold per-transaction state, so never share one.
 */
class GatewayProvider
{
    /**
     * @param GatewayFactory $gatewayFactory
     * @param Config $config
     */
    public function __construct(
        private readonly GatewayFactory $gatewayFactory,
        private readonly Config $config,
    ) {
    }

    /**
     * Get an initialized gateway for the given store.
     *
     * @param int|null $storeId
     * @return Gateway
     */
    public function get(?int $storeId): Gateway
    {
        /** @var Gateway $gateway */
        $gateway = $this->gatewayFactory->create();
        $gateway->init($this->config->getGatewayParameters($storeId));

        return $gateway;
    }
}
