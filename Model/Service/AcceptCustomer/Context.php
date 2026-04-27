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

namespace ParadoxLabs\Authnetcim\Model\Service\AcceptCustomer;

use Magento\Framework\Url;
use ParadoxLabs\Authnetcim\Helper\Data;
use ParadoxLabs\Authnetcim\Model\Service\CustomerProfile;
use ParadoxLabs\TokenBase\Api\CardRepositoryInterface;
use ParadoxLabs\TokenBase\Api\Data\CardInterfaceFactory;
use ParadoxLabs\TokenBase\Model\Method\Factory;

class Context
{
    /**
     * AbstractRequestHandler constructor.
     *
     * @param Url $urlBuilder
     * @param Factory $methodFactory
     * @param CardInterfaceFactory $cardFactory
     * @param CardRepositoryInterface $cardRepository
     * @param Data $helper
     * @param CustomerProfile $customerProfileService
     */
    public function __construct(
        private readonly Url $urlBuilder,
        private readonly Factory $methodFactory,
        private readonly CardInterfaceFactory $cardFactory,
        private readonly CardRepositoryInterface $cardRepository,
        private readonly Data $helper,
        private readonly CustomerProfile $customerProfileService
    ) {
    }

    /**
     * Get urlBuilder
     *
     * @return Url
     */
    public function getUrlBuilder()
    {
        return $this->urlBuilder;
    }

    /**
     * Get methodFactory
     *
     * @return Factory
     */
    public function getMethodFactory()
    {
        return $this->methodFactory;
    }

    /**
     * Get cardFactory
     *
     * @return CardInterfaceFactory
     */
    public function getCardFactory()
    {
        return $this->cardFactory;
    }

    /**
     * Get cardRepository
     *
     * @return CardRepositoryInterface
     */
    public function getCardRepository()
    {
        return $this->cardRepository;
    }

    /**
     * Get helper
     *
     * @return Data
     */
    public function getHelper()
    {
        return $this->helper;
    }

    /**
     * Get customerProfileService
     *
     * @return CustomerProfile
     */
    public function getCustomerProfileService()
    {
        return $this->customerProfileService;
    }
}
