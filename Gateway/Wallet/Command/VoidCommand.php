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

use Magento\Framework\Exception\PaymentException;
use Magento\Payment\Gateway\Command\CommandException;
use Magento\Sales\Model\Order\Payment;
use ParadoxLabs\Authnetcim\Model\Wallet\Config;
use ParadoxLabs\Authnetcim\Model\Wallet\GatewayProvider;
use ParadoxLabs\Authnetcim\Model\Wallet\PaymentResponseHandler;
use ParadoxLabs\TokenBase\Helper\Data as Helper;
use Throwable;

/**
 * Void an authorization. Configured twice: 'void' throws on failure, 'cancel' records the failure on the order
 * instead, so order cancellation is never blocked by the gateway.
 */
class VoidCommand extends AbstractCommand
{
    /**
     * @param GatewayProvider $gatewayProvider
     * @param Config $config
     * @param PaymentResponseHandler $responseHandler
     * @param Helper $helper
     * @param bool $throwOnFailure
     */
    public function __construct(
        GatewayProvider $gatewayProvider,
        Config $config,
        PaymentResponseHandler $responseHandler,
        Helper $helper,
        private readonly bool $throwOnFailure = true,
    ) {
        parent::__construct(
            $gatewayProvider,
            $config,
            $responseHandler,
            $helper,
        );
    }

    /**
     * @param array $commandSubject
     * @return null
     * @throws PaymentException
     * @throws CommandException
     */
    public function execute(array $commandSubject)
    {
        $payment       = $this->getPayment($commandSubject);
        $transactionId = $this->normalizeTransactionId(
            (string)($payment->getParentTransactionId() ?: $payment->getLastTransId())
        );

        $this->log(sprintf('void(%s, %s)', $payment->getOrder()->getIncrementId(), $transactionId));

        if ($transactionId === '') {
            $payment->setShouldCloseParentTransaction(true);
            $payment->setIsTransactionClosed(true);

            return null;
        }

        try {
            $response = $this->getGateway($payment)->void($payment, $transactionId);

            $this->logResponse($response);

            $this->responseHandler->handleFollowUpTransaction($payment, $response);
        } catch (Throwable $exception) {
            $this->log('Void failed: ' . $exception->getMessage());

            $this->handleFailedVoid($payment, $exception);

            return null;
        }

        $payment->setShouldCloseParentTransaction(true);
        $payment->setIsTransactionClosed(true);

        return null;
    }

    /**
     * Handle a void the gateway rejected. Nothing was reversed, so the transaction must stay open.
     *
     * @param Payment $payment
     * @param Throwable $exception
     * @return void
     * @throws PaymentException
     */
    private function handleFailedVoid(Payment $payment, Throwable $exception): void
    {
        $payment->setShouldCloseParentTransaction(false);
        $payment->setIsTransactionClosed(false);

        $message = __(
            'Unable to void payment: %1 The authorization may still be open at the payment gateway.',
            $exception->getMessage()
        );

        if ($this->throwOnFailure === true) {
            throw new PaymentException($message);
        }

        $existingMessage = $payment->hasMessage() ? (string)$payment->getMessage() . ' ' : '';
        $payment->setMessage($existingMessage . $message);
    }
}
