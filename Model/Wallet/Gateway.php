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
 *
 * @link https://support.paradoxlabs.com
 */

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Model\Wallet;

use Magento\Payment\Gateway\Command\CommandException;
use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Model\Order\Payment;
use Override;
use ParadoxLabs\Authnetcim\Model\Gateway as CimGateway;
use ParadoxLabs\TokenBase\Model\Gateway\Response;

/**
 * Authorize.net gateway for single-use wallet payments (Apple Pay, Google Pay).
 *
 * Reuses the Authorize.net CIM request building and response handling, with three differences:
 * - Payment is sent as opaqueData on the transaction itself, and never creates a CIM profile.
 * - No card-on-file (COF) indicators: a wallet token is not a stored credential.
 * - No stored card to fall back on, so an expired authorization cannot be recaptured.
 */
class Gateway extends CimGateway
{
    /**
     * @var string
     */
    protected $code = 'authnetcim_wallet';

    /**
     * Set the wallet payment token to send on the next new transaction.
     *
     * @param string $descriptor
     * @param string $token
     * @return $this
     */
    public function setWalletToken(string $descriptor, string $token): self
    {
        $this->setParameter('dataDescriptor', $descriptor);
        $this->setParameter('dataValue', $token);

        return $this;
    }

    /**
     * Set customer, billing, and (optionally) shipping details from the order.
     *
     * @param Payment $payment
     * @param bool $includeShipping
     * @return $this
     */
    public function setOrderContext(Payment $payment, bool $includeShipping): self
    {
        $order = $payment->getOrder();

        $this->setParameter('email', $order->getCustomerEmail());
        $this->setParameter('merchantCustomerId', $order->getCustomerId());
        $this->setParameter('customerIp', $order->getRemoteIp());

        if ($order->getBillingAddress() instanceof OrderAddressInterface) {
            $this->setBillTo($order->getBillingAddress());
        }

        $shippingAddress = $order->getShippingAddress();
        if ($includeShipping === true
            && (bool)$order->getIsVirtual() === false
            && $shippingAddress instanceof OrderAddressInterface) {
            $this->setParameter('shipToFirstName', $shippingAddress->getFirstname());
            $this->setParameter('shipToLastName', $shippingAddress->getLastname());
            $this->setParameter('shipToCompany', $shippingAddress->getCompany());
            $this->setParameter('shipToAddress', implode(' ', $shippingAddress->getStreet() ?: []));
            $this->setParameter('shipToCity', $shippingAddress->getCity());
            $this->setParameter(
                'shipToState',
                $shippingAddress->getRegionCode() ?: $shippingAddress->getRegion()
            );
            $this->setParameter('shipToZip', $shippingAddress->getPostcode());
            $this->setParameter('shipToCountry', $shippingAddress->getCountryId());
        }

        return $this;
    }

    /**
     * Run an auth transaction for $amount with the current wallet token.
     *
     * @param InfoInterface $payment
     * @param float $amount
     * @return Response
     * @throws CommandException
     */
    #[Override]
    public function authorize(InfoInterface $payment, $amount)
    {
        /** @var Payment $payment */
        $this->assertHasWalletToken();

        $this->setParameter('transactionType', 'authOnlyTransaction');
        $this->setParameter('amount', $amount);
        $this->setParameter('invoiceNumber', $payment->getOrder()->getIncrementId());

        if ($payment->getOrder()->getBaseTaxAmount()) {
            $this->setParameter('taxAmount', $payment->getOrder()->getBaseTaxAmount());
        }

        if ($payment->getBaseShippingAmount()) {
            $this->setParameter('shipAmount', $payment->getBaseShippingAmount());
        }

        $result = $this->createTransaction();

        return $this->interpretTransaction($result);
    }

    /**
     * Run a capture transaction for $amount.
     *
     * Captures the prior authorization if we have one; otherwise runs auth+capture with the wallet token.
     *
     * @param InfoInterface $payment
     * @param float $amount
     * @param string|null $transactionId
     * @return Response
     * @throws CommandException
     */
    #[Override]
    public function capture(InfoInterface $payment, $amount, $transactionId = null)
    {
        /** @var Payment $payment */
        if (!empty($transactionId)) {
            $this->setParameter('transactionType', 'priorAuthCaptureTransaction');
            $this->setParameter('transId', $transactionId);
        } else {
            $this->assertHasWalletToken();

            $this->setParameter('transactionType', 'authCaptureTransaction');
        }

        $this->setParameter('amount', $amount);
        $this->setParameter('invoiceNumber', $payment->getOrder()->getIncrementId());

        $this->captureGetAmountInfo($payment);

        $result   = $this->createTransaction();
        $response = $this->interpretTransaction($result);

        /**
         * 'Transaction not found' on a prior-auth capture means the authorization expired. The card CIM method
         * recaptures from the stored card here. A wallet token is single-use, so there is nothing to recapture with.
         */
        if ((int)$response->getResponseReasonCode() === 16 && !empty($transactionId)) {
            $this->helper->log(
                $this->code,
                sprintf("Authorization not found or expired on capture.\n%s", json_encode($response->getData()))
            );

            throw new CommandException(
                __(
                    'The Authorize.net authorization has expired and cannot be captured. Wallet payments can not '
                    . 'be reauthorized; please collect payment another way.'
                )
            );
        }

        return $response;
    }

    /**
     * Run a refund transaction for $amount.
     *
     * @param InfoInterface $payment
     * @param float $amount
     * @param string|null $transactionId
     * @return Response
     * @throws CommandException
     */
    #[Override]
    public function refund(InfoInterface $payment, $amount, $transactionId = null)
    {
        /** @var Payment $payment */
        $last4 = (string)$payment->getCcLast4();
        if (preg_match('/^\d{4}$/', $last4) !== 1) {
            throw new CommandException(
                __('Unable to refund online: the card number for this wallet payment is unknown.')
            );
        }

        $this->setParameter('transactionType', 'refundTransaction');
        $this->setParameter('amount', $amount);
        $this->setParameter('invoiceNumber', $payment->getOrder()->getIncrementId());

        // Refunds need the card last4 (for wallets, the device account number) along with the original txn.
        $this->setParameter('cardNumber', $last4);

        $creditmemo = $payment->getCreditmemo();
        if ($creditmemo instanceof CreditmemoInterface) {
            if ($creditmemo->getBaseTaxAmount()) {
                $this->setParameter('taxAmount', $creditmemo->getBaseTaxAmount());
            }

            if ($creditmemo->getBaseShippingAmount()) {
                $this->setParameter('shipAmount', $creditmemo->getBaseShippingAmount());
            }

            $billingAddress = $creditmemo->getBillingAddress();
            if ($billingAddress instanceof OrderAddressInterface) {
                $this->setBillTo($billingAddress);
            }
        }

        if (!empty($transactionId)) {
            $this->setParameter('transId', $transactionId);
        } elseif (!empty($payment->getTransactionId())) {
            $this->setParameter('transId', $payment->getTransactionId());
        }

        $result   = $this->createTransaction();
        $response = $this->interpretTransaction($result);

        /**
         * 'Transaction unsettled': a full refund can be a void instead. Partial refunds have to wait for settlement.
         */
        if ((int)$response->getResponseReasonCode() === 54) {
            if ($creditmemo instanceof CreditmemoInterface
                && $creditmemo->getInvoice() !== null
                && abs((float)$amount - (float)$creditmemo->getInvoice()->getBaseGrandTotal()) < 0.0001) {
                $refTransactionId = (string)$this->getParameter('transId');

                $this->helper->log(
                    $this->code,
                    sprintf(
                        "Transaction %s is unsettled; voiding it instead of refunding.\n%s",
                        $refTransactionId,
                        json_encode($response->getData())
                    )
                );

                return $this->clearParameters()->void($payment, $refTransactionId);
            }

            $this->helper->log(
                $this->code,
                sprintf(
                    "Transaction error: %s\n%s\n%s",
                    $response->getResponseReasonText(),
                    json_encode($response->getData()),
                    $this->log
                )
            );

            throw new CommandException(
                __(
                    'Authorize.net: this payment has not settled yet, so it can only be refunded in full. '
                    . 'Please try the partial refund again after settlement.'
                )
            );
        }

        return $response;
    }

    /**
     * Add payment info to a createTransaction API request's parameters.
     *
     * The wallet token goes on the transaction as opaqueData, with no CIM profile creation.
     *
     * @param array $params
     * @param string $type
     * @param bool $isNewCard
     * @return array
     */
    #[Override]
    protected function createTransactionAddPaymentInfo($params, $type, $isNewCard)
    {
        if ($this->hasParameter('dataValue') && $this->hasParameter('dataDescriptor')) {
            $params['payment'] = [
                'opaqueData' => [
                    'dataDescriptor' => $this->getParameter('dataDescriptor'),
                    'dataValue' => $this->getParameter('dataValue'),
                ],
            ];
        }

        return $params;
    }

    /**
     * Wallet transactions are customer-initiated with a one-time network token: never send card-on-file indicators.
     *
     * @param array $params
     * @return array
     */
    #[Override]
    protected function createTransactionAddCOFIndicators(array $params)
    {
        return $params;
    }

    /**
     * Mask certain values in the XML for secure logging purposes. Adds the wallet token to the parent's list.
     *
     * @param string $string
     * @return string
     */
    #[Override]
    protected function sanitizeLog($string)
    {
        $string = (string)preg_replace(
            '#<dataValue>(.+?)</dataValue>#s',
            '<dataValue>XXX</dataValue>',
            (string)$string
        );

        return (string)parent::sanitizeLog($string);
    }

    /**
     * Ensure we have a wallet token before running a new transaction.
     *
     * @return void
     * @throws CommandException
     */
    private function assertHasWalletToken(): void
    {
        if (empty($this->getParameter('dataValue')) || empty($this->getParameter('dataDescriptor'))) {
            throw new CommandException(
                __('Your wallet payment could not be processed. Please try again.')
            );
        }
    }
}
