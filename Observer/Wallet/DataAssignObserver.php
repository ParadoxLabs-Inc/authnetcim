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

namespace ParadoxLabs\Authnetcim\Observer\Wallet;

use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\PaymentInterface;
use ParadoxLabs\Authnetcim\Model\Wallet\WalletType;

/**
 * Copy wallet payment data from the checkout request onto the payment.
 */
class DataAssignObserver extends AbstractDataAssignObserver
{
    /**
     * Base64 token upper bound. Apple and Google payloads are a few KB; this only guards against abuse.
     */
    private const MAX_TOKEN_LENGTH = 32768;

    /**
     * The approved wallet sheet total, as the client formats it (e.g. '12.34'). The D modifier stops $ from also
     * matching before a trailing newline.
     */
    private const AMOUNT_PATTERN = '/^\d{1,10}(\.\d{1,2})?$/D';

    /**
     * @param Observer $observer
     * @return void
     * @throws LocalizedException
     */
    public function execute(Observer $observer)
    {
        $data           = $this->readDataArgument($observer);
        $additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);

        if (!is_array($additionalData)) {
            return;
        }

        $payment = $this->readPaymentModelArgument($observer);

        $walletType = (string)($additionalData['wallet_type'] ?? '');
        $token      = (string)($additionalData['wallet_token'] ?? '');

        /**
         * GraphQL and Hyvä placeOrder re-import payment data without the token. Never let an empty re-import
         * wipe a token already assigned in this request.
         */
        if ($token === '') {
            return;
        }

        if (!isset(WalletType::DESCRIPTORS[$walletType])) {
            throw new LocalizedException(__('Invalid wallet type.'));
        }

        if (strlen($token) > self::MAX_TOKEN_LENGTH
            || preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $token) !== 1) {
            throw new LocalizedException(__('Invalid wallet payment data. Please try again.'));
        }

        $amount = $this->readAmount($additionalData);

        $payment->setAdditionalInformation('wallet_type', $walletType);
        $payment->setAdditionalInformation('wallet_token', $token);
        $payment->setAdditionalInformation(
            'card_display',
            $this->sanitizeDisplay((string)($additionalData['card_display'] ?? ''))
        );

        /**
         * The total the customer approved in the wallet sheet, checked against the order total before the token is
         * charged. A new token without one must not inherit the amount from an earlier attempt.
         */
        if ($amount !== null) {
            $payment->setAdditionalInformation('wallet_amount', $amount);
        } else {
            $payment->unsAdditionalInformation('wallet_amount');
        }
    }

    /**
     * Get the wallet sheet total the customer approved, or null if the client did not send one.
     *
     * @param array $additionalData
     * @return string|null
     * @throws LocalizedException
     */
    private function readAmount(array $additionalData): ?string
    {
        $amount = $additionalData['wallet_amount'] ?? null;

        if ($amount === null || $amount === '') {
            return null;
        }

        if (!is_scalar($amount) || preg_match(self::AMOUNT_PATTERN, (string)$amount) !== 1) {
            throw new LocalizedException(__('Invalid wallet payment data. Please try again.'));
        }

        return (string)$amount;
    }

    /**
     * Keep only a short, plain-text card description (e.g. 'Visa 1234') from the wallet.
     *
     * @param string $display
     * @return string
     */
    private function sanitizeDisplay(string $display): string
    {
        $display = (string)preg_replace('/[^\p{L}\p{N} .\-•*]/u', '', $display);

        return mb_substr(trim($display), 0, 64);
    }
}
