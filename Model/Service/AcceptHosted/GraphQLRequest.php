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

namespace ParadoxLabs\Authnetcim\Model\Service\AcceptHosted;

use Magento\Framework\Exception\LocalizedException;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\ResourceModel\Quote\Payment;
use ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider as ConfigProviderAch;
use ParadoxLabs\Authnetcim\Model\ConfigProvider as ConfigProviderCc;
use ParadoxLabs\Authnetcim\Model\Gateway;
use ParadoxLabs\TokenBase\Model\Api\GraphQL;

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
     * @param Payment $paymentResource
     */
    public function __construct(
        Context $context,
        protected readonly RemoteAddress $remoteAddress,
        protected readonly CustomerRepositoryInterface $customerRepository,
        protected readonly GraphQL $graphQL,
        protected readonly Payment $paymentResource
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
        $payment    = $this->getQuote()->getPayment();
        $email      = $this->getEmail();
        $customerId = $this->getCustomerId();

        if ($payment->hasAdditionalInformation('profile_id')
            && $payment->getAdditionalInformation('email') === $email
            && $payment->getAdditionalInformation('merchantCustomerId') === $customerId) {
            return $payment->getAdditionalInformation('profile_id');
        }

        // Otherwise, look for a profile ID (iframe Session ID) in the input
        $this->validateAndSetProfileId();

        if ($this->profileId !== null) {
            return (string)$this->profileId;
        }

        // Otherwise, create a new profile
        /** @var Gateway $gateway */
        $gateway = $this->getMethod()->gateway();

        $gateway->setParameter('email', $email);
        $gateway->setParameter('merchantCustomerId', $customerId);
        $gateway->setParameter('description', 'Magento ' . date('c'));

        $profileId = $gateway->createCustomerProfile();

        // Store the profile ID on the payment record.
        $payment->setAdditionalInformation('profile_id', $profileId);
        $payment->setAdditionalInformation('email', $email);
        $payment->setAdditionalInformation('merchantCustomerId', $customerId);
        $this->paymentResource->save($payment);

        return (string)$profileId;
    }

    /**
     * Get customer email for the current session/context.
     *
     * @return string|null
     */
    public function getEmail(): ?string
    {
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
     * Set billing address parameters on the Gateway
     *
     * @param Gateway $gateway
     * @return void
     * @throws LocalizedException
     */
    protected function setBillingParams(Gateway $gateway): void
    {
        $billing = $this->getQuote()->getBillingAddress();
        if ($billing instanceof AddressInterface) {
            $gateway->setBillTo($billing->getDataModel());
        }
    }
}
