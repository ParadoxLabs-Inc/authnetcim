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

namespace ParadoxLabs\Authnetcim\Model\Config;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Data\ProcessorInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Filesystem\Io\File;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Module\Dir;
use Magento\Framework\Registry;
use Override;
use Throwable;

/**
 * Config backend model for version display.
 */
class Version extends Value implements
    ProcessorInterface
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param Dir $moduleDir
     * @param File $fileHandler
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        protected readonly Dir $moduleDir,
        protected readonly File $fileHandler,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $config,
            $cacheTypeList,
            $resource,
            $resourceCollection,
            $data
        );
    }

    /**
     * Get module version
     *
     * @return string
     */
    public function _getDefaultValue()
    {
        try {
            $composerFile = $this->fileHandler->read(
                $this->moduleDir->getDir('ParadoxLabs_Authnetcim') . '/composer.json'
            );

            $composer = json_decode((string)$composerFile, true);

            if (isset($composer['version'], $composer['time'])) {
                return $composer['version'] . ' (' . $composer['time'] . ')';
            } elseif (isset($composer['version'])) {
                return $composer['version'];
            } else {
                return __('Unknown (could not read composer.json)');
            }
        } catch (Throwable) {
            return __('Unknown (could not read composer.json)');
        }
    }

    /**
     * Inject current installed module version as the config value.
     *
     * @return $this
     */
    #[Override]
    protected function _afterLoad()
    {
        $this->setValue($this->_getDefaultValue());

        parent::_afterLoad();

        return $this;
    }

    /**
     * Process config value
     *
     * @param string $value
     * @return string
     */
    public function processValue($value)
    {
        return $this->_getDefaultValue();
    }
}
