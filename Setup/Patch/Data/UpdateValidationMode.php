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
 * @link https://support.paradoxlabs.com
 */

namespace ParadoxLabs\Authnetcim\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class UpdateValidationMode implements DataPatchInterface
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

        // Change any validation modes of 'none' to 'testMode'. 'none' was removed in 5.1.0.
        $this->configResource->getConnection()->update(
            $this->configResource->getTable('core_config_data'),
            [
                'value' => 'testMode',
            ],
            [
                'path in ("payment/authnetcim/validation_mode", "payment/authnetcim_ach/validation_mode")',
                'value="none"'
            ]
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
}
