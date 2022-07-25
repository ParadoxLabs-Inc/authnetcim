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

namespace ParadoxLabs\Authnetcim\Block\Adminhtml\Customer\Form;

class Ach extends \ParadoxLabs\TokenBase\Block\Adminhtml\Customer\Form
{
    /**
     * @var string
     */
    protected $_template = 'ParadoxLabs_Authnetcim::customer/form/ach.phtml';

    /**
     * Swap form template for Accept Hosted vs inline
     *
     * @return string
     */
    protected function _toHtml()
    {
        $method = $this->getMethod();
        if ($method->getConfigData('form_type') === 'hosted') {
            $this->_template = 'ParadoxLabs_Authnetcim::customer/form/hosted.phtml';
        }

        return parent::_toHtml();
    }
}
