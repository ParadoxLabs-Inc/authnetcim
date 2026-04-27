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

namespace ParadoxLabs\Authnetcim\Observer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Registry;
use Throwable;

class CheckoutFailureClearProfileIdObserver implements ObserverInterface
{
    /**
     * @param Registry $registry
     * @param CustomerRepositoryInterface $customerRepository
     */
    public function __construct(
        protected readonly Registry $registry,
        protected readonly CustomerRepositoryInterface $customerRepository
    ) {
    }

    /**
     * Check for authnetcim_profile_id queued for deletion after order fail.
     * We can't save it there, so we register and do it here instead. Magic.
     *
     * This is ultimately to prevent failure loops on checkout where an invalid
     * ID prevents payment, and can't be resolved by any normal means.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        try {
            /** @var CustomerInterface */
            $customer = $this->registry->registry('queue_profileid_deletion');

            if ($customer instanceof CustomerInterface && $customer->getId() > 0) {
                $customer->setCustomAttribute('authnetcim_profile_id', '');

                $this->customerRepository->save($customer);
            }
        } catch (Throwable) {
            // Do nothing on error -- we don't want it causing any more problems.
        }
    }
}
