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

class PaymentConfigSaveObserver implements \Magento\Framework\Event\ObserverInterface
{
    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    private $request;

    /**
     * @var \Magento\Eav\Api\AttributeRepositoryInterface
     */
    private $attributeRepository;

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var \ParadoxLabs\Authnetcim\Helper\Data
     */
    private $helper;

    /**
     * PaymentConfigSaveObserver constructor.
     *
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \Magento\Eav\Api\AttributeRepositoryInterface $attributeRepository
     * @param \Magento\Framework\App\ResourceConnection $resourceConnection
     * @param \ParadoxLabs\Authnetcim\Helper\Data $helper
     */
    public function __construct(
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Eav\Api\AttributeRepositoryInterface $attributeRepository,
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        \ParadoxLabs\Authnetcim\Helper\Data $helper
    ) {
        $this->request = $request;
        $this->attributeRepository = $attributeRepository;
        $this->resourceConnection = $resourceConnection;
        $this->helper = $helper;
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $changedPaths = $observer->getData('changed_paths');

        // Check that we have changed_paths, older versions of Magento may not. #1905113
        if ($changedPaths === null) {
            return;
        }

        $groups = $this->request->getParam('groups');

        $methodCodes = [
            'authnetcim',
            'authnetcim_ach',
        ];

        foreach ($methodCodes as $methodCode) {
            if (isset($groups[$methodCode]['fields']['login']['value'])
                && $groups[$methodCode]['fields']['login']['value'] !== '******'
                && in_array('payment/' . $methodCode . '/login', (array)$changedPaths, true)) {
                /**
                 * Value changed -- purge any cached authnetcim_profile_id values to be safe and avoid potential errors.
                 * This may also mean that paradoxlabs_stored_card.profile_id references are invalid, but we'll assume
                 * not until proven otherwise.
                 *
                 * NB: This is also not looking at scope, so setting/changing the value in any scope will remove all.
                 */
                try {
                    $this->purgeCachedProfileIds();
                } catch (\Exception $e) {
                    $this->helper->log(
                        'authnetcim',
                        __('Error when purging cached authnetcim_profile_id values: %1', $e->getMessage())
                    );
                }

                break;
            }
        }
    }

    /**
     * Remove any stored customer_entity_varchar -> authnetcim_profile_id values.
     *
     * @return void
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function purgeCachedProfileIds()
    {
        /** @var \Magento\Eav\Model\Entity\Attribute $attribute */
        $attribute = $this->attributeRepository->get(
            \Magento\Customer\Model\Customer::ENTITY,
            'authnetcim_profile_id'
        );

        if ($attribute instanceof \Magento\Eav\Model\Entity\Attribute
            && $attribute->getAttributeCode() === 'authnetcim_profile_id') {
            $db = $this->resourceConnection->getConnection();
            $affected = $db->delete(
                $db->getTableName($attribute->getBackendTable()),
                [
                    'attribute_id=?' => $attribute->getId(),
                ]
            );

            $this->helper->log(
                'authnetcim',
                __('Purged %1 cached authnetcim_profile_id values on API Login ID change.', $affected)
            );
        }
    }
}
