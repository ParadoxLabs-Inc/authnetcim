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

namespace ParadoxLabs\Authnetcim\Model;

/**
 * Factory class for @see \ParadoxLabs\Authnetcim\Model\Card
 */
class CardFactory extends \ParadoxLabs\TokenBase\Model\CardFactory
{
    /**
     * Factory constructor
     *
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     * @param string $instanceName
     */
    public function __construct(
        \Magento\Framework\ObjectManagerInterface $objectManager,
        $instanceName = '\\ParadoxLabs\\Authnetcim\\Model\\Card'
    ) {
        $this->_objectManager = $objectManager;
        $this->_instanceName = $instanceName;

        parent::__construct($objectManager, $instanceName);
    }
}
