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

use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\Repository as TransactionRepository;
use ParadoxLabs\Authnetcim\Model\Wallet\WalletType;
use ParadoxLabs\TokenBase\Model\Gateway\Response;

/**
 * Apply Authorize.net transaction results to an order payment.
 */
class PaymentResponseHandler
{
    /**
     * Response keys not worth keeping on a wallet payment (no CIM profile or customer mapping).
     */
    private const EXCLUDED_KEYS = [
        'profile_id',
        'payment_id',
        'customer_id',
        'split_tender_id',
        'requested_amount',
        'balance_on_card',
        'is_error',
    ];

    /**
     * Payment additional_information keys that must never be persisted after a transaction.
     */
    public const TRANSIENT_KEYS = [
        'wallet_token',
    ];

    /**
     * @param TransactionRepository $transactionRepository
     */
    public function __construct(
        private readonly TransactionRepository $transactionRepository,
    ) {
    }

    /**
     * Apply a new-transaction (auth or auth+capture) response to the payment.
     *
     * @param Payment $payment
     * @param Response $response
     * @return void
     */
    public function handleNewTransaction(Payment $payment, Response $response): void
    {
        $this->storeCardDetails($payment, $response);
        $this->storeTransactionStatuses($payment, $response);
        $this->handleFraud($payment, $response);
        $this->mergeAdditionalInformation($payment, $response);

        $payment->setTransactionAdditionalInfo(Transaction::RAW_DETAILS, $this->getRawDetails($response));
    }

    /**
     * Apply a follow-up (capture/refund/void) response to the payment.
     *
     * @param Payment $payment
     * @param Response $response
     * @return void
     */
    public function handleFollowUpTransaction(Payment $payment, Response $response): void
    {
        $this->storeTransactionStatuses($payment, $response);
        $this->mergeAdditionalInformation($payment, $response);

        $payment->setTransactionAdditionalInfo(Transaction::RAW_DETAILS, $this->getRawDetails($response));
    }

    /**
     * Mark the payment for review if Authorize.net held the transaction.
     *
     * @param Payment $payment
     * @param Response $response
     * @return void
     */
    public function handleFraud(Payment $payment, Response $response): void
    {
        if ($response->getData('is_fraud') === true) {
            $payment->setIsTransactionPending(true);
            $payment->setIsFraudDetected(true);
            $payment->setTransactionAdditionalInfo('is_transaction_fraud', true);
        }
    }

    /**
     * Remove single-use wallet data from the payment so it is never persisted.
     *
     * @param InfoInterface $payment
     * @return void
     */
    public function clearTransientData(InfoInterface $payment): void
    {
        foreach (self::TRANSIENT_KEYS as $key) {
            if ($payment->hasAdditionalInformation($key)) {
                $payment->unsAdditionalInformation($key);
            }
        }
    }

    /**
     * Get a transaction ID that does not already exist on the order (txnId, txnId-1, txnId-2, ...).
     *
     * @param Payment $payment
     * @param string $transactionId
     * @return string
     */
    public function getUniqueTransactionId(Payment $payment, string $transactionId): string
    {
        $baseId    = $transactionId;
        $increment = 1;

        while ($this->transactionRepository->getByTransactionId(
            $transactionId,
            (int)$payment->getId(),
            (int)$payment->getOrder()->getId()
        ) !== false) {
            $transactionId = $baseId . '-' . ($increment++);
        }

        return $transactionId;
    }

    /**
     * Store card type and last4 as Authorize.net reports them.
     *
     * @param Payment $payment
     * @param Response $response
     * @return void
     */
    private function storeCardDetails(Payment $payment, Response $response): void
    {
        $accountNumber = (string)$response->getData('acc_number');
        if (preg_match('/(\d{4})$/', $accountNumber, $matches) === 1) {
            $payment->setCcLast4($matches[1]);
        }

        $ccType = WalletType::ACCOUNT_TYPE_MAP[(string)$response->getData('card_type')] ?? null;
        if ($ccType !== null) {
            $payment->setCcType($ccType);
        }
    }

    /**
     * Store AVS/CCV/CAVV results and the approval code.
     *
     * @param Payment $payment
     * @param Response $response
     * @return void
     */
    private function storeTransactionStatuses(Payment $payment, Response $response): void
    {
        if (empty($payment->getData('cc_avs_status')) && !empty($response->getData('avs_result_code'))) {
            $payment->setData('cc_avs_status', $response->getData('avs_result_code'));
        }

        if (empty($payment->getData('cc_status')) && !empty($response->getData('cavv_response_code'))) {
            $payment->setData('cc_status', $response->getData('cavv_response_code'));
        }

        if (!empty($response->getData('auth_code'))) {
            $payment->setData('cc_approval', $response->getData('auth_code'));
        }
    }

    /**
     * Merge response data into payment additional_information.
     *
     * @param Payment $payment
     * @param Response $response
     * @return void
     */
    private function mergeAdditionalInformation(Payment $payment, Response $response): void
    {
        $payment->setAdditionalInformation(
            array_replace(
                (array)$payment->getAdditionalInformation(),
                $this->getRawDetails($response)
            )
        );

        $this->clearTransientData($payment);
    }

    /**
     * Get response data suitable for storage.
     *
     * @param Response $response
     * @return array
     */
    private function getRawDetails(Response $response): array
    {
        $data = (array)$response->getData();
        foreach (self::EXCLUDED_KEYS as $key) {
            unset($data[$key]);
        }

        return array_filter(
            $data,
            static fn ($value): bool => is_scalar($value) || $value === null
        );
    }
}
