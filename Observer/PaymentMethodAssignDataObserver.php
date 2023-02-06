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

namespace ParadoxLabs\Authnetcim\Observer;

/**
 * PaymentMethodAssignDataObserver Class
 */
class PaymentMethodAssignDataObserver extends \ParadoxLabs\TokenBase\Observer\PaymentMethodAssignDataObserver
{
    /**
     * @var \ParadoxLabs\TokenBase\Model\Method\Factory
     */
    private $methodFactory;

    /**
     * @var \ParadoxLabs\Authnetcim\Model\Service\CustomerProfile
     */
    private $customerProfileService;

    /**
     * @var \ParadoxLabs\TokenBase\Api\Data\CardInterfaceFactory
     */
    private $cardFactory;

    /**
     * PaymentMethodAssignAcceptjsDataObserver constructor.
     *
     * @param \ParadoxLabs\TokenBase\Helper\Data $helper
     * @param \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository
     * @param \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
     * @param \ParadoxLabs\Authnetcim\Model\Service\CustomerProfile $customerProfileService
     * @param \ParadoxLabs\TokenBase\Api\Data\CardInterfaceFactory $cardFactory
     */
    public function __construct(
        \ParadoxLabs\TokenBase\Helper\Data $helper,
        \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository,
        \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory,
        \ParadoxLabs\Authnetcim\Model\Service\CustomerProfile $customerProfileService,
        \ParadoxLabs\TokenBase\Api\Data\CardInterfaceFactory $cardFactory
    ) {
        parent::__construct($helper, $cardRepository);

        $this->methodFactory = $methodFactory;
        $this->customerProfileService = $customerProfileService;
        $this->cardFactory = $cardFactory;
    }

    /**
     * Assign data to the payment instance for our methods.
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param \Magento\Framework\DataObject $data
     * @param \Magento\Payment\Model\MethodInterface $method
     * @return void
     */
    protected function assignTokenbaseData(
        \Magento\Payment\Model\InfoInterface $payment,
        \Magento\Framework\DataObject $data,
        \Magento\Payment\Model\MethodInterface $method
    ) {
        /** @var \Magento\Sales\Model\Order\Payment $payment */

        $tokenbaseMethod = $this->methodFactory->getMethodInstance($method->getCode());
        $tokenbaseMethod->setStore((int)$method->getStore());

        $this->processAcceptJs($payment, $data, $tokenbaseMethod);
        $this->processAcceptHosted($payment, $data, $tokenbaseMethod);

        parent::assignTokenbaseData($payment, $data, $method);
    }

    /**
     * Store Accept.js info if given and enabled.
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param \Magento\Framework\DataObject $data
     * @param \ParadoxLabs\TokenBase\Api\MethodInterface $tokenbaseMethod
     * @return void
     */
    public function processAcceptJs(
        \Magento\Payment\Model\InfoInterface $payment,
        \Magento\Framework\DataObject $data,
        \ParadoxLabs\TokenBase\Api\MethodInterface $tokenbaseMethod
    ): void {
        if ($tokenbaseMethod->isAcceptJsEnabled() === true
            && $data->getData('acceptjs_key') != ''
            && $data->getData('acceptjs_value') != '') {
            $payment->setAdditionalInformation('acceptjs_key', $data->getData('acceptjs_key'))
                    ->setAdditionalInformation('acceptjs_value', $data->getData('acceptjs_value'))
                    ->setCcLast4($data->getData('cc_last4'));

            if ($tokenbaseMethod->getConfigData('can_store_bin') == 1) {
                $payment->setAdditionalInformation('cc_bin', $data->getData('cc_bin'));
            }
        }
    }

    /**
     * Store transaction ID if given and not Accept.js
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param \Magento\Framework\DataObject $data
     * @param \ParadoxLabs\TokenBase\Api\MethodInterface $tokenbaseMethod
     * @return void
     */
    public function processAcceptHosted(
        \Magento\Payment\Model\InfoInterface $payment,
        \Magento\Framework\DataObject $data,
        \ParadoxLabs\TokenBase\Api\MethodInterface $tokenbaseMethod
    ): void {
        if ($tokenbaseMethod->isAcceptJsEnabled() === true) {
            return;
        }

        $transactionId = $data->getData('transaction_id');
        if (empty($transactionId)
            || $payment->getAdditionalInformation('transaction_id') === $transactionId
            || $payment instanceof \Magento\Quote\Model\Quote\Payment === false) {
            return;
        }

        // TODO: Clean up this method

        /** @var \ParadoxLabs\Authnetcim\Model\Gateway $gateway */
        $gateway = $tokenbaseMethod->gateway();
        $gateway->setTransactionId($transactionId);

        $transactionDetails = $gateway->getTransactionDetailsObject();

        if (!in_array((int)$transactionDetails->getResponseCode(), [1, 4], true)) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Transaction was declined.'));
        }

        $quote = $payment->getQuote();
        if ($transactionDetails->getData('customer_email') !== $quote->getCustomerEmail()
            || $transactionDetails->getData('invoice_number') !== (string)$quote->getReservedOrderId()
            || $transactionDetails->getData('amount') < $quote->getBaseGrandTotal()) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Transaction failed, please try again.'));
        }

        $submitTime = strtotime($transactionDetails->getData('submit_time_utc'));
        $window     = 15 * 60; // Disallow transaction completion after 15 minutes
        if ($submitTime < (time() - $window)) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Transaction expired, please try again.'));
        }

        $payment->setAdditionalInformation(
            array_replace_recursive($payment->getAdditionalInformation(), $transactionDetails->getData())
        );

        /**
         * Get/create card from transaction
         */

        /** @var \ParadoxLabs\Authnetcim\Model\Card $card */
        $card = $this->cardFactory->create();
        $card->setMethod($payment->getMethod());
        $card->setCustomerId($quote->getCustomerId());
        $card->setCustomerEmail($quote->getCustomerEmail());
        $card->setActive(false);
        $card->setProfileId($payment->getAdditionalInformation('profile_id'));
        $card->setAddress($quote->getBillingAddress()->getDataModel());

        $this->customerProfileService->setMethod($tokenbaseMethod);

        if ((bool)$data->getData('save') === true) {
            /**
             * Card was saved to CIM profile at checkout -- find the newest card on the CIM profile and import it.
             */

            $newestCardProfile = $this->customerProfileService->fetchAddedCard(
                $payment->getAdditionalInformation('profile_id')
            );

            $card->setProfileId($payment->getAdditionalInformation('profile_id'));
            $card->setActive(true);

            $card = $this->customerProfileService->importPaymentProfile(
                $card,
                $newestCardProfile
            );
        } else {
            /**
             * Card was not saved to profile at checkout -- we need to save it from the transaction, then import it.
             */
            $gateway->setParameter('customerProfileId', $payment->getAdditionalInformation('profile_id'));
            $result = $gateway->createCustomerProfileFromTransaction();

            $card->setPaymentId($result['customerPaymentProfileIdList']['numericString']);

            $card = $this->customerProfileService->updateCardFromPaymentProfile($card);
        }

        $payment->setData('tokenbase_id', $card->getId());
        $payment->setAdditionalInformation('payment_id', $card->getPaymentId());
    }
}
