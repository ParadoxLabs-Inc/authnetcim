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

/**
 * Decline a transaction held for review by Authorize.net fraud filters.
 */
class DenyPaymentCommand extends AbstractCommand
{
    /**
     * @param array $commandSubject
     * @return null
     * @throws LocalizedException
     * @throws CommandException
     */
    public function execute(array $commandSubject)
    {
        $payment       = $this->getPayment($commandSubject);
        $transactionId = $this->normalizeTransactionId((string)$payment->getLastTransId());

        $this->log(sprintf('denyPayment(%s, %s)', $payment->getOrder()->getIncrementId(), $transactionId));

        $response = $this->getGateway($payment)->denyPayment($payment, $transactionId);

        $this->logResponse($response);

        if ($response->getData('is_denied') !== true) {
            throw new LocalizedException(__('Authorize.net did not decline the held payment.'));
        }

        $payment->setIsTransactionDenied(true);

        return null;
    }
}
