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

namespace ParadoxLabs\Authnetcim\Observer;

class CheckoutFailureClearProfileIdObserver implements \Magento\Framework\Event\ObserverInterface
{
    /**
     * @var \Magento\Framework\Registry
     */
    protected $registry;

    /**
     * @var \Magento\Customer\Api\CustomerRepositoryInterface
     */
    protected $customerRepository;

    /**
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository
     */
    public function __construct(
        \Magento\Framework\Registry $registry,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository
    ) {
        $this->registry = $registry;
        $this->customerRepository = $customerRepository;
    }

    /**
     * Check for authnetcim_profile_id queued for deletion after order fail.
     * We can't save it there, so we register and do it here instead. Magic.
     *
     * This is ultimately to prevent failure loops on checkout where an invalid
     * ID prevents payment, and can't be resolved by any normal means.
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            /** @var \Magento\Customer\Api\Data\CustomerInterface */
            $customer = $this->registry->registry('queue_profileid_deletion');

            if ($customer instanceof \Magento\Customer\Api\Data\CustomerInterface && $customer->getId() > 0) {
                $customer->setCustomAttribute('authnetcim_profile_id', '');

                $this->customerRepository->save($customer);
            }
        } catch (\Exception $e) {
            // Do nothing on error -- we don't want it causing any more problems.
        }
    }
}
