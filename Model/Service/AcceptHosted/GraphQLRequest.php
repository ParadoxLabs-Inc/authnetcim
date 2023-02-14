<?php declare(strict_types=1);
/**
 * Paradox Labs, Inc.
 * http://www.paradoxlabs.com
 * 717-431-3330
 *
 * Need help? Open a ticket in our support system:
 *  http://support.paradoxlabs.com
 *
 * @author      Ryan Hoerr <info@paradoxlabs.com>
 * @license     http://store.paradoxlabs.com/license.html
 */

namespace ParadoxLabs\Authnetcim\Model\Service\AcceptHosted;

use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Quote\Api\Data\AddressInterface;
use ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider as ConfigProviderAch;
use ParadoxLabs\Authnetcim\Model\ConfigProvider as ConfigProviderCc;
use ParadoxLabs\TokenBase\Api\Data\CardInterface;

class GraphQLRequest extends AbstractRequestHandler
{
    /**
     * @var \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress
     */
    protected $remoteAddress;

    /**
     * @var \Magento\Customer\Api\CustomerRepositoryInterface
     */
    protected $customerRepository;

    /**
     * @var \ParadoxLabs\TokenBase\Model\Api\GraphQL
     */
    protected $graphQL;

    /**
     * @var \Magento\Quote\Model\ResourceModel\Quote\Payment
     */
    protected $paymentResource;

    /**
     * @var \Magento\GraphQl\Model\Query\Resolver\Context
     */
    protected $graphQlContext;

    /**
     * @var array
     */
    protected $graphQlArgs;

    /**
     * @var \Magento\Quote\Api\Data\CartInterface
     */
    protected $quote;

    /**
     * @var string
     */
    protected $profileId;

    /**
     * GraphQLRequest constructor.
     *
     * @param \ParadoxLabs\Authnetcim\Model\Service\AcceptHosted\Context $context
     * @param \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress $remoteAddress
     * @param \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository
     * @param \ParadoxLabs\TokenBase\Model\Api\GraphQL $graphQL
     * @param \Magento\Quote\Model\ResourceModel\Quote\Payment $paymentResource
     */
    public function __construct(
        Context $context,
        \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress $remoteAddress,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository,
        \ParadoxLabs\TokenBase\Model\Api\GraphQL $graphQL,
        \Magento\Quote\Model\ResourceModel\Quote\Payment $paymentResource
    ) {
        parent::__construct($context);

        $this->remoteAddress = $remoteAddress;
        $this->customerRepository = $customerRepository;
        $this->graphQL = $graphQL;
        $this->paymentResource = $paymentResource;
    }

    /**
     * Set GraphQL request info/args on the object
     *
     * @param \Magento\Framework\GraphQl\Query\Resolver\ContextInterface $context
     * @param array $args
     * @return void
     */
    public function setGraphQLContext(ContextInterface $context, array $args): void
    {
        $this->graphQlContext = $context;
        $this->graphQlArgs = $args;
    }

    /**
     * Get the CIM customer profile ID for the current session/context.
     *
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getCustomerProfileId(): string
    {
        // If we were given a card ID, get the profile ID from that
        $payment = $this->getQuote()->getPayment();
        if ($payment->hasAdditionalInformation('profile_id')) {
            return $payment->getAdditionalInformation('profile_id');
        }

        // Otherwise, look for a profile ID (iframe Session ID) in the input
        $this->validateAndSetProfileId();

        if ($this->profileId !== null) {
            return (string)$this->profileId;
        }

        // Otherwise, create a new profile
        /** @var \ParadoxLabs\Authnetcim\Model\Gateway $gateway */
        $gateway = $this->getMethod()->gateway();

        $gateway->setParameter('email', $this->getEmail());
        $gateway->setParameter('merchantCustomerId', $this->getCustomerId());
        $gateway->setParameter('description', 'Magento ' . date('c'));

        $profileId = $gateway->createCustomerProfile();

        // If this is a checkout session, store the profile ID on the payment record.
        if ($this->graphQlArgs['source'] !== 'paymentinfo') {
            $payment = $this->getQuote()->getPayment();
            $payment->setAdditionalInformation('profile_id', $profileId);
            $this->paymentResource->save($payment);
        }

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
     * @return \Magento\Quote\Api\Data\CartInterface
     */
    protected function getQuote(): \Magento\Quote\Api\Data\CartInterface
    {
        if ($this->quote instanceof \Magento\Quote\Api\Data\CartInterface) {
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
        $methodCode = $this->graphQlArgs['method'];

        if (in_array($methodCode, [ConfigProviderCc::CODE, ConfigProviderAch::CODE], true)) {
            return $methodCode;
        }

        return ConfigProviderCc::CODE;
    }

    /**
     * If we're given a customer profile ID, make sure the user is authorized (info matches the profile).
     *
     * @return void
     * @throws \Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException
     * @throws \Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException
     */
    protected function validateAndSetProfileId(): void
    {
        $profileId = $this->graphQlArgs['iframeSessionId'] ?? null;

        if (empty($profileId)) {
            return;
        }

        /** @var \ParadoxLabs\Authnetcim\Model\Gateway $gateway */
        $gateway = $this->getMethod()->gateway();
        $gateway->setParameter('customerProfileId', (string)$profileId);

        $response = $gateway->getCustomerProfile();

        if (!empty($response['messages']['message']['text'])
            && $response['messages']['message']['text'] !== 'Successful.') {
            throw new \Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException(
                __($response['messages']['message']['text'])
            );
        }

        if ($response['profile']['email'] === $this->getEmail()) {
            $this->profileId = $response['profile']['customerProfileId'];
        } else {
            throw new \Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException(
                __('Invalid iframeSessionId')
            );
        }
    }

    /**
     * Set billing address parameters on the Gateway
     *
     * @param \ParadoxLabs\Authnetcim\Model\Gateway $gateway
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function setBillingParams(\ParadoxLabs\Authnetcim\Model\Gateway $gateway): void
    {
        $billing = $this->getQuote()->getBillingAddress();
        if ($billing instanceof AddressInterface) {
            $gateway->setBillTo($billing->getDataModel());
        }
    }
}
