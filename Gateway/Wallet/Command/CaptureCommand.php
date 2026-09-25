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

namespace ParadoxLabs\Authnetcim\Gateway\Wallet\Command;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Command\CommandException;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Model\Order\Payment;

/**
 * Capture: prior-auth capture when the order was authorized, otherwise auth+capture with the wallet token.
 */
class CaptureCommand extends AbstractCommand
{
    /**
     * Tolerance for comparing currency amounts as floats.
     */
    private const AMOUNT_EPSILON = 0.0001;

    /**
     * @param array $commandSubject
     * @return null
     * @throws CommandException
     * @throws LocalizedException
     */
    public function execute(array $commandSubject)
    {
        $payment = $this->getPayment($commandSubject);
        $amount  = (float)SubjectReader::readAmount($commandSubject);

        $this->log(sprintf('capture(%s, %s)', $payment->getOrder()->getIncrementId(), $amount));

        $authTransactionId = $this->getOpenAuthTransactionId($payment);

        if ($authTransactionId !== null) {
            $this->assertFullCapture($payment, $amount);

            // Admin-initiated (invoice): gateway errors are left as-is, so the merchant sees the detail.
            $response = $this->getGateway($payment)->capture($payment, $amount, $authTransactionId);

            $this->responseHandler->handleFollowUpTransaction($payment, $response);
        } else {
            $gateway  = $this->getGatewayForNewTransaction($payment);
            $response = $this->runCheckoutTransaction(
                $payment,
                $gateway,
                fn () => $gateway->capture($payment, $amount)
            );

            $this->responseHandler->handleNewTransaction($payment, $response);
        }

        $this->logResponse($response);

        // Set transaction id iff different from the parent -- use Magento's generated ID otherwise.
        if ($payment->getParentTransactionId() != $response->getTransactionId()) {
            $payment->setTransactionId(
                $this->responseHandler->getUniqueTransactionId($payment, (string)$response->getTransactionId())
            );
        }

        $payment->setIsTransactionClosed(false);
        $payment->setShouldCloseParentTransaction(true);

        return null;
    }

    /**
     * Reject a prior-auth capture for less than the authorization.
     *
     * A wallet authorization can be captured only once: the capture closes it, and the single-use token cannot
     * reauthorize the remainder. Magento enforces can_capture_partial=0 only in the admin invoice form, so a partial
     * invoice from the REST API or code would otherwise capture part of the order and strand the rest.
     *
     * @param Payment $payment
     * @param float $amount
     * @return void
     * @throws LocalizedException
     */
    private function assertFullCapture(
        Payment $payment,
        float $amount,
    ): void {
        $authorized = (float)$payment->getBaseAmountAuthorized();

        if ($authorized > 0 && $amount < $authorized - self::AMOUNT_EPSILON) {
            $this->log(
                sprintf(
                    'Rejected partial capture of %s against authorization of %s for order %s',
                    $amount,
                    $authorized,
                    $payment->getOrder()->getIncrementId()
                )
            );

            throw new LocalizedException(
                __('Apple Pay / Google Pay payments must be captured in full in a single invoice.')
            );
        }
    }

    /**
     * Get the open authorization transaction ID to capture, if any.
     *
     * @param Payment $payment
     * @return string|null
     */
    private function getOpenAuthTransactionId(Payment $payment): ?string
    {
        $authTransaction = $payment->getAuthorizationTransaction();

        if (!$authTransaction instanceof TransactionInterface
            || (int)$authTransaction->getIsClosed() === 1
            || empty($authTransaction->getTxnId())) {
            return null;
        }

        $transactionId = $payment->getParentTransactionId() ?: $authTransaction->getTxnId();

        return $this->normalizeTransactionId((string)$transactionId);
    }
}
