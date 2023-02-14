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

namespace ParadoxLabs\Authnetcim\Gateway\Validator;

use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Sales\Model\Order\Payment as OrderPayment;

/**
 * CreditCard Class
 */
class CreditCard extends \ParadoxLabs\TokenBase\Gateway\Validator\CreditCard
{
    /**
     * @var \ParadoxLabs\TokenBase\Model\Method\Factory
     */
    protected $methodFactory;

    /**
     * @param \Magento\Payment\Gateway\Validator\ResultInterfaceFactory $resultFactory
     * @param \Magento\Payment\Gateway\ConfigInterface $config
     * @param \ParadoxLabs\TokenBase\Gateway\Validator\CreditCard\Types $ccTypes
     * @param \Magento\Framework\Stdlib\DateTime\TimezoneInterface $dateProcessor
     * @param \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
     */
    public function __construct(
        \Magento\Payment\Gateway\Validator\ResultInterfaceFactory $resultFactory,
        \Magento\Payment\Gateway\ConfigInterface $config,
        \ParadoxLabs\TokenBase\Gateway\Validator\CreditCard\Types $ccTypes,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $dateProcessor,
        \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
    ) {
        parent::__construct($resultFactory, $config, $ccTypes, $dateProcessor);

        $this->methodFactory = $methodFactory;
    }

    /**
     * Performs domain-related validation for business object
     *
     * @param array $validationSubject
     * @return \Magento\Payment\Gateway\Validator\ResultInterface
     */
    public function validate(array $validationSubject)
    {
        $isValid = true;
        $fails   = [];

        /** @var \Magento\Payment\Model\InfoInterface $payment */
        $payment = $validationSubject['payment'];
        $storeId = (int)$validationSubject['storeId'];

        try {
            $this->validateAcceptJs($payment);
        } catch (\Exception $exception) {
            $isValid = false;
            $fails[] = $exception->getMessage();
        }

        try {
            $this->validateHostedTransaction($payment, $storeId);
        } catch (\Exception $exception) {
            $isValid = false;
            $fails[] = $exception->getMessage();
        }

        /**
         * Comply with the configuration settings for allowed card types.
         */
        try {
            $this->validateCcType($payment);
        } catch (\Exception $exception) {
            $isValid = false;
            $fails[] = $exception->getMessage();
        }

        /**
         * If we do not have Accept.js info, apply normal CC validation.
         */
        $acceptJsValue = $payment->getAdditionalInformation('acceptjs_value');

        if (empty($fails) && empty($acceptJsValue)) {
            return parent::validate($validationSubject);
        }

        return $this->createResult($isValid, $fails);
    }

    /**
     * Determine whether Accept.js is configured.
     */
    public function isAcceptJsEnabled(): bool
    {
        $clientKey = $this->config->getValue('client_key');

        if ($this->config->getValue('acceptjs') == 1 && !empty($clientKey)) {
            return true;
        }

        return false;
    }

    /**
     * If Accept.js is enabled, make sure we didn't receive raw CC info.
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function validateAcceptJs(\Magento\Payment\Model\InfoInterface $payment): void
    {
        if ($this->isAcceptJsEnabled() !== true) {
            return;
        }

        if (strlen(str_replace(['X', '-'], '', (string)$payment->getData('cc_number'))) > 4) {
            // This gets triggered if Accept.js is enabled but we received raw credit card data anyway.
            // We don't ever want that, so refuse to process it. Whatever happened must be fixed.
            throw new \Magento\Framework\Exception\LocalizedException(__(
                'We did not receive the expected Accept.js data. Please verify payment details and try again.'
                . ' If you get this error twice, contact support.'
            ));
        }
    }

    /**
     * If Hosted form is enabled, fetch and validate the transaction info.
     *
     * @param \Magento\Payment\Model\InfoInterface|OrderPayment|QuotePayment $payment
     * @param int $storeId
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function validateHostedTransaction(\Magento\Payment\Model\InfoInterface $payment, int $storeId): void
    {
        // TODO: Add differentiation between AcceptJS disabled and Hosted enabled
        //  (does that mean not removing raw CC processing yet?)
        if ($this->isAcceptJsEnabled() === true
            || $payment instanceof OrderPayment === false
            || empty($payment->getAdditionalInformation('transaction_id'))) {
            return;
        }

        $transactionDetails = $payment->getAdditionalInformation();

        if (!in_array((int)$transactionDetails['response_code'], [1, 4], true)) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Transaction was declined.'));
        }

        $order = $payment->getOrder();

        if ($transactionDetails['customer_email'] !== $order->getCustomerEmail()
            || $transactionDetails['invoice_number'] !== $order->getIncrementId()
            || $transactionDetails['amount'] < $order->getBaseGrandTotal()) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Transaction failed, please try again.'));
        }

        $submitTime = strtotime($transactionDetails['submit_time_utc']);
        $window     = 15 * 60; // Disallow transaction completion after 15 minutes
        if ($submitTime < (time() - $window)) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Transaction expired, please try again.'));
        }
    }

    /**
     * Make sure we received a valid CC type.
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @return void
     */
    protected function validateCcType(\Magento\Payment\Model\InfoInterface $payment): void
    {
        $typeInfo       = $payment->getData('cc_type');
        $availableTypes = explode(',', $this->config->getValue('cctypes'));
        if (isset($typeInfo) && in_array($typeInfo, $availableTypes, true) === false) {
            // Is the type allowed?
            throw new \Magento\Framework\Exception\LocalizedException(__(
                'This credit card type is not allowed for this payment method.'
            ));
        }
    }
}
