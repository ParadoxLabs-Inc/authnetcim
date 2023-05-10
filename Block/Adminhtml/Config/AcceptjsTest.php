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
 * @link https://support.paradoxlabs.com
 */

namespace ParadoxLabs\Authnetcim\Block\Adminhtml\Config;

/**
 * AcceptjsTest Class
 */
class AcceptjsTest extends \ParadoxLabs\TokenBase\Block\Adminhtml\Config\ApiTest
{
    /**
     * @var string
     */
    protected $code = 'authnetcim';

    /**
     * @var \ParadoxLabs\TokenBase\Model\Method\Factory
     */
    protected $methodFactory;

    /**
     * @var \ParadoxLabs\Authnetcim\Model\Method
     */
    protected $method;

    /**
     * @param \Magento\Backend\Block\Template\Context $context
     * @param \ParadoxLabs\TokenBase\Helper\Data $helper
     * @param \Magento\Store\Model\StoreFactory $storeFactory
     * @param \Magento\Store\Model\WebsiteFactory $websiteFactory
     * @param \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
     * @param array $data
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \ParadoxLabs\TokenBase\Helper\Data $helper,
        \Magento\Store\Model\StoreFactory $storeFactory,
        \Magento\Store\Model\WebsiteFactory $websiteFactory,
        \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory,
        array $data = []
    ) {
        $this->setTemplate('ParadoxLabs_Authnetcim::config/acceptjs-test.phtml');
        parent::__construct($context, $helper, $storeFactory, $websiteFactory, $methodFactory, $data);
    }

    /**
     * @return \ParadoxLabs\Authnetcim\Model\Method
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getMethodInstance()
    {
        /** @var \ParadoxLabs\Authnetcim\Model\Method $method */
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
