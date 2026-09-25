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
     * @param array $commandSubject
     * @return null
     * @throws CommandException
     */
    public function execute(array $commandSubject)
    {
        $payment = $this->getPayment($commandSubject);
        $amount  = (float)SubjectReader::readAmount($commandSubject);

        $this->log(sprintf('capture(%s, %s)', $payment->getOrder()->getIncrementId(), $amount));

        $authTransactionId = $this->getOpenAuthTransactionId($payment);

        if ($authTransactionId !== null) {
            $response = $this->getGateway($payment)->capture($payment, $amount, $authTransactionId);

            $this->responseHandler->handleFollowUpTransaction($payment, $response);
        } else {
            $response = $this->getGatewayForNewTransaction($payment)->capture($payment, $amount);

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
