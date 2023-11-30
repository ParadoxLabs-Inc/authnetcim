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

namespace ParadoxLabs\Authnetcim\Model\Magewire\Payment;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Quote\Api\CartManagementInterface;

class PlaceOrderService extends \Hyva\Checkout\Model\Magewire\Payment\AbstractPlaceOrderService
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;

    /**
     * PlaceOrderService constructor.
     *
     * @param \Magento\Checkout\Model\Session $checkoutSession
     */
    public function __construct(
        CartManagementInterface $cartManagement,
        \Magento\Checkout\Model\Session $checkoutSession
    ) {
        parent::__construct($cartManagement);

        $this->checkoutSession = $checkoutSession;
    }

    /**
     * @throws CouldNotSaveException
     */
    public function placeOrder(\Magento\Quote\Model\Quote $quote): int
    {
        // Load CVV in from session if present
        $ccCid = $this->checkoutSession->getStepData('payment', 'cc_cid');
        if (!empty($ccCid) && is_numeric((string)$ccCid)) {
            $quote->getPayment()->setData('cc_cid', $ccCid);
        }

        return parent::placeOrder($quote);
    }
}
