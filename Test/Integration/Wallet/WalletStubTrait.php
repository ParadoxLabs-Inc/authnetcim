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

namespace ParadoxLabs\Authnetcim\Test\Integration\Wallet;

use LogicException;
use Magento\Framework\HTTP\ClientInterfaceFactory;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ResourceModel\Quote\CollectionFactory as QuoteCollectionFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment\Transaction\Manager as TransactionManager;
use Magento\Sales\Model\Order\Payment\Transaction\ManagerInterface as TransactionManagerInterface;
use Magento\Sales\Model\Order\Payment\Transaction\Repository as TransactionRepository;
use Magento\Sales\Model\OrderRepository;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\Collection as TransactionCollection;
use Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\CollectionFactory as TransactionCollectionFactory;
use Magento\TestFramework\ObjectManager;
use ParadoxLabs\Authnetcim\Model\Wallet\WalletType;
use ParadoxLabs\Authnetcim\Test\Integration\AuthnetClientStub;
use ParadoxLabs\Authnetcim\Test\Integration\AuthnetClientStubFactory;

/**
 * Shared wiring for Apple Pay / Google Pay (authnetcim_wallet) integration tests.
 *
 * Replaces the {@see ClientInterfaceFactory} every wallet gateway is built with, so the real Adapter facade, command
 * pool, wallet gateway, and response handler run against canned Authorize.net XML replies. The default replies
 * behave like Authorize.net: new transactions get a fresh transId (accountNumber XXXX1111, Visa), prior-auth
 * captures and voids echo the referenced transId, refunds get a fresh transId, and held-transaction updates succeed.
 * A test can override any reply by returning a string from its override callable, or null to use the default.
 *
 * Every property is nullable: the harness nulls all test-case properties after each class.
 */
trait WalletStubTrait
{
    /**
     * @var AuthnetClientStub|null
     */
    protected ?AuthnetClientStub $clientStub = null;

    /**
     * @var int|null
     */
    protected ?int $txnSequence = 0;

    /**
     * fn(string $requestType, string $requestXml): ?string
     *
     * @var callable|null
     */
    protected $responseOverride = null;

    /**
     * A Google Pay tokenizationData.token, base64-encoded as the checkout client sends it.
     *
     * @return string
     */
    protected function googlePayToken(): string
    {
        return base64_encode('{"signature":"MEUCIQD-integration","protocolVersion":"ECv2","signedMessage":"{}"}');
    }

    /**
     * An Apple Pay token.paymentData, base64-encoded JSON as the checkout client sends it.
     *
     * @return string
     */
    protected function applePayToken(): string
    {
        return base64_encode('{"version":"EC_v1","data":"integration","signature":"sig","header":{}}');
    }

    /**
     * Register the HTTP client double for all wallet gateway traffic.
     *
     * @param ObjectManager $objectManager
     * @param callable|null $override fn(string $requestType, string $requestXml): ?string
     * @return AuthnetClientStub
     */
    protected function registerWalletStub(
        ObjectManager $objectManager,
        ?callable $override = null,
    ): AuthnetClientStub {
        $this->txnSequence      = 0;
        $this->responseOverride = $override;
        $this->clientStub       = new AuthnetClientStub();
        $this->clientStub->setResponder(
            function (string $requestType, string $requestXml): string {
                if ($this->responseOverride !== null) {
                    $reply = ($this->responseOverride)($requestType, $requestXml);

                    if ($reply !== null) {
                        return $reply;
                    }
                }

                return $this->buildDefaultReply($requestType, $requestXml);
            }
        );

        $objectManager->addSharedInstance(
            new AuthnetClientStubFactory($this->clientStub),
            ClientInterfaceFactory::class
        );

        return $this->clientStub;
    }

    /**
     * Drop the HTTP client double so later tests get the real factory.
     *
     * @param ObjectManager $objectManager
     * @return void
     */
    protected function unregisterWalletStub(ObjectManager $objectManager): void
    {
        $objectManager->removeSharedInstance(ClientInterfaceFactory::class);
    }

    /**
     * Drop Magento's in-memory sales registries, as the next HTTP request would.
     *
     * The transaction repository memoizes lookups by (type, payment id) and the order repository memoizes orders, so
     * a second operation in the same PHP process would otherwise see the state from before the first one.
     *
     * @param ObjectManager $objectManager
     * @return void
     */
    protected function simulateNewRequest(ObjectManager $objectManager): void
    {
        $objectManager->removeSharedInstance(TransactionRepositoryInterface::class);
        $objectManager->removeSharedInstance(TransactionRepository::class);
        $objectManager->removeSharedInstance(TransactionManagerInterface::class);
        $objectManager->removeSharedInstance(TransactionManager::class);
        $objectManager->removeSharedInstance(OrderRepositoryInterface::class);
        $objectManager->removeSharedInstance(OrderRepository::class);
    }

    /**
     * Place the fixture cart with the given wallet payment data, as the storefront checkout posts it.
     *
     * @param ObjectManager $objectManager
     * @param array $additionalData
     * @return Order
     */
    protected function placeWalletOrder(
        ObjectManager $objectManager,
        array $additionalData,
    ): Order {
        $quote = $this->loadWalletQuote($objectManager);

        $orderId = $objectManager->create(CartManagementInterface::class)->placeOrder(
            (int)$quote->getId(),
            $this->buildWalletPayment($objectManager, $additionalData)
        );

        $this->simulateNewRequest($objectManager);

        return $this->loadWalletOrder($objectManager, (int)$orderId);
    }

    /**
     * Build checkout payment input for the wallet method.
     *
     * @param ObjectManager $objectManager
     * @param array $additionalData
     * @return PaymentInterface
     */
    protected function buildWalletPayment(
        ObjectManager $objectManager,
        array $additionalData,
    ): PaymentInterface {
        /** @var PaymentInterface $payment */
        $payment = $objectManager->create(PaymentInterface::class);
        $payment->setMethod(WalletType::METHOD_CODE);
        $payment->setAdditionalData($additionalData);

        return $payment;
    }

    /**
     * Google Pay checkout payment data, as the storefront client posts it.
     *
     * @return array
     */
    protected function googlePayData(): array
    {
        return [
            'wallet_type' => WalletType::GOOGLE_PAY,
            'wallet_token' => $this->googlePayToken(),
            'card_display' => 'Visa 1111',
        ];
    }

    /**
     * Apple Pay checkout payment data, as the storefront client posts it.
     *
     * @return array
     */
    protected function applePayData(): array
    {
        return [
            'wallet_type' => WalletType::APPLE_PAY,
            'wallet_token' => $this->applePayToken(),
            'card_display' => 'Visa 1111',
        ];
    }

    /**
     * Load the fixture cart fresh from the database.
     *
     * @param ObjectManager $objectManager
     * @return Quote
     */
    protected function loadWalletQuote(ObjectManager $objectManager): Quote
    {
        /** @var Quote $quote */
        $quote = $objectManager->create(QuoteCollectionFactory::class)
            ->create()
            ->addFieldToFilter('reserved_order_id', 'test_wallet_quote')
            ->setPageSize(1)
            ->getFirstItem();

        if (!$quote->getId()) {
            throw new LogicException('The wallet_quote fixture cart is missing.');
        }

        return $quote;
    }

    /**
     * Load the placed wallet order fresh from the database (never through the memoizing order repository).
     *
     * @param ObjectManager $objectManager
     * @param int|null $orderId
     * @return Order
     */
    protected function loadWalletOrder(
        ObjectManager $objectManager,
        ?int $orderId = null,
    ): Order {
        $collection = $objectManager->create(OrderCollectionFactory::class)->create();

        if ($orderId !== null) {
            $collection->addFieldToFilter('entity_id', $orderId);
        } else {
            $collection->addFieldToFilter('increment_id', 'test_wallet_quote');
        }

        /** @var Order $order */
        $order = $collection->setPageSize(1)->getFirstItem();

        if (!$order->getId()) {
            throw new LogicException('The wallet order was not placed.');
        }

        return $order;
    }

    /**
     * Load the order's payment transactions of the given type, fresh from the database.
     *
     * @param ObjectManager $objectManager
     * @param Order $order
     * @param string $txnType
     * @return TransactionCollection
     */
    protected function loadTransactions(
        ObjectManager $objectManager,
        Order $order,
        string $txnType,
    ): TransactionCollection {
        /** @var TransactionCollection $collection */
        $collection = $objectManager->create(TransactionCollectionFactory::class)->create();
        $collection->addOrderIdFilter((int)$order->getId());
        $collection->addTxnTypeFilter($txnType);

        return $collection;
    }

    /**
     * Return recorded createTransactionRequest bodies of the given Authorize.net transactionType, in order.
     *
     * @param string $transactionType
     * @return array<int, string>
     */
    protected function getTransactionRequests(string $transactionType): array
    {
        return array_values(
            array_filter(
                $this->clientStub->getRequestsOfType('createTransactionRequest'),
                fn (string $xml): bool => $this->readTag($xml, 'transactionType') === $transactionType
            )
        );
    }

    /**
     * Return the Authorize.net transactionType of every recorded createTransactionRequest, in order.
     *
     * @return array<int, string>
     */
    protected function getTransactionTypes(): array
    {
        return array_map(
            fn (string $xml): string => (string)$this->readTag($xml, 'transactionType'),
            $this->clientStub->getRequestsOfType('createTransactionRequest')
        );
    }

    /**
     * Read the first value of the given XML element from a request body.
     *
     * @param string $xml
     * @param string $tag
     * @return string|null
     */
    protected function readTag(
        string $xml,
        string $tag,
    ): ?string {
        if (preg_match('#<' . $tag . '>([^<]*)</' . $tag . '>#', $xml, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Default Authorize.net-like reply for a request.
     *
     * @param string $requestType
     * @param string $requestXml
     * @return string
     */
    protected function buildDefaultReply(
        string $requestType,
        string $requestXml,
    ): string {
        if ($requestType === 'updateHeldTransactionRequest') {
            $action = $this->readTag($requestXml, 'action');

            return $this->buildHeldTransactionReplyXml(
                $action === 'decline' ? 2 : 1,
                (string)$this->readTag($requestXml, 'refTransId')
            );
        }

        if ($requestType !== 'createTransactionRequest') {
            throw new LogicException(sprintf('Unexpected wallet API request: %s', $requestType));
        }

        $type = (string)$this->readTag($requestXml, 'transactionType');

        // Authorize.net answers prior-auth captures and voids with the referenced transaction's own ID.
        if (in_array($type, ['priorAuthCaptureTransaction', 'voidTransaction'], true)) {
            return $this->buildTransactionReplyXml((string)$this->readTag($requestXml, 'refTransId'));
        }

        return $this->buildTransactionReplyXml($this->nextTransactionId());
    }

    /**
     * Get a new unique gateway transaction ID.
     *
     * @return string
     */
    protected function nextTransactionId(): string
    {
        $this->txnSequence = (int)$this->txnSequence + 1;

        return (string)(8800000100 + $this->txnSequence);
    }

    /**
     * Build a createTransactionResponse. responseCode 1 = approved, 2 = declined, 4 = held for review.
     *
     * @param string $transId
     * @param int $responseCode
     * @return string
     */
    protected function buildTransactionReplyXml(
        string $transId,
        int $responseCode = 1,
    ): string {
        $descriptions = [
            1 => 'This transaction has been approved.',
            4 => 'This transaction is being held for review.',
        ];

        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<createTransactionResponse xmlns="AnetApi/xml/v1/schema/AnetApiSchema.xsd">'
            . '<messages><resultCode>Ok</resultCode>'
            . '<message><code>I00001</code><text>Successful.</text></message></messages>'
            . '<transactionResponse>'
            . '<responseCode>' . $responseCode . '</responseCode>'
            . '<authCode>WLT001</authCode>'
            . '<avsResultCode>Y</avsResultCode>'
            . '<cvvResultCode></cvvResultCode>'
            . '<cavvResultCode>2</cavvResultCode>'
            . '<transId>' . $transId . '</transId>'
            . '<refTransID></refTransID>'
            . '<transHash></transHash>'
            . '<testRequest>0</testRequest>'
            . '<accountNumber>XXXX1111</accountNumber>'
            . '<accountType>Visa</accountType>'
            . '<messages><message><code>1</code>'
            . '<description>' . ($descriptions[$responseCode] ?? 'Unknown.') . '</description>'
            . '</message></messages>'
            . '</transactionResponse>'
            . '</createTransactionResponse>';
    }

    /**
     * Build a failed createTransactionResponse (resultCode Error, E00027) with the given transaction error.
     *
     * @param int $responseCode
     * @param int $errorCode
     * @param string $errorText
     * @param string $refTransId
     * @return string
     */
    protected function buildTransactionErrorXml(
        int $responseCode,
        int $errorCode,
        string $errorText,
        string $refTransId = '',
    ): string {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<createTransactionResponse xmlns="AnetApi/xml/v1/schema/AnetApiSchema.xsd">'
            . '<messages><resultCode>Error</resultCode>'
            . '<message><code>E00027</code><text>The transaction was unsuccessful.</text></message></messages>'
            . '<transactionResponse>'
            . '<responseCode>' . $responseCode . '</responseCode>'
            . '<authCode></authCode>'
            . '<avsResultCode>P</avsResultCode>'
            . '<cvvResultCode></cvvResultCode>'
            . '<cavvResultCode></cavvResultCode>'
            . '<transId>0</transId>'
            . '<refTransID>' . $refTransId . '</refTransID>'
            . '<transHash></transHash>'
            . '<testRequest>0</testRequest>'
            . '<accountNumber>XXXX1111</accountNumber>'
            . '<accountType>Visa</accountType>'
            . '<errors><error><errorCode>' . $errorCode . '</errorCode>'
            . '<errorText>' . $errorText . '</errorText></error></errors>'
            . '</transactionResponse>'
            . '</createTransactionResponse>';
    }

    /**
     * Build an updateHeldTransactionResponse. The gateway treats responseCode 1 as approved, 2 as declined.
     *
     * @param int $responseCode
     * @param string $transId
     * @return string
     */
    protected function buildHeldTransactionReplyXml(
        int $responseCode,
        string $transId,
    ): string {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<updateHeldTransactionResponse xmlns="AnetApi/xml/v1/schema/AnetApiSchema.xsd">'
            . '<messages><resultCode>Ok</resultCode>'
            . '<message><code>I00001</code><text>Successful.</text></message></messages>'
            . '<transactionResponse>'
            . '<responseCode>' . $responseCode . '</responseCode>'
            . '<authCode>WLT001</authCode>'
            . '<transId>' . $transId . '</transId>'
            . '<refTransID>' . $transId . '</refTransID>'
            . '<accountNumber>XXXX1111</accountNumber>'
            . '<accountType>Visa</accountType>'
            . '<messages><message><code>1</code><description>Updated.</description></message></messages>'
            . '</transactionResponse>'
            . '</updateHeldTransactionResponse>';
    }
}
