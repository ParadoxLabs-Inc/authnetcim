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

namespace ParadoxLabs\Authnetcim\Block\Customer\Form;

/**
 * Cc Class
 */
class Cc extends \ParadoxLabs\TokenBase\Block\Customer\Form
{
    /**
     * @var string
     */
    protected $_template = 'ParadoxLabs_Authnetcim::customer/form/cc.phtml';

    /**
     * Swap form template for Accept Hosted vs Accept.js
     *
     * @return string
     */
    protected function _toHtml()
    {
        $method = $this->getMethod();
        if ((bool)$method->getConfigData('acceptjs') === false) {
            $this->_template = 'ParadoxLabs_Authnetcim::customer/form/hosted.phtml';
        }

        return parent::_toHtml();
    }
}
