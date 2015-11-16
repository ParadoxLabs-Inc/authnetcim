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
}
