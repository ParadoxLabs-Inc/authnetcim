<?php declare(strict_types=1);
/**
 * Paradox Labs, Inc.
 * http://www.paradoxlabs.com
 * 717-431-3330
 *
 * Need help? Open a ticket in our support system:
 *  http://support.paradoxlabs.com
 *
 * @author      Ryan Hoerr <info@paradoxlabs.com>
 * @license     http://store.paradoxlabs.com/license.html
 */

namespace ParadoxLabs\Authnetcim\Setup\Patch\Data;

use Magento\Customer\Model\Customer;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

class CustomerProfileVersionAttr implements DataPatchInterface, PatchRevertableInterface
{
    /**
     * @var \Magento\Eav\Api\AttributeRepositoryInterface
     */
    private $attributeRepository;
    
    /**
     * @var \Magento\Customer\Setup\CustomerSetupFactory
     */
    private $customerSetupFactory;

    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param \Magento\Customer\Setup\CustomerSetupFactory $customerSetupFactory
     * @param \Magento\Eav\Api\AttributeRepositoryInterface $attributeRepository
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        \Magento\Customer\Setup\CustomerSetupFactory $customerSetupFactory,
        \Magento\Eav\Api\AttributeRepositoryInterface $attributeRepository
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->customerSetupFactory = $customerSetupFactory;
        $this->attributeRepository = $attributeRepository;
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
        /** @var \Magento\Customer\Setup\CustomerSetup $customerSetup */
        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $this->moduleDataSetup->startSetup();

        if ($customerSetup->getAttributeId(Customer::ENTITY, 'authnetcim_profile_version') === false) {
            $this->addAttribute($customerSetup);
        } else {
            $this->updateAttribute($customerSetup);
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
     * @param \Magento\Customer\Setup\CustomerSetup $customerSetup
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\StateException
     * @throws \Zend_Validate_Exception
     */
    public function addAttribute(\Magento\Customer\Setup\CustomerSetup $customerSetup): void
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
     * @param \Magento\Customer\Setup\CustomerSetup $customerSetup
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\StateException
     */
    public function updateAttribute(\Magento\Customer\Setup\CustomerSetup $customerSetup): void
    {
        /**
         * is_system must be 0 in order for attribute values to save.
         */
        $attribute = $customerSetup->getAttribute(Customer::ENTITY, 'authnetcim_profile_version');
        if ($attribute['is_system'] != 0) {
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
        if ($attribute['is_visible'] != 0) {
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
        if ($attribute['is_user_defined'] != 0) {
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
        /** @var \Magento\Customer\Setup\CustomerSetup $customerSetup */
        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $this->moduleDataSetup->startSetup();

        $customerSetup->removeAttribute(
            Customer::ENTITY,
            'authnetcim_profile_version'
        );

        $this->moduleDataSetup->endSetup();
    }
}
