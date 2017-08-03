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
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * Init
     *
     * @param CustomerSetupFactory $customerSetupFactory
     * @param \Magento\Eav\Api\AttributeRepositoryInterface $attributeRepository
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        CustomerSetupFactory $customerSetupFactory,
        \Magento\Eav\Api\AttributeRepositoryInterface $attributeRepository,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        $this->customerSetupFactory = $customerSetupFactory;
        $this->attributeRepository = $attributeRepository;
        $this->scopeConfig = $scopeConfig;
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

        $this->fixCustomerProfileIdAttr($customerSetup);
        $this->fixCustomerProfileVersionAttr($customerSetup);
        $this->removeCustomerShippingIdAttr($customerSetup);
        $this->addConfigMinifyExcludeAcceptjs($setup, $context);
    }

    /**
     * authnetcim_profile_id customer attribute: Stores CIM profile ID for each customer.
     *
     * @param \Magento\Customer\Setup\CustomerSetup $customerSetup
     * @return void
     */
    public function fixCustomerProfileIdAttr(\Magento\Customer\Setup\CustomerSetup $customerSetup)
    {
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
                    'visible'          => false,
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
        }
    }

    /**
     * authnetcim_profile_version customer attribute: Indicates whether each customer needs
     * the card upgrade process run, to migrate data from CIM 1.x to CIM 2+.
     *
     * @param \Magento\Customer\Setup\CustomerSetup $customerSetup
     * @return void
     */
    public function fixCustomerProfileVersionAttr(\Magento\Customer\Setup\CustomerSetup $customerSetup)
    {
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
                    'visible'          => false,
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
        }
    }

    /**
     * Add Accept.js to minify exclude list if it isn't there.
     *
     * @param ModuleDataSetupInterface $setup
     * @param ModuleContextInterface $context
     * @return void
     */
    public function addConfigMinifyExcludeAcceptjs(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $db = $setup->getConnection();

        // Is there an existing config value for js minify_exclude?
        $sql = $db->select()
            ->from($setup->getTable('core_config_data'))
            ->where('scope_id=?', '0')
            ->where('path=?', 'dev/js/minify_exclude')
            ->limit(1);

        $config = $db->fetchRow($sql);
        if (!empty($config) && isset($config['value'])) {
            // Config exists. Is Accept in it?
            if (strpos($config['value'], 'Accept') === false) {
                // ... Nope, let's add it.
                $config['value'] .= "\nAccept";
                $db->update(
                    $setup->getTable('core_config_data'),
                    [
                        'value' => $config['value'],
                    ],
                    [
                        'config_id=?' => $config['config_id'],
                    ]
                );
            }
        } else {
            // Config does not exist. We'll have to add it.
            $db->insert(
                $setup->getTable('core_config_data'),
                [
                    'scope' => 'default',
                    'scope_id' => 0,
                    'path' => 'dev/js/minify_exclude',
                    'value' => trim($this->scopeConfig->getValue('dev/js/minify_exclude')) . "\nAccept",
                ]
            );
        }
    }

    /**
     * authnetcim_shipping_id is no longer used. Remove it if exists.
     *
     * @param \Magento\Customer\Setup\CustomerSetup $customerSetup
     * @return void
     */
    public function removeCustomerShippingIdAttr(\Magento\Customer\Setup\CustomerSetup $customerSetup)
    {
        if ($customerSetup->getAttributeId('customer_address', 'authnetcim_shipping_id') !== false) {
            $customerSetup->removeAttribute('customer_address', 'authnetcim_shipping_id');
        }
    }
}
