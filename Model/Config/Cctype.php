<?php
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

namespace ParadoxLabs\Authnetcim\Model\Config;

/**
 * Cctype Class
 */
class Cctype extends \Magento\Payment\Model\Source\Cctype
{
    /**
     * Allowed CC types
     *
     * @var array
     */
    protected $_allowedTypes = ['VI', 'MC', 'AE', 'DI', 'JCB', 'DN'];
}
