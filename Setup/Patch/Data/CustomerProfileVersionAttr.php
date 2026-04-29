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

namespace ParadoxLabs\Authnetcim\Setup\Patch\Data;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\StateException;
use Magento\Customer\Model\Customer;
use Magento\Customer\Setup\CustomerSetup;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;
use ParadoxLabs\TokenBase\Helper\Operation;
use Throwable;

class CustomerProfileVersionAttr implements DataPatchInterface, PatchRevertableInterface
{
    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param CustomerSetupFactory $customerSetupFactory
     * @param AttributeRepositoryInterface $attributeRepository
     * @param Operation $helper
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly CustomerSetupFactory $customerSetupFactory,
        private readonly AttributeRepositoryInterface $attributeRepository,
        private readonly Operation $helper,
    ) {
    }

    /**
     * Run patch
     *
     * authnetcim_profile_version customer attribute: Indicates whether each customer needs
     * the card upgrade process run, to migrate data from CIM 1.x to CIM 2+.
     *
     * @return $this
     */
    public function apply()
    {
        /** @var CustomerSetup $customerSetup */
        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $this->moduleDataSetup->startSetup();

        try {
            if ($customerSetup->getAttributeId(Customer::ENTITY, 'authnetcim_profile_version') === false) {
                $this->addAttribute($customerSetup);
            } else {
                $this->updateAttribute($customerSetup);
            }
        } catch (Throwable $exception) {
            $this->helper->log('authnetcim', $exception->getMessage());
        }

        $this->moduleDataSetup->endSetup();

        return $this;
    }

    /**
     * Get array of patches that have to be executed prior to this.
     *
     * @return string[]
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * Get aliases (previous names) for the patch.
     *
     * @return string[]
     */
    public function getAliases()
    {
        return [];
    }

    /**
     * @param CustomerSetup $customerSetup
     * @return void
     * @throws LocalizedException
     * @throws StateException
     * @throws \Exception
     */
    public function addAttribute(CustomerSetup $customerSetup): void
    {
        $customerSetup->addAttribute(
            Customer::ENTITY,
            'authnetcim_profile_version',
            [
                'label' => 'Authorize.Net CIM: Profile version (for updating legacy data)',
                'type' => 'int',
                'input' => 'text',
                'default' => '100',
                'position' => 71,
                'visible' => false,
                'required' => false,
                'system' => false,
                'user_defined' => false,
                'visible_on_front' => false,
            ]
        );

        $profileVersionAttr = $customerSetup->getEavConfig()->getAttribute(
            Customer::ENTITY,
            'authnetcim_profile_version'
        );

        $profileVersionAttr->addData(
            [
                'attribute_set_id' => $customerSetup->getDefaultAttributeSetId(Customer::ENTITY),
                'attribute_group_id' => $customerSetup->getDefaultAttributeGroupId(Customer::ENTITY),
                'used_in_forms' => [],
            ]
        );

        $this->attributeRepository->save($profileVersionAttr);
    }

    /**
     * @param CustomerSetup $customerSetup
     * @return void
     * @throws LocalizedException
     * @throws StateException
     */
    public function updateAttribute(CustomerSetup $customerSetup): void
    {
        /**
         * is_system must be 0 in order for attribute values to save.
         */
        $attribute = $customerSetup->getAttribute(Customer::ENTITY, 'authnetcim_profile_version');
        if (!is_array($attribute)) {
            return;
        }

        if (!isset($attribute['is_system']) || $attribute['is_system'] != 0) {
            $customerSetup->updateAttribute(
                Customer::ENTITY,
                $attribute['attribute_id'],
                'is_system',
                0
            );

            $profileVersionAttr = $customerSetup->getEavConfig()->getAttribute(
                Customer::ENTITY,
                'authnetcim_profile_version'
            );

            $profileVersionAttr->addData(
                [
                    'attribute_set_id' => $customerSetup->getDefaultAttributeSetId(Customer::ENTITY),
                    'attribute_group_id' => $customerSetup->getDefaultAttributeGroupId(Customer::ENTITY),
                    'used_in_forms' => [],
                ]
            );

            $this->attributeRepository->save($profileVersionAttr);
        }

        /**
         * is_visible should be 0 to prevent the attribute showing on forms.
         */
        if (!isset($attribute['is_visible']) || $attribute['is_visible'] != 0) {
            $customerSetup->updateAttribute(
                Customer::ENTITY,
                $attribute['attribute_id'],
                'is_visible',
                0
            );
        }

        /**
         * ... is_user_defined should be 0 to prevent the attribute showing on forms.
         */
        if (!isset($attribute['is_user_defined']) || $attribute['is_user_defined'] != 0) {
            $customerSetup->updateAttribute(
                Customer::ENTITY,
                $attribute['attribute_id'],
                'is_user_defined',
                0
            );
        }
    }

    /**
     * Rollback all changes, done by this patch
     *
     * @return void
     */
    public function revert()
    {
        /** @var CustomerSetup $customerSetup */
        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $this->moduleDataSetup->startSetup();

        $customerSetup->removeAttribute(
            Customer::ENTITY,
            'authnetcim_profile_version'
        );

        $this->moduleDataSetup->endSetup();
    }
}
