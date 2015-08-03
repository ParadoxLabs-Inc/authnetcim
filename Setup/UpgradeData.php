<?php
/**
 * Paradox Labs, Inc.
 * http://www.paradoxlabs.com
 * 717-431-3330
 *
 * Need help? Open a ticket in our support system:
 *  http://support.paradoxlabs.com
 *
 * @author      Ryan Hoerr <magento@paradoxlabs.com>
 * @license     http://store.paradoxlabs.com/license.html
 */

namespace ParadoxLabs\Authnetcim\Setup;

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
    private $customerSetupFactory;

    /**
     * Init
     *
     * @param CustomerSetupFactory $customerSetupFactory
     */
    public function __construct(CustomerSetupFactory $customerSetupFactory)
    {
        $this->customerSetupFactory = $customerSetupFactory;
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
        }

        /**
         * authnetcim_profile_version customer attribute: Indicates whether each customer needs
         * the card upgrade process run, to migrate data from CIM 1.x to CIM 2+.
         */
        if ($customerSetup->getAttributeId('customer', 'authnetcim_profile_version') === false) {
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
