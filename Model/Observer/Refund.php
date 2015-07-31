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

namespace ParadoxLabs\Authnetcim\Model\Observer;

/**
 * Refund Observer
 */
class Refund
{
    /**
     * @var \ParadoxLabs\TokenBase\Helper\Data
     */
    protected $helper;

    /**
     * @param \ParadoxLabs\TokenBase\Helper\Data $helper
     */
    public function __construct(\ParadoxLabs\TokenBase\Helper\Data $helper)
    {
        $this->helper = $helper;
    }

    /**
     * If we're doing a partial refund, don't mark it as fully refunded
     * unless the full amount is done.
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return $this
     */
    public function processRefund(\Magento\Framework\Event\Observer $observer)
    {
        $memo       = $observer->getEvent()->getData('creditmemo');
        $methods    = $this->helper->getAllMethods();

        if (in_array($memo->getOrder()->getPayment()->getMethod(), $methods)) {
            if ($memo->getInvoice()
                && $memo->getInvoice()->getBaseTotalRefunded() < $memo->getInvoice()->getBaseGrandTotal()) {
                $memo->getInvoice()->setIsUsedForRefund(false);
            }
        }

        return $this;
    }
}
