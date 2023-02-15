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

use ParadoxLabs\Authnetcim\Model\ConfigProvider;

/**
 * Credit card input form on checkout
 */
class Cc extends \ParadoxLabs\TokenBase\Block\Form\Cc
{
    /**
     * @var string
     */
    protected $_template = 'ParadoxLabs_Authnetcim::form/cc.phtml';

    /**
     * @var string
     */
    protected $brandingImage = 'ParadoxLabs_Authnetcim::images/logo.png';

    /**
     * Swap form template for Accept Hosted vs Accept.js
     *
     * @return string
     */
    protected function _toHtml()
    {
        $method = $this->getTokenbaseMethod();
        if ($method->getConfigData('form_type') === ConfigProvider::FORM_HOSTED) {
            $this->_template = 'ParadoxLabs_Authnetcim::checkout/hosted/form.phtml';
        }

        return parent::_toHtml();
    }
}
