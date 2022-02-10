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
