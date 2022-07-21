<?php
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

namespace ParadoxLabs\Authnetcim\Model\Service\Hosted;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;

/**
 * GraphQLRequest Class
 */
class GraphQLRequest extends AbstractRequestHandler
{
    /**
     * @var \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress
     */
    protected $remoteAddress;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var \Magento\GraphQl\Model\Query\Resolver\Context
     */
    protected $graphQlContext;

    /**
     * @var array
     */
    protected $graphQlArgs;

    /**
     * @var \Magento\Customer\Api\CustomerRepositoryInterface
     */
    protected $customerRepository;

    /**
     * @var \Magento\Quote\Api\Data\CartInterface
     */
    protected $quote;

    /**
     * @var \ParadoxLabs\TokenBase\Model\Api\GraphQL
     */
    protected $graphQL;

    /**
     * @var string
     */
    protected $profileId;

    /**
     * GraphQLRequest constructor.
     *
     * @param \Magento\Framework\UrlInterface $urlBuilder
     * @param \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
     * @param \ParadoxLabs\TokenBase\Model\Card\Factory $cardFactory
     * @param \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository
     * @param \ParadoxLabs\Authnetcim\Helper\Data $helper
     * @param \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress $remoteAddress
     * @param \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository
     * @param \ParadoxLabs\TokenBase\Model\Api\GraphQL $graphQL
     */
    public function __construct(
        \Magento\Framework\UrlInterface $urlBuilder,
        \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory,
        \ParadoxLabs\TokenBase\Model\Card\Factory $cardFactory,
        \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository,
        \ParadoxLabs\Authnetcim\Helper\Data $helper,
        \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress $remoteAddress,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository,
        \ParadoxLabs\TokenBase\Model\Api\GraphQL $graphQL
    ) {
        parent::__construct($urlBuilder, $methodFactory, $cardFactory, $cardRepository, $helper);

        $this->remoteAddress = $remoteAddress;
        $this->customerRepository = $customerRepository;
        $this->graphQL = $graphQL;
    }

    /**
     * @param \Magento\Framework\GraphQl\Query\Resolver\ContextInterface $context
     * @param array $args
     * @return void
     */
    public function setGraphQLContext(ContextInterface $context, array $args)
    {
        $this->graphQlContext = $context;
        $this->graphQlArgs = $args;
    }

    /**
     * @param \ParadoxLabs\Authnetcim\Model\Gateway $gateway
     * @return string
     */
    public function getCustomerProfileId(): string
    {
        // If we were given a card ID, get the profile ID from that
        if ($this->graphQlArgs['source'] === 'paymentinfo') {
            $card = $this->getCardModel();

            if ($card instanceof \ParadoxLabs\TokenBase\Api\Data\CardInterface) {
                return (string)$card->getProfileId();
            }
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
     * @return string|null
     */
    public function getCustomerPaymentId(): ?string
    {
        if ($this->graphQlArgs['source'] === 'paymentinfo') {
            $card = $this->getCardModel();

            // If we were given a card ID, get the profile ID from that instead of creating new
            if ($card instanceof \ParadoxLabs\TokenBase\Api\Data\CardInterface) {
                return (string)$card->getPaymentId();
            }
        }

        return null;
    }

    /**
     * Get customer email for the Secure Acceptance request.
     *
     * @return string|null
     */
    protected function getEmail()
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
     * Get customer ID for the Secure Acceptance request.
     *
     * @return int|null
     */
    protected function getCustomerId()
    {
        return $this->graphQlContext->getUserId();
    }

    /**
     * Get quote for the GraphQL request
     *
     * @return \Magento\Quote\Api\Data\CartInterface
     */
    protected function getQuote()
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
     * Get the stored card from the request's card_id card hash, or null if none.
     *
     * @return \ParadoxLabs\TokenBase\Api\Data\CardInterface|null
     */
    protected function getCardModel()
    {
        if (empty($this->graphQlArgs['cardId'])) {
            return null;
        }

        try {
            $card = $this->cardRepository->getByHash($this->graphQlArgs['cardId']);

            if ($card->hasOwner((int)$this->getCustomerId()) === false) {
                throw new \Magento\Framework\Exception\LocalizedException(__('Could not load payment profile'));
            }

            return $card;
        } catch (\Exception $exception) {
            return null;
        }
    }

    /**
     * Get the current store ID, for config scoping.
     *
     * @return string
     */
    protected function getStoreId()
    {
        return $this->storeManager->getStore()->getId();
    }

    /**
     * Get the active payment method code.
     *
     * @return string
     */
    protected function getMethodCode(): string
    {
        return $this->graphQlArgs['method'];
    }

    /**
     * @return string
     */
    protected function getTokenbaseCardId(): string
    {
        return (string)($this->graphQlArgs['cardId'] ?? '');
    }

    /**
     * @param \ParadoxLabs\TokenBase\Api\Data\CardInterface $card
     * @return void
     */
    protected function saveCardToQuote(\ParadoxLabs\TokenBase\Api\Data\CardInterface $card): void
    {
        $payment = $this->getQuote()->getPayment();
        $method->assignData(new \Magento\Framework\DataObject(['card_id' => $card->getHash()]));

        $this->paymentResource->save($payment);
    }

    /**
     * If we're given a customer profile ID, make sure the user is authorized (info matches the profile).
     *
     * @return void
     */
    protected function validateAndSetProfileId(): void
    {
        $profileId = $this->graphQlArgs['iframeSessionId'];

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
}
