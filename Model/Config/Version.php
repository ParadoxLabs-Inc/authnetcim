<?php
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

namespace ParadoxLabs\Authnetcim\Model\Config;

/**
 * Config backend model for version display.
 */
class Version extends \Magento\Framework\App\Config\Value implements
    \Magento\Framework\App\Config\Data\ProcessorInterface
{
    /**
     * @var \Magento\Framework\Module\Dir
     */
    protected $moduleDir;

    /**
     * @var \Magento\Framework\Filesystem\Io\File
     */
    protected $fileHandler;

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $config
     * @param \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList
     * @param \Magento\Framework\Module\Dir $moduleDir
     * @param \Magento\Framework\Filesystem\Io\File $fileHandler
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        \Magento\Framework\Module\Dir $moduleDir,
        \Magento\Framework\Filesystem\Io\File $fileHandler,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
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

        $this->moduleDir = $moduleDir;
        $this->fileHandler = $fileHandler;
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

            $composer = json_decode((string)$composerFile, 1);

            if (isset($composer['version'], $composer['time'])) {
                return $composer['version'] . ' (' . $composer['time'] . ')';
            } elseif (isset($composer['version'])) {
                return $composer['version'];
            } else {
                return __('Unknown (could not read composer.json)');
            }
        } catch (\Exception $e) {
            return __('Unknown (could not read composer.json)');
        }
    }

    /**
     * Inject current installed module version as the config value.
     *
     * @return $this
     */
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
