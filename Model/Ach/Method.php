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

namespace ParadoxLabs\Authnetcim\Model\Ach;

/**
 * Authorize.Net CIM ACH payment method
 */
class Method extends \ParadoxLabs\Authnetcim\Model\Method
{
    /**
     * @var string
     */
    protected $_code = 'authnetcim_ach';

    /**
     * @var string
     */
    protected $_formBlockType = \ParadoxLabs\Authnetcim\Block\Form\Ach::class;

    /**
     * @var string
     */
    protected $_infoBlockType = \ParadoxLabs\Authnetcim\Block\Info\Ach::class;

    /**
     * @var array
     */
    protected $achFields = [
        'echeck_account_name',
        'echeck_bank_name',
        'echeck_routing_no',
        'echeck_account_no',
        'echeck_account_type',
    ];

    /**
     * Set the current payment card
     *
     * @param \ParadoxLabs\TokenBase\Api\Data\CardInterface $card
     * @return $this
     */
    public function setCard(\ParadoxLabs\TokenBase\Api\Data\CardInterface $card)
    {
        parent::setCard($card);

        /** @var \Magento\Sales\Model\Order\Payment\Info $info */
        $info = $this->getInfoInstance();

        foreach ($this->achFields as $field) {
            if ($card->getAdditional($field)) {
                $info->setData($field, $card->getAdditional($field));
            }
        }

        $info->setData('echeck_routing_number', $card->getAdditional('echeck_routing_number_last4'));
        $info->setAdditionalInformation(
            'echeck_account_number_last4',
            $card->getAdditional('echeck_account_number_last4')
        );

        return $this;
    }

    /**
     * Return boolean whether given payment object includes new card info.
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @return bool
     */
    protected function paymentContainsCard(\Magento\Payment\Model\InfoInterface $payment)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        if (strlen((string)$payment->getData('echeck_routing_no')) == 9
            && strlen((string)$payment->getData('echeck_account_no')) >= 5) {
            return true;
        }

        return false;
    }

    /**
     * Save type for legacy cards.
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param \ParadoxLabs\TokenBase\Model\Gateway\Response $response
     * @return \Magento\Payment\Model\InfoInterface
     */
    protected function fixLegacyCcType(
        \Magento\Payment\Model\InfoInterface $payment,
        \ParadoxLabs\TokenBase\Model\Gateway\Response $response
    ) {
        /**
         * Legacy CIM method, not needed for ACH.
         */

        return $payment;
    }
}
