<?php
/**
 * Paradox Labs, Inc.
 * http://www.paradoxlabs.com
 * 717-431-3330
 *
 * Need help? Open a ticket in our support system:
 *  http://support.paradoxlabs.com
 *
 * @author      Ryan Hoerr <support@paradoxlabs.com>
 * @license     http://store.paradoxlabs.com/license.html
 */

namespace ParadoxLabs\Authnetcim\Setup;

use Magento\Customer\Model\Customer;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;

/**
 * Upgrade CIM attributes
 */
class UpgradeData implements \Magento\Framework\Setup\UpgradeDataInterface
{
    /**
     * @var CustomerSetupFactory
     */
    protected $customerSetupFactory;

    /**
     * @var \Magento\Eav\Api\AttributeRepositoryInterface
     */
    protected $attributeRepository;

    /**
     * Init
     *
     * @param CustomerSetupFactory $customerSetupFactory
     * @param \Magento\Eav\Api\AttributeRepositoryInterface $attributeRepository
     */
    public function __construct(
        CustomerSetupFactory $customerSetupFactory,
        \Magento\Eav\Api\AttributeRepositoryInterface $attributeRepository
    ) {
        $this->customerSetupFactory = $customerSetupFactory;
        $this->attributeRepository = $attributeRepository;
    }

    /**
     * Upgrades data for a module
     *
     * @param ModuleDataSetupInterface $setup
     * @param ModuleContextInterface $context
     * @return void
     */
    public function upgrade(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        /** @var \Magento\Customer\Setup\CustomerSetup $customerSetup */
        $customerSetup = $this->customerSetupFactory->create(['setup' => $setup]);

        $setup->startSetup();

        /**
         * authnetcim_profile_id customer attribute: Stores CIM profile ID for each customer.
         */
        if ($customerSetup->getAttributeId('customer', 'authnetcim_profile_id') === false) {
            $customerSetup->addAttribute(
                Customer::ENTITY,
                'authnetcim_profile_id',
                [
                    'label'            => 'Authorize.Net CIM: Profile ID',
                    'type'             => 'varchar',
                    'input'            => 'text',
                    'default'          => '',
                    'position'         => 70,
                    'visible'          => true,
                    'required'         => false,
                    'system'           => false,
                    'user_defined'     => true,
                    'visible_on_front' => false,
                ]
            );

            $profileIdAttr = $customerSetup->getEavConfig()->getAttribute(
                Customer::ENTITY,
                'authnetcim_profile_id'
            );

            $profileIdAttr->addData([
                'attribute_set_id' => $customerSetup->getDefaultAttributeSetId(Customer::ENTITY),
                'attribute_group_id' => $customerSetup->getDefaultAttributeGroupId(Customer::ENTITY),
                'used_in_forms' => [],
            ]);

            $this->attributeRepository->save($profileIdAttr);
        } else {
            /**
             * is_system must be 0 in order for attribute values to save.
             */
            $attribute = $customerSetup->getAttribute(Customer::ENTITY, 'authnetcim_profile_id');
            if ($attribute['is_system'] != 0) {
                $customerSetup->updateAttribute(
                    Customer::ENTITY,
                    $attribute['attribute_id'],
                    'is_system',
                    0
                );

                $profileIdAttr = $customerSetup->getEavConfig()->getAttribute(
                    Customer::ENTITY,
                    'authnetcim_profile_id'
                );

                $profileIdAttr->addData([
                    'attribute_set_id' => $customerSetup->getDefaultAttributeSetId(Customer::ENTITY),
                    'attribute_group_id' => $customerSetup->getDefaultAttributeGroupId(Customer::ENTITY),
                    'used_in_forms' => [],
                ]);

                $this->attributeRepository->save($profileIdAttr);
            }
        }

        /**
         * authnetcim_profile_version customer attribute: Indicates whether each customer needs
         * the card upgrade process run, to migrate data from CIM 1.x to CIM 2+.
         */
        if ($customerSetup->getAttributeId(Customer::ENTITY, 'authnetcim_profile_version') === false) {
            $customerSetup->addAttribute(
                Customer::ENTITY,
                'authnetcim_profile_version',
                [
                    'label'            => 'Authorize.Net CIM: Profile version (for updating legacy data)',
                    'type'             => 'int',
                    'input'            => 'text',
                    'default'          => '100',
                    'position'         => 71,
                    'visible'          => true,
                    'required'         => false,
                    'system'           => false,
                    'user_defined'     => true,
                    'visible_on_front' => false,
                ]
            );

            $profileVersionAttr = $customerSetup->getEavConfig()->getAttribute(
                Customer::ENTITY,
                'authnetcim_profile_version'
            );

            $profileVersionAttr->addData([
                'attribute_set_id' => $customerSetup->getDefaultAttributeSetId(Customer::ENTITY),
                'attribute_group_id' => $customerSetup->getDefaultAttributeGroupId(Customer::ENTITY),
                'used_in_forms' => [],
            ]);

            $this->attributeRepository->save($profileVersionAttr);
        } else {
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

                $profileVersionAttr->addData([
                    'attribute_set_id' => $customerSetup->getDefaultAttributeSetId(Customer::ENTITY),
                    'attribute_group_id' => $customerSetup->getDefaultAttributeGroupId(Customer::ENTITY),
                    'used_in_forms' => [],
                ]);

                $this->attributeRepository->save($profileVersionAttr);
            }
        }

        /**
         * authnetcim_shipping_id is no longer used. Remove it.
         */
        if ($customerSetup->getAttributeId('customer_address', 'authnetcim_shipping_id') !== false) {
            $customerSetup->removeAttribute('customer_address', 'authnetcim_shipping_id');
        }

        $setup->endSetup();
    }
}
