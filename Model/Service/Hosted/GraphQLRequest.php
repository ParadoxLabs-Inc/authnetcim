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

namespace ParadoxLabs\Authnetcim\Model\Service\Hosted;

use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use ParadoxLabs\TokenBase\Api\Data\CardInterface;

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
     * @var \Magento\Quote\Model\ResourceModel\Quote\Payment
     */
    protected $paymentResource;

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
     * @param \Magento\Quote\Model\ResourceModel\Quote\Payment $paymentResource
     */
    public function __construct(
        \Magento\Framework\UrlInterface $urlBuilder,
        \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory,
        \ParadoxLabs\TokenBase\Model\Card\Factory $cardFactory,
        \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository,
        \ParadoxLabs\Authnetcim\Helper\Data $helper,
        \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress $remoteAddress,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository,
        \ParadoxLabs\TokenBase\Model\Api\GraphQL $graphQL,
        \Magento\Quote\Model\ResourceModel\Quote\Payment $paymentResource
    ) {
        parent::__construct($urlBuilder, $methodFactory, $cardFactory, $cardRepository, $helper);

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
        if ($this->graphQlArgs['source'] === 'paymentinfo') {
            $card = $this->getCardModel();

            if ($card instanceof CardInterface) {
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
     * Get the CIM payment ID for the current session/context.
     *
     * @return string|null
     * @throws \Magento\Framework\Exception\LocalizedException
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
        } catch (\Exception $exception) {
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
        return (int)$this->storeManager->getStore()->getId();
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
        $method  = $payment->getMethodInstance();
        $method->assignData(new \Magento\Framework\DataObject(['card_id' => $card->getHash()]));

        $this->paymentResource->save($payment);
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
