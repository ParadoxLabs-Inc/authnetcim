<?php
/**
 * Copyright © 2015-present ParadoxLabs, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * Need help? Try our knowledgebase and support system:
 * @link https://support.paradoxlabs.com
 */

namespace ParadoxLabs\Authnetcim\Observer;

use ParadoxLabs\TokenBase\Api\Data\CardInterface;

/**
 * PaymentMethodAssignDataObserver Class
 */
class PaymentMethodAssignDataObserver extends \ParadoxLabs\TokenBase\Observer\PaymentMethodAssignDataObserver
{
    /**
     * @var \ParadoxLabs\TokenBase\Model\Method\Factory
     */
    protected $methodFactory;

    /**
     * @var \ParadoxLabs\Authnetcim\Model\Service\CustomerProfile
     */
    protected $customerProfileService;

    /**
     * @var \ParadoxLabs\TokenBase\Api\Data\CardInterfaceFactory
     */
    protected $cardFactory;

    /**
     * PaymentMethodAssignDataObserver constructor.
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

            if (empty($data->getData('card_id'))) {
                $payment->setData('tokenbase_id', null);
            }
        }
    }

    /**
     * Process transaction info for a Hosted checkout, if given
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
        $transactionId = $data->getData('transaction_id');

        if (empty($transactionId)
            || $tokenbaseMethod->isAcceptJsEnabled() === true
            || $payment->getAdditionalInformation('transaction_id') === $transactionId
            || $payment instanceof \Magento\Quote\Model\Quote\Payment === false) {
            return;
        }

        /**
         * Fetch and validate transaction info
         */

        /** @var \ParadoxLabs\Authnetcim\Model\Gateway $gateway */
        $gateway = $tokenbaseMethod->gateway();
        $gateway->setTransactionId($transactionId);

        $transactionDetails = $gateway->getTransactionDetailsObject();
        $this->validateHostedTransaction($transactionDetails, $payment);

        $payment->setAdditionalInformation(
            array_replace_recursive((array)$payment->getAdditionalInformation(), $transactionDetails->getData())
        );

        /**
         * Get/create card from transaction
         */
        $card = $this->createCard($payment);

        $this->customerProfileService->setMethod($tokenbaseMethod);

        // Import the transaction payment into a stored card, based on whether it was already saved or not.
        if ((bool)$data->getData('save') === true) {
            $card = $this->importSavedPaymentProfile($payment, $card);
        } else {
            $card = $this->importNewPaymentProfile($gateway, $payment, $card);
        }

        $payment->setData('tokenbase_id', $card->getId());
        $payment->setAdditionalInformation('payment_id', $card->getPaymentId());
    }

    /**
     * Validate the transaction details for the given transaction ID
     *
     * @param \ParadoxLabs\TokenBase\Model\Gateway\Response $transactionDetails
     * @param \Magento\Quote\Model\Quote\Payment $payment
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function validateHostedTransaction(
        \ParadoxLabs\TokenBase\Model\Gateway\Response $transactionDetails,
        \Magento\Quote\Model\Quote\Payment $payment
    ): void {
        if (!in_array((int)$transactionDetails->getResponseCode(), [1, 4], true)) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Transaction was declined.'));
        }

        $quote = $payment->getQuote();
        if ($transactionDetails->getData('customer_email') !== $quote->getBillingAddress()->getEmail()
            || $transactionDetails->getData('invoice_number') !== (string)$quote->getReservedOrderId()
            || $transactionDetails->getData('amount') < $quote->getBaseGrandTotal()) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Transaction failed, please try again.'));
        }

        $submitTime = strtotime((string)$transactionDetails->getData('submit_time_utc'));
        $window     = 15 * 60; // Disallow transaction completion after 15 minutes
        if ($submitTime < (time() - $window)) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Transaction expired, please try again.'));
        }
    }

    /**
     * Create a TokenBase Card from the given payment info instance's data.
     *
     * @param \Magento\Quote\Model\Quote\Payment $payment
     * @return \ParadoxLabs\TokenBase\Api\Data\CardInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function createCard(\Magento\Quote\Model\Quote\Payment $payment): CardInterface
    {
        $quote = $payment->getQuote();

        /** @var \ParadoxLabs\Authnetcim\Model\Card $card */
        $card = $this->cardFactory->create();
        $card->setMethod($payment->getMethod());
        $card->setCustomerId($quote->getCustomerId());
        $card->setCustomerEmail($quote->getCustomerEmail());
        $card->setActive(false);
        $card->setProfileId($payment->getAdditionalInformation('profile_id'));
        $card->setAddress($quote->getBillingAddress()->getDataModel());

        return $card;
    }

    /**
     * Import the newest card on the CIM profile to the given CardInterface.
     *
     * @param \Magento\Quote\Model\Quote\Payment $payment
     * @param \ParadoxLabs\TokenBase\Api\Data\CardInterface $card
     * @return \ParadoxLabs\TokenBase\Api\Data\CardInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Payment\Gateway\Command\CommandException
     */
    protected function importSavedPaymentProfile(
        \Magento\Quote\Model\Quote\Payment $payment,
        CardInterface $card
    ): CardInterface {
        $newestCardProfile = $this->customerProfileService->fetchAddedCard(
            $payment->getAdditionalInformation('profile_id')
        );

        $card->setActive($payment->getQuote()->getCustomerId() > 0);

        $card = $this->customerProfileService->importPaymentProfile(
            $card,
            $newestCardProfile
        );

        return $card;
    }

    /**
     * Create a CIM payment profile from the transaction, then fetch and import it to the given CardInterface.
     *
     * @param \ParadoxLabs\Authnetcim\Model\Gateway $gateway
     * @param \Magento\Quote\Model\Quote\Payment $payment
     * @param \ParadoxLabs\TokenBase\Api\Data\CardInterface $card
     * @return \ParadoxLabs\TokenBase\Api\Data\CardInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function importNewPaymentProfile(
        \ParadoxLabs\Authnetcim\Model\Gateway $gateway,
        \Magento\Quote\Model\Quote\Payment $payment,
        CardInterface $card
    ): CardInterface {
        try {
            $gateway->setParameter('customerProfileId', $payment->getAdditionalInformation('profile_id'));
            $result = $gateway->createCustomerProfileFromTransaction();

            $card->setPaymentId($result['customerPaymentProfileIdList']['numericString']);

            $card = $this->customerProfileService->updateCardFromPaymentProfile($card);
        } catch (\Exception $exception) {
            /**
             * If CIM payment storage failed, create card without it for processing purposes.
             * New transactions won't work, but capture/void should.
             */
            $this->helper->log(
                \ParadoxLabs\Authnetcim\Model\ConfigProvider::CODE,
                'CIM Payment Profile creation failed: ' . $exception->getMessage()
            );

            $this->cardRepository->save($card);
        }

        return $card;
    }
}
