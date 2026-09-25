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

namespace ParadoxLabs\Authnetcim\Controller\Wallet;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;
use ParadoxLabs\Authnetcim\Model\Wallet\Config;

/**
 * Serve the Apple Pay domain verification file (/.well-known/apple-developer-merchantid-domain-association).
 *
 * @see \ParadoxLabs\Authnetcim\Controller\Router
 */
class DomainAssociation implements HttpGetActionInterface
{
    /**
     * @param RawFactory $rawFactory
     * @param ForwardFactory $forwardFactory
     * @param StoreManagerInterface $storeManager
     * @param Config $config
     */
    public function __construct(
        private readonly RawFactory $rawFactory,
        private readonly ForwardFactory $forwardFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
    ) {
    }

    /**
     * @return ResultInterface
     */
    public function execute()
    {
        $storeId  = (int)$this->storeManager->getStore()->getId();
        $contents = $this->config->getApplePayDomainAssociation($storeId);

        if ($contents === '' || $this->config->isActive($storeId) !== true
            || (bool)$this->config->getValue('applepay_active', $storeId) !== true) {
            /** @var Forward $forward */
            $forward = $this->forwardFactory->create();

            return $forward->forward('noroute');
        }

        /** @var Raw $result */
        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/plain', true);
        $result->setContents($contents);

        return $result;
    }
}
