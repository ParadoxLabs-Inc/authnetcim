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
    protected $_formBlockType = 'ParadoxLabs\Authnetcim\Block\Form\Ach';

    /**
     * @var string
     */
    protected $_infoBlockType = 'ParadoxLabs\Authnetcim\Block\Info\Ach';

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
     * Check whether this payment method is active and usable
     *
     * @param \Magento\Quote\Api\Data\CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        /**
         * Bypass the CC type check. sneaksy
         */
        return \Magento\Payment\Model\Method\AbstractMethod::isAvailable($quote);
    }

    /**
     * Update info during the checkout process.
     *
     * @param \Magento\Framework\DataObject|mixed $data
     * @return $this
     */
    public function assignData(\Magento\Framework\DataObject $data)
    {
        if (!($data instanceof \Magento\Framework\DataObject)) {
            $data = new \Magento\Framework\DataObject($data);
        }

        parent::assignData($data);

        /** @var \Magento\Sales\Model\Order\Payment\Info $info */
        $info = $this->getInfoInstance();

        foreach ($this->achFields as $field) {
            if ($data->hasData($field) && $data->getData($field) != '') {
                $info->setData($field, $data->getData($field));

                if ($field != 'echeck_routing_no' && $field != 'echeck_account_no') {
                    $info->setAdditionalInformation($field, $data->getData($field));
                }
            }
        }

        if ($data->getData('echeck_routing_no') != '') {
            $info->setData('echeck_routing_number', substr($data->getData('echeck_routing_no'), -4));
        }

        if ($data->getData('echeck_account_no') != '') {
            $last4 = substr($data->getData('echeck_account_no'), -4);

            $info->setData('cc_last_4', $last4);
            $info->setAdditionalInformation('echeck_account_number_last4', $last4);
        }

        return $this;
    }

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
     * Validate the transaction inputs.
     *
     * @return $this
     * @throws \Magento\Framework\Exception\PaymentException
     */
    public function validate()
    {
        /** @var \Magento\Sales\Model\Order\Payment\Info $info */
        $info = $this->getInfoInstance();

        $this->log(sprintf('validate(%s)', $info->getData('tokenbase_id')));

        /**
         * If no tokenbase ID, we must have a new card. Make sure all the details look valid.
         */
        if ($info->hasData('tokenbase_id') === false) {
            // Fields all present?
            foreach ($this->achFields as $field) {
                $value = trim($info->getData($field));

                if (empty($value)) {
                    throw new \Magento\Framework\Exception\PaymentException(
                        __('Please complete all required fields.')
                    );
                }
            }

            // Field lengths?
            if (strlen($info->getData('echeck_account_name')) > 22) {
                throw new \Magento\Framework\Exception\PaymentException(
                    __('Please limit your account name to 22 characters.')
                );
            } elseif (strlen($info->getData('echeck_routing_no')) != 9) {
                throw new \Magento\Framework\Exception\PaymentException(
                    __('Your routing number must be 9 digits long. Please recheck the value you entered.')
                );
            } elseif (strlen($info->getData('echeck_account_no')) < 5
                || strlen($info->getData('echeck_account_no')) > 17) {
                throw new \Magento\Framework\Exception\PaymentException(
                    __('Your account number must be between 5 and 17 digits. Please recheck the value you entered.')
                );
            }

            // Data types?
            if (!is_numeric($info->getData('echeck_routing_no'))) {
                throw new \Magento\Framework\Exception\PaymentException(
                    __('Your routing number must be 9 digits long. Please recheck the value you entered.')
                );
            } elseif (!is_numeric($info->getData('echeck_account_no'))) {
                throw new \Magento\Framework\Exception\PaymentException(
                    __('Your account number must be between 5 and 17 digits. Please recheck the value you entered.')
                );
            }

            return \Magento\Payment\Model\Method\AbstractMethod::validate();
        } else {
            /**
             * If there is an ID, this might be an edit. Validate there too, as much as we can.
             */
            if ($info->getData('echeck_account_name') != '' && strlen($info->getData('echeck_account_name')) > 22) {
                throw new \Magento\Framework\Exception\PaymentException(
                    __('Please limit your account name to 22 characters.')
                );
            }

            if ($info->getData('echeck_routing_no') != ''
                && substr($info->getData('echeck_routing_no'), 0, 4) != 'XXXX') {
                // If not masked and not 9 digits, or not numeric...
                if (strlen($info->getData('echeck_routing_no')) != 9
                    || !is_numeric($info->getData('echeck_routing_no'))) {
                    throw new \Magento\Framework\Exception\PaymentException(
                        __('Your routing number must be 9 digits long. Please recheck the value you entered.')
                    );
                }
            }

            if ($info->getData('echeck_account_no') != ''
                && substr($info->getData('echeck_account_no'), 0, 4) != 'XXXX') {
                // If not masked and not 5-17 digits, or not numeric...
                if (strlen($info->getData('echeck_account_no')) < 5
                    || strlen($info->getData('echeck_account_no')) > 17
                    || !is_numeric($info->getData('echeck_account_no'))) {
                    throw new \Magento\Framework\Exception\PaymentException(
                        __('Your account number must be between 5 and 17 digits. Please recheck the value you entered.')
                    );
                }
            }
        }

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
        if (strlen($payment->getData('echeck_routing_no')) == 9
            && strlen($payment->getData('echeck_account_no')) >= 5) {
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
