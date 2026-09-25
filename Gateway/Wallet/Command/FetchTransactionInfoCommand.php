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
use Magento\Sales\Api\Data\TransactionInterface;

/**
 * Check the current review status of a held transaction ('Get Payment Update').
 */
class FetchTransactionInfoCommand extends AbstractCommand
{
    /**
     * @param array $commandSubject
     * @return null
     * @throws CommandException
     */
    public function execute(array $commandSubject)
    {
        $payment       = $this->getPayment($commandSubject);
        $transactionId = $this->normalizeTransactionId((string)($commandSubject['transactionId'] ?? ''));

        $this->log(sprintf('fetchTransactionInfo(%s, %s)', $payment->getOrder()->getIncrementId(), $transactionId));

        $response = $this->getGateway($payment)->fraudUpdate($payment, $transactionId);

        $this->logResponse($response);

        if ($response->getData('is_approved')) {
            $payment->setData('parent_transaction_id', $transactionId);

            $transaction = $payment->getAuthorizationTransaction();
            if ($transaction instanceof TransactionInterface) {
                $transaction->setAdditionalInformation('is_transaction_fraud', false);
            }

            $payment->setIsTransactionApproved(true);
        } elseif ($response->getData('is_denied')) {
            $payment->setIsTransactionDenied(true);
        }

        return null;
    }
}
