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
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;

/**
 * Install CIM attributes
 */
class InstallData implements \Magento\Framework\Setup\InstallDataInterface
{
    /**
     * @var \Magento\Customer\Setup\CustomerSetupFactory
     */
    protected $customerSetupFactory;

    /**
     * @var \Magento\Eav\Api\AttributeRepositoryInterface
     */
    protected $attributeRepository;

    /**
     * @var UpgradeData
     */
    protected $dataUpgrade;

    /**
     * Init
     *
     * @param \Magento\Customer\Setup\CustomerSetupFactory $customerSetupFactory
     * @param \Magento\Eav\Api\AttributeRepositoryInterface $attributeRepository
     * @param UpgradeData $dataUpgrade
     */
    public function __construct(
        \Magento\Customer\Setup\CustomerSetupFactory $customerSetupFactory,
        \Magento\Eav\Api\AttributeRepositoryInterface $attributeRepository,
        \ParadoxLabs\Authnetcim\Setup\UpgradeData $dataUpgrade
    ) {
        $this->customerSetupFactory = $customerSetupFactory;
        $this->attributeRepository = $attributeRepository;
        $this->dataUpgrade = $dataUpgrade;
    }

    /**
     * Installs data for a module
     *
     * @param ModuleDataSetupInterface $setup
     * @param ModuleContextInterface $context
     * @return void
     */
    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        /** @var \Magento\Customer\Setup\CustomerSetup $customerSetup */
        $customerSetup = $this->customerSetupFactory->create(['setup' => $setup]);

        $this->addCustomerProfileIdAttr($customerSetup);
        $this->addCustomerProfileVersionAttr($customerSetup);
        $this->dataUpgrade->addConfigMinifyExcludeAcceptjs($setup, $context);
    }

    /**
     * authnetcim_profile_id customer attribute: Stores CIM profile ID for each customer.
     *
     * @param \Magento\Customer\Setup\CustomerSetup $customerSetup
     * @return void
     */
    public function addCustomerProfileIdAttr(\Magento\Customer\Setup\CustomerSetup $customerSetup)
    {
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
    }

    /**
     * authnetcim_profile_version customer attribute: Indicates whether each customer needs
     * the card upgrade process run, to migrate data from CIM 1.x to CIM 2+.
     *
     * @param \Magento\Customer\Setup\CustomerSetup $customerSetup
     * @return void
     */
    public function addCustomerProfileVersionAttr(\Magento\Customer\Setup\CustomerSetup $customerSetup)
    {
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
    }
}
