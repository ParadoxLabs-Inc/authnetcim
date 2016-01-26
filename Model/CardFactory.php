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

namespace ParadoxLabs\Authnetcim\Model;

/**
 * Factory class for @see \ParadoxLabs\Authnetcim\Model\Card
 */
class CardFactory extends \ParadoxLabs\TokenBase\Api\Data\CardInterfaceFactory
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
        parent::__construct($objectManager, $instanceName);
    }
}
