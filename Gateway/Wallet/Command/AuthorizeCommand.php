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

/**
 * Authorize the order total with the wallet token.
 */
class AuthorizeCommand extends AbstractCommand
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

        $this->log(sprintf('authorize(%s, %s)', $payment->getOrder()->getIncrementId(), $amount));

        $gateway  = $this->getGatewayForNewTransaction($payment);
        $response = $gateway->authorize($payment, $amount);

        $this->logResponse($response);

        $this->responseHandler->handleNewTransaction($payment, $response);

        $payment->setTransactionId(
            $this->responseHandler->getUniqueTransactionId($payment, (string)$response->getTransactionId())
        );
        $payment->setIsTransactionClosed(false);

        return null;
    }
}
