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

namespace ParadoxLabs\Authnetcim\Block\Adminhtml\Config;

use ParadoxLabs\TokenBase\Block\Adminhtml\Config\ApiTest;
use ParadoxLabs\Authnetcim\Model\Method;
use Magento\Framework\Exception\LocalizedException;
use Magento\Backend\Block\Template\Context;
use Magento\Store\Model\StoreFactory;
use Magento\Store\Model\WebsiteFactory;
use ParadoxLabs\TokenBase\Helper\Data;
use ParadoxLabs\TokenBase\Model\Method\Factory;

class AcceptjsTest extends ApiTest
{
    /**
     * @var string
     */
    protected $code = 'authnetcim';

    /**
     * @var Method
     */
    protected $method;

    /**
     * @param Context $context
     * @param Data $helper
     * @param StoreFactory $storeFactory
     * @param WebsiteFactory $websiteFactory
     * @param Factory $methodFactory
     * @param array $data
     */
    public function __construct(
        Context $context,
        Data $helper,
        StoreFactory $storeFactory,
        WebsiteFactory $websiteFactory,
        Factory $methodFactory,
        array $data = []
    ) {
        $this->setTemplate('ParadoxLabs_Authnetcim::config/acceptjs-test.phtml');
        parent::__construct($context, $helper, $storeFactory, $websiteFactory, $methodFactory, $data);
    }

    /**
     * @return Method
     * @throws LocalizedException
     */
    public function getMethodInstance()
    {
        /** @var Method $method */
        $this->method = $this->methodFactory->getMethodInstance($this->code);
        $this->method->setStore($this->getStoreId());

        return $this->method;
    }

    /**
     * Method to test the API connection. Should return a string indicating success or error.
     * NOTE: This is not used for Accept.js testing.
     *
     * @return mixed
     */
    protected function testApi()
    {
        return null;
    }
}
