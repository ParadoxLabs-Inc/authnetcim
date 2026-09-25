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

namespace ParadoxLabs\Authnetcim\Controller;

use Magento\Framework\App\Action\Forward;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;

/**
 * Route the Apple Pay domain verification path to the wallet controller, which the standard router cannot match (leading dot, no frontName).
 */
class Router implements RouterInterface
{
    public const DOMAIN_ASSOCIATION_PATH = '.well-known/apple-developer-merchantid-domain-association';

    /**
     * @param ActionFactory $actionFactory
     */
    public function __construct(
        private readonly ActionFactory $actionFactory,
    ) {
    }

    /**
     * @param RequestInterface $request
     * @return ActionInterface|null
     */
    public function match(RequestInterface $request)
    {
        /** @var Http $request */
        if (trim((string)$request->getPathInfo(), '/') !== self::DOMAIN_ASSOCIATION_PATH
            || $request->getModuleName() === 'authnetcim') {
            return null;
        }

        $request->setModuleName('authnetcim')
                ->setControllerName('wallet')
                ->setActionName('domainAssociation');

        return $this->actionFactory->create(Forward::class);
    }
}
