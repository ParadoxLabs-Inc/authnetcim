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
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order\Payment;
use ParadoxLabs\Authnetcim\Model\Wallet\Config;
use ParadoxLabs\Authnetcim\Model\Wallet\Gateway as WalletGateway;
use ParadoxLabs\Authnetcim\Model\Wallet\GatewayProvider;
use ParadoxLabs\Authnetcim\Model\Wallet\PaymentResponseHandler;
use ParadoxLabs\Authnetcim\Model\Wallet\WalletType;
use ParadoxLabs\TokenBase\Helper\Data as Helper;

/**
 * Shared plumbing for wallet gateway commands.
 */
abstract class AbstractCommand implements CommandInterface
{
    /**
     * @param GatewayProvider $gatewayProvider
     * @param Config $config
     * @param PaymentResponseHandler $responseHandler
     * @param Helper $helper
     */
    public function __construct(
        protected readonly GatewayProvider $gatewayProvider,
        protected readonly Config $config,
        protected readonly PaymentResponseHandler $responseHandler,
        protected readonly Helper $helper,
    ) {
    }

    /**
     * Get the order payment from the command subject.
     *
     * @param array $commandSubject
     * @return Payment
     * @throws CommandException
     */
    protected function getPayment(array $commandSubject): Payment
    {
        $payment = SubjectReader::readPayment($commandSubject)->getPayment();

        if (!$payment instanceof Payment) {
            throw new CommandException(__('Wallet payment commands require an order payment.'));
        }

        return $payment;
    }

    /**
     * Get an initialized gateway for the payment's store.
     *
     * @param Payment $payment
     * @return WalletGateway
     */
    protected function getGateway(Payment $payment): WalletGateway
    {
        $storeId = $payment->getOrder()->getStoreId();

        return $this->gatewayProvider->get($storeId !== null ? (int)$storeId : null);
    }

    /**
     * Get a gateway loaded with the payment's wallet token and order context, for a new transaction.
     *
     * @param Payment $payment
     * @return WalletGateway
     * @throws CommandException
     */
    protected function getGatewayForNewTransaction(Payment $payment): WalletGateway
    {
        $walletType = (string)$payment->getAdditionalInformation('wallet_type');
        $token      = (string)$payment->getAdditionalInformation('wallet_token');
        $storeId    = $payment->getOrder()->getStoreId() !== null ? (int)$payment->getOrder()->getStoreId() : null;

        // Single-use: remove the token from the payment now, so it cannot persist or be replayed.
        $this->responseHandler->clearTransientData($payment);

        if (!isset(WalletType::DESCRIPTORS[$walletType])
            || !$this->config->isWalletEnabled($walletType, $storeId)) {
            throw new CommandException(
                __('The selected wallet is not available. Please choose another payment method.')
            );
        }

        if ($token === '') {
            throw new CommandException(__('Your wallet payment could not be processed. Please try again.'));
        }

        $gateway = $this->getGateway($payment);
        $gateway->setWalletToken(WalletType::DESCRIPTORS[$walletType], $token);
        $gateway->setOrderContext($payment, $this->config->shouldSendShippingAddress($storeId));

        if ($this->config->shouldSendLineItems($storeId)) {
            $gateway->setLineItems($payment->getOrder()->getAllVisibleItems());
        }

        return $gateway;
    }

    /**
     * Get the Authorize.net transaction ID to act on (strips Magento suffixes such as -capture, -1).
     *
     * @param string|null $transactionId
     * @return string
     */
    protected function normalizeTransactionId(?string $transactionId): string
    {
        $transactionId = (string)$transactionId;

        return substr($transactionId, 0, strcspn($transactionId, '-'));
    }

    /**
     * Write to the wallet log.
     *
     * @param string $message
     * @return void
     */
    protected function log(string $message): void
    {
        $this->helper->log(WalletType::METHOD_CODE, $message);
    }
}
