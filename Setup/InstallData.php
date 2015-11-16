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
    private $customerSetupFactory;

    /**
     * Init
     *
     * @param \Magento\Customer\Setup\CustomerSetupFactory $customerSetupFactory
     */
    public function __construct(\Magento\Customer\Setup\CustomerSetupFactory $customerSetupFactory)
    {
        $this->customerSetupFactory = $customerSetupFactory;
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

        $setup->startSetup();

        /**
         * authnetcim_profile_id customer attribute: Stores CIM profile ID for each customer.
         */
        $customerSetup->addAttribute(
            'customer',
            'authnetcim_profile_id',
            [
                'label'            => 'Authorize.Net CIM: Profile ID',
                'type'             => 'varchar',
                'input'            => 'text',
                'default'          => '',
                'position'         => 70,
                'visible'          => true,
                'required'         => false,
                'user_defined'     => true,
                'visible_on_front' => false,
            ]
        );

        /**
         * authnetcim_profile_version customer attribute: Indicates whether each customer needs
         * the card upgrade process run, to migrate data from CIM 1.x to CIM 2+.
         */
        $customerSetup->addAttribute(
            'customer',
            'authnetcim_profile_version',
            [
                'label'            => 'Authorize.Net CIM: Profile version (for updating legacy data)',
                'type'             => 'int',
                'input'            => 'text',
                'default'          => '100',
                'position'         => 71,
                'visible'          => true,
                'required'         => false,
                'user_defined'     => true,
                'visible_on_front' => false,
            ]
        );

        $setup->endSetup();
    }
}
