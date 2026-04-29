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

namespace ParadoxLabs\Authnetcim\Controller\Adminhtml\Hosted;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Registry;
use Override;
use ParadoxLabs\Authnetcim\Model\Service\AcceptCustomer\BackendRequest;

class UpdateCard extends GetNewCard
{
    /**
     * UpdateCard constructor.
     *
     * @param Context $context
     * @param Validator $formKey
     * @param BackendRequest $hostedForm
     * @param Registry $registry
     * @param CustomerRepositoryInterface $customerRepository
     */
    public function __construct(
        Context $context,
        Validator $formKey,
        BackendRequest $hostedForm,
        protected readonly Registry $registry,
        protected readonly CustomerRepositoryInterface $customerRepository
    ) {
        parent::__construct(
            $context,
            $formKey,
            $hostedForm
        );
    }

    /**
     * Execute action based on request and return result
     *
     * @return ResultInterface
     */
    #[Override]
    public function execute()
    {
        $this->getCustomer();

        return parent::execute();
    }

    /**
     * Get current customer model.
     *
     * @return CustomerInterface
     */
    protected function getCustomer(): CustomerInterface
    {
        if ($this->registry->registry('current_customer') instanceof CustomerInterface) {
            return $this->registry->registry('current_customer');
        }

        $customerId = (int)$this->getRequest()->getParam('id');
        $customer   = $this->customerRepository->getById($customerId);

        $this->registry->register('current_customer', $customer);

        return $customer;
    }
}
