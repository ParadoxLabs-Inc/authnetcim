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

namespace ParadoxLabs\Authnetcim\Block\Info;

/**
 * ACH payment info block for Authorize.Net CIM.
 */
class Ach extends \ParadoxLabs\TokenBase\Block\Info\Ach
{
    /**
     * @var \ParadoxLabs\Authnetcim\Helper\Data
     */
    protected $helper;

    /**
     * Prepare payment info
     *
     * @param \Magento\Framework\DataObject|array $transport
     * @return \Magento\Framework\DataObject
     */
    protected function _prepareSpecificInformation($transport = null)
    {
        $transport  = parent::_prepareSpecificInformation($transport);
        $data       = [];

        if ($this->getIsSecureMode() === false && $this->isEcheck() === true) {
            /** @var \Magento\Sales\Model\Order\Payment $info */
            $info = $this->getInfo();

            $type = $info->getAdditionalInformation('echeck_account_type');

            if (!empty($type)) {
                $data[(string)__('Type')] = $this->helper->getAchAccountTypes($type);
            }
        }

        $transport->setData(array_merge($transport->getData(), $data));

        return $transport;
    }
}
