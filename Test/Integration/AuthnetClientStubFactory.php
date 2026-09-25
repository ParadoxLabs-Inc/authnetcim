<?php declare(strict_types=1);
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

namespace ParadoxLabs\Authnetcim\Test\Integration;

use Magento\Framework\HTTP\ClientInterface;
use Magento\Framework\HTTP\ClientInterfaceFactory;

/**
 * Factory double returning a fixed {@see AuthnetClientStub} instead of a live HTTP client.
 *
 * {@see \ParadoxLabs\TokenBase\Model\AbstractGateway} requires a ClientInterfaceFactory instance,
 * so this extends the generated factory (bypassing its constructor) and is registered via
 * ObjectManager::addSharedInstance so a freshly-built Gateway receives the stubbed HTTP client.
 */
class AuthnetClientStubFactory extends ClientInterfaceFactory
{
    /**
     * Bypass the parent constructor: the object manager is not needed for the double.
     *
     * @param AuthnetClientStub $client
     */
    public function __construct(
        private readonly AuthnetClientStub $client,
    ) {
    }

    /**
     * Return the fixed stub client for every create() call.
     *
     * @param array $data
     * @return ClientInterface
     */
    public function create(array $data = [])
    {
        return $this->client;
    }
}
