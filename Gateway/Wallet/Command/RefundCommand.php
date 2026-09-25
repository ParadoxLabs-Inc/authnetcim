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
use Magento\Sales\Api\Data\CreditmemoInterface;

/**
 * Refund a captured wallet transaction.
 */
class RefundCommand extends AbstractCommand
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

        $this->log(sprintf('refund(%s, %s)', $payment->getOrder()->getIncrementId(), $amount));

        $creditmemo = $payment->getCreditmemo();
        if ($payment->getParentTransactionId() != '') {
            $transactionId = $payment->getParentTransactionId();
        } elseif ($creditmemo instanceof CreditmemoInterface
            && $creditmemo->getInvoice() !== null
            && $creditmemo->getInvoice()->getTransactionId() != '') {
            $transactionId = $creditmemo->getInvoice()->getTransactionId();
        } else {
            $transactionId = $payment->getLastTransId();
        }

        $transactionId = $this->normalizeTransactionId((string)$transactionId);

        $gateway = $this->getGateway($payment);
        if ($this->config->shouldSendLineItems((int)$payment->getOrder()->getStoreId())
            && $creditmemo instanceof CreditmemoInterface) {
            $gateway->setLineItems($creditmemo->getAllItems());
        }

        $response = $gateway->refund($payment, $amount, $transactionId);

        $this->logResponse($response);

        $this->responseHandler->handleFollowUpTransaction($payment, $response);

        $payment->setIsTransactionClosed(true);

        if ($response->getTransactionId() != '' && $response->getTransactionId() != $transactionId) {
            $payment->setTransactionId(
                $this->responseHandler->getUniqueTransactionId($payment, (string)$response->getTransactionId())
            );
        } else {
            $payment->setTransactionId(
                $this->responseHandler->getUniqueTransactionId($payment, $transactionId . '-refund')
            );
        }

        $invoice = $creditmemo instanceof CreditmemoInterface ? $creditmemo->getInvoice() : null;
        if ($invoice !== null && $invoice->getBaseTotalRefunded() < $invoice->getBaseGrandTotal()) {
            $payment->setShouldCloseParentTransaction(false);
        } else {
            $payment->setShouldCloseParentTransaction(true);
        }

        return null;
    }
}
