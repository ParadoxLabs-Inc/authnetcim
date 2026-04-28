<?php declare(strict_types=1);
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

namespace ParadoxLabs\Authnetcim\Model\Service\AcceptCustomer;

use Magento\Framework\Exception\LocalizedException;
use ParadoxLabs\Authnetcim\Model\Gateway;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\DataObject;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Quote\Model\ResourceModel\Quote\Payment;
use ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider as ConfigProviderAch;
use ParadoxLabs\Authnetcim\Model\ConfigProvider as ConfigProviderCc;
use ParadoxLabs\TokenBase\Api\Data\CardInterface;
use ParadoxLabs\TokenBase\Model\Api\GraphQL;
use Throwable;

class GraphQLRequest extends AbstractRequestHandler
{
    /**
     * @var \Magento\GraphQl\Model\Query\Resolver\Context
     */
    protected $graphQlContext;

    /**
     * @var array
     */
    protected $graphQlArgs;

    /**
     * @var CartInterface
     */
    protected $quote;

    /**
     * @var string
     */
    protected $profileId;

    /**
     * GraphQLRequest constructor.
     *
     * @param Context $context
     * @param RemoteAddress $remoteAddress
     * @param CustomerRepositoryInterface $customerRepository
     * @param GraphQL $graphQL
     * @param \Magento\Quote\Model\ResourceModel\Quote\Payment $paymentResource
     */
    public function __construct(
        Context $context,
        protected readonly RemoteAddress $remoteAddress,
        protected readonly CustomerRepositoryInterface $customerRepository,
        protected readonly GraphQL $graphQL,
        protected readonly \Magento\Quote\Model\ResourceModel\Quote\Payment $paymentResource
    ) {
        parent::__construct($context);
    }

    /**
     * Set GraphQL request info/args on the object
     *
     * @param ContextInterface $context
     * @param array $args
     * @return void
     */
    public function setGraphQLContext(ContextInterface $context, array $args): void
    {
        $this->graphQlContext = $context;
        $this->graphQlArgs    = $args;
    }

    /**
     * Get the CIM customer profile ID for the current session/context.
     *
     * @return string
     * @throws LocalizedException
     */
    public function getCustomerProfileId(): string
    {
        // If we were given a card ID, get the profile ID from that
        if ($this->graphQlArgs['source'] === 'paymentinfo') {
            $card = $this->getCardModel();

            if ($card instanceof CardInterface) {
                return (string)$card->getProfileId();
            }
        } else {
            $payment = $this->getQuote()->getPayment();

            if ($payment->hasAdditionalInformation('profile_id')) {
                return $payment->getAdditionalInformation('profile_id');
            }
        }

        // Otherwise, look for a profile ID (iframe Session ID) in the input
        $this->validateAndSetProfileId();

        if ($this->profileId !== null) {
            return (string)$this->profileId;
        }

        // Otherwise, create a new profile
        /** @var Gateway $gateway */
        $gateway = $this->getMethod()->gateway();

        $gateway->setParameter('email', $this->getEmail());
        $gateway->setParameter('merchantCustomerId', $this->getCustomerId());
        $gateway->setParameter('description', 'Magento ' . date('c'));

        $profileId = $gateway->createCustomerProfile();

        // If this is a checkout session, store the profile ID on the payment record.
        if ($this->graphQlArgs['source'] !== 'paymentinfo' && $payment instanceof QuotePayment) {
            $payment = $this->getQuote()->getPayment();
            $payment->setAdditionalInformation('profile_id', $profileId);
            $this->paymentResource->save($payment);
        }

        return (string)$profileId;
    }

    /**
     * Get the CIM payment ID for the current session/context.
     *
     * @return string|null
     */
    public function getCustomerPaymentId(): ?string
    {
        if ($this->graphQlArgs['source'] === 'paymentinfo') {
            $card = $this->getCardModel();

            // If we were given a card ID, get the profile ID from that instead of creating new
            if ($card instanceof CardInterface) {
                return (string)$card->getPaymentId();
            }
        }

        return null;
    }

    /**
     * Get customer email for the current session/context.
     *
     * @return string|null
     */
    public function getEmail(): ?string
    {
        if ($this->graphQlArgs['source'] === 'paymentinfo') {
            $customer = $this->customerRepository->getById(
                $this->graphQlContext->getUserId()
            );

            return $customer->getEmail();
        }

        if (!empty($this->getQuote()->getBillingAddress()->getEmail())) {
            return $this->getQuote()->getBillingAddress()->getEmail();
        }

        // Fall back to guest email parameter iff there's none on the quote.
        return $this->graphQlArgs['guestEmail'] ?? null;
    }

    /**
     * Get customer ID for the current session/context.
     *
     * @return string|null
     */
    public function getCustomerId(): ?string
    {
        return (string)$this->graphQlContext->getUserId();
    }

    /**
     * Get quote for the GraphQL request
     *
     * @return CartInterface
     */
    protected function getQuote(): CartInterface
    {
        if ($this->quote instanceof CartInterface) {
            return $this->quote;
        }

        $customerId = $this->graphQlContext->getUserId();
        $quoteHash  = $this->graphQlArgs['cartId'];

        $this->quote = $this->graphQL->getQuote($customerId, $quoteHash);

        return $this->quote;
    }

    /**
     * Get the stored card from the request's card_id card hash, or null if none.
     *
     * @return CardInterface|null
     */
    protected function getCardModel(): ?CardInterface
    {
        if (empty($this->graphQlArgs['cardId'])) {
            return null;
        }

        try {
            $card = $this->cardRepository->getByHash($this->graphQlArgs['cardId']);

            if ($card->hasOwner((int)$this->getCustomerId()) === false) {
                return null;
            }

            return $card;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Get the current store ID, for config loading.
     *
     * @return int
     */
    protected function getStoreId(): int
    {
        return (int)$this->graphQlContext->getExtensionAttributes()->getStore()->getId();
    }

    /**
     * Get the active payment method code.
     *
     * @return string
     */
    protected function getMethodCode(): string
    {
        $methodCode = $this->methodCode ?? $this->graphQlArgs['method'];

        if (in_array($methodCode, [ConfigProviderCc::CODE, ConfigProviderAch::CODE], true)) {
            return $methodCode;
        }

        return ConfigProviderCc::CODE;
    }

    /**
     * Get the tokenbase card hash for the current session/context.
     *
     * @return string
     */
    protected function getTokenbaseCardId(): string
    {
        return (string)($this->graphQlArgs['cardId'] ?? '');
    }

    /**
     * Save the given card to the active quote as the active payment method.
     *
     * @param CardInterface $card
     * @return void
     */
    protected function saveCardToQuote(CardInterface $card): void
    {
        if ($this->graphQlArgs['source'] === 'paymentinfo') {
            return;
        }

        $payment = $this->getQuote()->getPayment();
        $payment->setMethod($this->getMethodCode());
        $method = $payment->getMethodInstance();
        $method->assignData(new DataObject(['card_id' => $card->getHash()]));

        $this->paymentResource->save($payment);
    }

    /**
     * If we're given a customer profile ID, make sure the user is authorized (info matches the profile).
     *
     * @return void
     * @throws GraphQlNoSuchEntityException
     * @throws GraphQlAuthorizationException
     */
    protected function validateAndSetProfileId(): void
    {
        $profileId = $this->graphQlArgs['iframeSessionId'] ?? null;

        if (empty($profileId)) {
            return;
        }

        /** @var Gateway $gateway */
        $gateway = $this->getMethod()->gateway();
        $gateway->setParameter('customerProfileId', (string)$profileId);

        $response = $gateway->getCustomerProfile();

        if (!empty($response['messages']['message']['text'])
            && $response['messages']['message']['text'] !== 'Successful.') {
            throw new GraphQlNoSuchEntityException(
                __($response['messages']['message']['text'])
            );
        }

        if ($response['profile']['email'] === $this->getEmail()) {
            $this->profileId = $response['profile']['customerProfileId'];
        } else {
            throw new GraphQlAuthorizationException(
                __('Invalid iframeSessionId')
            );
        }
    }

    /**
     * Clear the stored profile ID for the current session/context.
     *
     * @return void
     */
    protected function clearProfileId(): void
    {
        $this->profileId = null;

        if ($this->graphQlArgs['source'] === 'paymentinfo') {
            return;
        }

        $payment = $this->getQuote()->getPayment();
        $payment->unsAdditionalInformation('profile_id');
    }
}
