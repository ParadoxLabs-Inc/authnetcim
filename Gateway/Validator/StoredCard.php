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

namespace ParadoxLabs\Authnetcim\Gateway\Validator;

class StoredCard extends \ParadoxLabs\TokenBase\Gateway\Validator\StoredCard
{
    /**
     * @var \ParadoxLabs\TokenBase\Gateway\Validator\CreditCard
     */
    private $ccValidator;

    /**
     * @var \Magento\Payment\Gateway\ConfigInterface
     */
    private $config;

    /**
     * Constructor
     *
     * @param \Magento\Payment\Gateway\Validator\ResultInterfaceFactory $resultFactory
     * @param \ParadoxLabs\TokenBase\Gateway\Validator\CreditCard $ccValidator
     * @param \Magento\Payment\Gateway\ConfigInterface $config
     */
    public function __construct(
        \Magento\Payment\Gateway\Validator\ResultInterfaceFactory $resultFactory,
        \ParadoxLabs\TokenBase\Gateway\Validator\CreditCard $ccValidator,
        \Magento\Payment\Gateway\ConfigInterface $config
    ) {
        parent::__construct($resultFactory, $ccValidator, $config);

        $this->ccValidator = $ccValidator;
        $this->config = $config;
    }

    /**
     * Validate a stored card on the payment object
     *
     * @param array $validationSubject
     * @return \Magento\Payment\Gateway\Validator\ResultInterface
     */
    public function validate(array $validationSubject)
    {
        // If Accept.js is enabled, kick to standard CC validator
        if ((int)$this->config->getValue('acceptjs') === 1) {
            return parent::validate($validationSubject);
        }

        // Otherwise, we need to validate no payment data at all

        $isValid = true;
        $fails   = [];

        /** @var \Magento\Payment\Model\Info $payment */
        $payment = $validationSubject['payment'];

        /**
         * If we have a tokenbase ID, we're using a stored card.
         */
        $tokenbaseId = $payment->getData('tokenbase_id');
        if (!empty($tokenbaseId)) {
            /**
             * If Require CCV is enabled, enforce it.
             */
            if ($this->config->getValue('require_ccv') == 1
                && $payment->getAdditionalInformation('is_subscription_generated') != 1
                && $payment->getData('tokenbase_source') !== 'paymentinfo') {
                $ccvLength = null;
                $ccvLabel  = 'CVV';

                $ccType = $payment->getData('cc_type');
                if (!empty($ccType)) {
                    $typeInfo = $this->ccValidator->getCcTypes()->getType($ccType);
                    if ($typeInfo !== false) {
                        $ccvLength = $typeInfo['code']['size'];
                        $ccvLabel  = $typeInfo['code']['name'];
                    }
                }

                if (!is_numeric($payment->getData('cc_cid'))
                    || ($ccvLength !== null && strlen((string)$payment->getData('cc_cid')) != $ccvLength)
                    || strlen((string)$payment->getData('cc_cid')) < 3) {
                    $isValid = false;
                    $fails[] = __('Please enter your credit card %1.', $ccvLabel);
                }
            }
        }

        return $this->createResult($isValid, $fails);
    }
}
