<?php declare(strict_types=1);
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
 *
 * @link https://support.paradoxlabs.com
 */

namespace ParadoxLabs\Authnetcim\Observer;

use Override;
use ParadoxLabs\Authnetcim\Model\Card;
use Magento\Payment\Gateway\Command\CommandException;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\InfoInterface;
use Magento\Quote\Api\Data\PaymentExtensionInterface;
use Magento\Quote\Model\Quote\Payment;
use Magento\Sales\Api\Data\OrderPaymentExtensionInterface;
use ParadoxLabs\Authnetcim\Model\ConfigProvider;
use ParadoxLabs\Authnetcim\Model\Gateway;
use ParadoxLabs\Authnetcim\Model\Service\CustomerProfile;
use ParadoxLabs\TokenBase\Api\CardRepositoryInterface;
use ParadoxLabs\TokenBase\Api\Data\CardInterface;
use ParadoxLabs\TokenBase\Api\Data\CardInterfaceFactory;
use ParadoxLabs\TokenBase\Api\MethodInterface;
use ParadoxLabs\TokenBase\Helper\Data;
use ParadoxLabs\TokenBase\Model\Gateway\Response;
use ParadoxLabs\TokenBase\Model\Method\Factory;
use Throwable;

class PaymentMethodAssignDataObserver extends \ParadoxLabs\TokenBase\Observer\PaymentMethodAssignDataObserver
{
    /**
     * PaymentMethodAssignDataObserver constructor.
     *
     * @param Data $helper
     * @param CardRepositoryInterface $cardRepository
     * @param Factory $methodFactory
     * @param CustomerProfile $customerProfileService
     * @param \ParadoxLabs\TokenBase\Api\Data\CardInterfaceFactory $cardFactory
     */
    public function __construct(
        Data $helper,
        CardRepositoryInterface $cardRepository,
        protected readonly Factory $methodFactory,
        protected readonly CustomerProfile $customerProfileService,
        protected readonly CardInterfaceFactory $cardFactory
    ) {
        parent::__construct($helper, $cardRepository);
    }

    /**
     * Assign data to the payment instance for our methods.
     *
     * @param InfoInterface $payment
     * @param DataObject $data
     * @param \Magento\Payment\Model\MethodInterface $method
     * @return void
     */
    #[Override]
    protected function assignTokenbaseData(
        InfoInterface $payment,
        DataObject $data,
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
     * @param InfoInterface $payment
     * @param DataObject $data
     * @param MethodInterface $tokenbaseMethod
     * @return void
     */
    public function processAcceptJs(
        InfoInterface $payment,
        DataObject $data,
        MethodInterface $tokenbaseMethod
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

            /**
             * Since we received payment data, reset any attached stored card.
             * If this is a card edit, the card ID will be reloaded later.
             *
             * @see \ParadoxLabs\TokenBase\Observer\PaymentMethodAssignDataObserver::assignTokenbaseData()
             */
            $payment->setData('tokenbase_id', null);
            $paymentAttributes = $payment->getExtensionAttributes();
            if ($paymentAttributes instanceof PaymentExtensionInterface
                || $paymentAttributes instanceof OrderPaymentExtensionInterface) {
                $paymentAttributes->setTokenbaseId(null);
            }
        }
    }

    /**
     * Process transaction info for a Hosted checkout, if given
     *
     * @param InfoInterface $payment
     * @param DataObject $data
     * @param MethodInterface $tokenbaseMethod
     * @return void
     */
    public function processAcceptHosted(
        InfoInterface $payment,
        DataObject $data,
        MethodInterface $tokenbaseMethod
    ): void {
        $transactionId = $data->getData('transaction_id');

        if (empty($transactionId)
            || $tokenbaseMethod->isAcceptJsEnabled() === true
            || $payment->getAdditionalInformation('transaction_id') === $transactionId
            || $payment instanceof Payment === false) {
            return;
        }

        /**
         * Fetch and validate transaction info
         */
        /** @var Gateway $gateway */
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
     * @param Response $transactionDetails
     * @param Payment $payment
     * @return void
     * @throws LocalizedException
     */
    protected function validateHostedTransaction(
        Response $transactionDetails,
        Payment $payment
    ): void {
        if (!in_array((int)$transactionDetails->getResponseCode(), [1, 4], true)) {
            throw new LocalizedException(__('Transaction was declined.'));
        }

        $quote           = $payment->getQuote();
        $uncoveredAmount = (float)$quote->getBaseGrandTotal() - (float)$transactionDetails->getData('amount');
        if ($transactionDetails->getData('customer_email') !== $quote->getBillingAddress()->getEmail()
            || $transactionDetails->getData('invoice_number') !== (string)$quote->getReservedOrderId()
            || $uncoveredAmount > 0.001) {
            throw new LocalizedException(__('Transaction failed, please try again.'));
        }

        $submitTime = strtotime((string)$transactionDetails->getData('submit_time_utc'));
        $window     = 15 * 60; // Disallow transaction completion after 15 minutes
        if ($submitTime < (time() - $window)) {
            throw new LocalizedException(__('Transaction expired, please try again.'));
        }
    }

    /**
     * Create a TokenBase Card from the given payment info instance's data.
     *
     * @param Payment $payment
     * @return CardInterface
     * @throws LocalizedException
     */
    protected function createCard(Payment $payment): CardInterface
    {
        $quote = $payment->getQuote();

        /** @var Card $card */
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
     * @param Payment $payment
     * @param CardInterface $card
     * @return CardInterface
     * @throws LocalizedException
     * @throws CommandException
     */
    protected function importSavedPaymentProfile(
        Payment $payment,
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
     * @param Gateway $gateway
     * @param Payment $payment
     * @param CardInterface $card
     * @return CardInterface
     * @throws LocalizedException
     */
    protected function importNewPaymentProfile(
        Gateway $gateway,
        Payment $payment,
        CardInterface $card
    ): CardInterface {
        try {
            $gateway->setParameter('customerProfileId', $payment->getAdditionalInformation('profile_id'));
            $result = $gateway->createCustomerProfileFromTransaction();

            $card->setPaymentId($result['customerPaymentProfileIdList']['numericString']);

            $card = $this->customerProfileService->updateCardFromPaymentProfile($card);
        } catch (Throwable $exception) {
            /**
             * If CIM payment storage failed, create card without it for processing purposes.
             * New transactions won't work, but capture/void should.
             */
            $this->helper->log(
                ConfigProvider::CODE,
                'CIM Payment Profile creation failed: ' . $exception->getMessage()
            );

            $this->cardRepository->save($card);
        }

        return $card;
    }
}
