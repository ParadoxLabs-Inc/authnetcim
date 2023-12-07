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
 * @link https://support.paradoxlabs.com
 */

namespace ParadoxLabs\Authnetcim\Model\Service\AcceptCustomer;

class Context
{
    /**
     * @var \Magento\Framework\Url
     */
    private $urlBuilder;

    /**
     * @var \ParadoxLabs\TokenBase\Model\Method\Factory
     */
    private $methodFactory;

    /**
     * @var \ParadoxLabs\TokenBase\Api\Data\CardInterfaceFactory
     */
    private $cardFactory;

    /**
     * @var \ParadoxLabs\TokenBase\Api\CardRepositoryInterface
     */
    private $cardRepository;

    /**
     * @var \ParadoxLabs\Authnetcim\Helper\Data
     */
    private $helper;

    /**
     * @var \ParadoxLabs\Authnetcim\Model\Service\CustomerProfile
     */
    private $customerProfileService;

    /**
     * AbstractRequestHandler constructor.
     *
     * @param \Magento\Framework\Url $urlBuilder
     * @param \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
     * @param \ParadoxLabs\TokenBase\Api\Data\CardInterfaceFactory $cardFactory
     * @param \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository
     * @param \ParadoxLabs\Authnetcim\Helper\Data $helper
     * @param \ParadoxLabs\Authnetcim\Model\Service\CustomerProfile $customerProfileService
     */
    public function __construct(
        \Magento\Framework\Url $urlBuilder,
        \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory,
        \ParadoxLabs\TokenBase\Api\Data\CardInterfaceFactory $cardFactory,
        \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository,
        \ParadoxLabs\Authnetcim\Helper\Data $helper,
        \ParadoxLabs\Authnetcim\Model\Service\CustomerProfile $customerProfileService
    ) {
        $this->urlBuilder = $urlBuilder;
        $this->methodFactory = $methodFactory;
        $this->cardFactory = $cardFactory;
        $this->cardRepository = $cardRepository;
        $this->helper = $helper;
        $this->customerProfileService = $customerProfileService;
    }

    /**
     * Get urlBuilder
     *
     * @return \Magento\Framework\Url
     */
    public function getUrlBuilder()
    {
        return $this->urlBuilder;
    }

    /**
     * Get methodFactory
     *
     * @return \ParadoxLabs\TokenBase\Model\Method\Factory
     */
    public function getMethodFactory()
    {
        return $this->methodFactory;
    }

    /**
     * Get cardFactory
     *
     * @return \ParadoxLabs\TokenBase\Api\Data\CardInterfaceFactory
     */
    public function getCardFactory()
    {
        return $this->cardFactory;
    }

    /**
     * Get cardRepository
     *
     * @return \ParadoxLabs\TokenBase\Api\CardRepositoryInterface
     */
    public function getCardRepository()
    {
        return $this->cardRepository;
    }

    /**
     * Get helper
     *
     * @return \ParadoxLabs\Authnetcim\Helper\Data
     */
    public function getHelper()
    {
        return $this->helper;
    }

    /**
     * Get customerProfileService
     *
     * @return \ParadoxLabs\Authnetcim\Model\Service\CustomerProfile
     */
    public function getCustomerProfileService()
    {
        return $this->customerProfileService;
    }
}
