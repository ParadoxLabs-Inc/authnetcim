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

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchVersionInterface;

class DefaultFormType implements DataPatchInterface, PatchVersionInterface
{
    /**
     * @var \Magento\Config\Model\ResourceModel\Config
     */
    private $configResource;

    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param \Magento\Config\Model\ResourceModel\Config $configResource
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        \Magento\Config\Model\ResourceModel\Config $configResource
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->configResource = $configResource;
    }

    /**
     * Run patch
     *
     * Set default config for upgrades different from new installs
     *
     * @return $this
     */
    public function apply()
    {
        $this->moduleDataSetup->startSetup();

        $this->configResource->saveConfig(
            'payment/authnetcim_ach/form_type',
            '0'
        );

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
     * This version associate patch with Magento setup version.
     * For example, if Magento current setup version is 2.0.3 and patch version is 2.0.2 then
     * this patch will be added to registry, but will not be applied, because it is already applied
     * by old mechanism of UpgradeData.php script
     *
     * @return string
     */
    public static function getVersion()
    {
        return '4.6.0';
    }
}
