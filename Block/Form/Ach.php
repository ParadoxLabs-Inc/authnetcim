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

namespace ParadoxLabs\Authnetcim\Block\Form;

/**
 * ACH input form on checkout
 */
class Ach extends \ParadoxLabs\TokenBase\Block\Form\Ach
{
    /**
     * @var string
     */
    protected $brandingImage = 'ParadoxLabs_Authnetcim::images/logo.png';

    /**
     * Swap form template for Accept Hosted vs inline
     *
     * @return string
     */
    protected function _toHtml()
    {
        $method = $this->getTokenbaseMethod();
        if ($method->getConfigData('form_type') === 'hosted') {
            $this->_template = 'ParadoxLabs_Authnetcim::checkout/hosted/form.phtml';
        }

        return parent::_toHtml();
    }

    /**
     * Retrieve has verification configuration
     *
     * @return bool
     */
    public function hasVerification()
    {
        return false;
    }
}
