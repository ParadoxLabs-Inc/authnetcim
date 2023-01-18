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

namespace ParadoxLabs\Authnetcim\Model\Service\AcceptHosted;

use ParadoxLabs\TokenBase\Api\Data\CardInterface;

abstract class AbstractRequestHandler
{
    public const HOSTED_ENDPOINTS = [
        'live'    => 'https://accept.authorize.net/',
        'sandbox' => 'https://test.authorize.net/',
    ];

    /**
     * @var \Magento\Framework\Url
     */
    protected $urlBuilder;

    /**
     * @var \ParadoxLabs\TokenBase\Model\Method\Factory
     */
    protected $methodFactory;

    /**
     * @var \ParadoxLabs\TokenBase\Api\Data\CardInterfaceFactory
     */
    protected $cardFactory;

    /**
     * @var \ParadoxLabs\TokenBase\Api\CardRepositoryInterface
     */
    protected $cardRepository;

    /**
     * @var \ParadoxLabs\Authnetcim\Helper\Data
     */
    protected $helper;

    /**
     * @var \ParadoxLabs\Authnetcim\Model\Method
     */
    protected $method;

    /**
     * AbstractRequestHandler constructor.
     *
     * @param \ParadoxLabs\Authnetcim\Model\Service\AcceptCustomer\Context $context
     */
    public function __construct(
        Context $context
    ) {
        $this->urlBuilder = $context->getUrlBuilder();
        $this->methodFactory = $context->getMethodFactory();
        $this->cardFactory = $context->getCardFactory();
        $this->cardRepository = $context->getCardRepository();
        $this->helper = $context->getHelper();
    }

    /**
     * Get the payment method instance
     *
     * @return \ParadoxLabs\Authnetcim\Model\Method
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getMethod(): \ParadoxLabs\Authnetcim\Model\Method
    {
        if ($this->method === null) {
            $methodCode = $this->getMethodCode();

            /** @var \ParadoxLabs\Authnetcim\Model\Method $method */
            $this->method = $this->methodFactory->getMethodInstance($methodCode);
            $this->method->setStore($this->getStoreId());
        }

        return $this->method;
    }

    /**
     * Get hosted form request parameters and URL
     *
     * @return array
     */
    public function getParams(): array
    {
        $action = 'payment/payment';
        $params = [
            'token' => $this->getToken(),
        ];

        return [
            'iframeAction' => $this->getEndpoint() . $action,
            'iframeParams' => $params,
        ];
    }

    /**
     * Get Authorize.Net Accept Hosted API endpoint URL for the current configuration.
     *
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getEndpoint(): string
    {
        if ((bool)$this->getMethod()->getConfigData('test') === true) {
            return static::HOSTED_ENDPOINTS['sandbox'];
        }

        return static::HOSTED_ENDPOINTS['live'];
    }

    /**
     * Get hosted profile page request token
     *
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\StateException
     */
    public function getToken(): string
    {
        $method = $this->getMethod();

        /** @var \ParadoxLabs\Authnetcim\Model\Gateway $gateway */
        $gateway = $method->gateway();

        // Get CC form token
        $communicatorUrl = $this->urlBuilder->getUrl('authnetcim/hosted/communicator');
        $gateway->setParameter('hostedProfileIFrameCommunicatorUrl', $communicatorUrl);
        $gateway->setParameter('hostedProfileHeadingBgColor', $method->getConfigData('accent_color'));
        $gateway->setParameter('customerProfileId', $this->getCustomerProfileId());

        // TODO: Payment form params

        $response = $gateway->getHostedPaymentPage();

        if (!empty($response['messages']['message']['text'])
            && $response['messages']['message']['text'] !== 'Successful.') {
            throw new \Magento\Framework\Exception\InputException(__($response['messages']['message']['text']));
        }

        if (empty($response['token'])) {
            throw new \Magento\Framework\Exception\StateException(__('Unable to initialize payment form.'));
        }

        return $response['token'];
    }

    /**
     * Sync the new/edited card from CIM to Magento.
     *
     * @return CardInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getCard(): CardInterface
    {
        $cardId = $this->getTokenbaseCardId();

        if (empty($cardId)) {
            // Find the newest card on the CIM profile and import it
            $newestCardProfile = $this->fetchAddedCard();
            $card              = $this->importPaymentProfile($newestCardProfile);

            $this->saveCardToQuote($card);
        } else {
            // Card already exists; refresh the payment info from the CIM profile
            $card = $this->cardRepository->getByHash($cardId);

            if ($card->hasOwner((int)$this->helper->getCurrentCustomer()->getId()) === false) {
                throw new \Magento\Framework\Exception\LocalizedException(__('Could not load payment profile'));
            }

            $card = $card->getTypeInstance();
            $card->setData('no_sync', true);

            $this->updateCardFromPaymentProfile($card);
        }

        return $card;
    }

    /**
     * Get the most recent added card from the current CIM profile.
     *
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Payment\Gateway\Command\CommandException
     */
    public function fetchAddedCard(): array
    {
        // Get CIM profile ID
        $profileId  = $this->getCustomerProfileId();

        /** @var \ParadoxLabs\Authnetcim\Model\Gateway $gateway */
        $gateway = $this->getMethod()->gateway();

        // Get CIM profile cards
        $gateway->setParameter('customerProfileId', $profileId);
        $gateway->setParameter('unmaskExpirationDate', 'true');
        $gateway->setParameter('includeIssuerInfo', 'true');

        $response = $gateway->getCustomerProfile();

        if (!empty($response['messages']['message']['text'])
            && $response['messages']['message']['text'] !== 'Successful.') {
            throw new \Magento\Framework\Exception\LocalizedException(__($response['messages']['message']['text']));
        }

        if (!isset($response['profile']['paymentProfiles'])) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Unable to find payment record.'));
        }

        $newestCard = $response['profile']['paymentProfiles'] ?? [];
        if (!isset($response['profile']['paymentProfiles']['customerPaymentProfileId'])) {
            $paymentProfiles = [];
            foreach ($response['profile']['paymentProfiles'] as $paymentProfile) {
                $paymentProfiles[ $paymentProfile['customerPaymentProfileId'] ] = $paymentProfile;
            }

            ksort($paymentProfiles);
            $newestCard = end($paymentProfiles);
        }

        return $newestCard;
    }

    /**
     * Set data from a CIM payment profile onto the given TokenBase Card
     *
     * @param array $paymentProfile
     * @return CardInterface
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function importPaymentProfile(array $paymentProfile): CardInterface
    {
        /** @var \ParadoxLabs\Authnetcim\Model\Card $card */
        $card = $this->cardFactory->create();
        $card->setMethod($this->getMethodCode());
        $card->setCustomerId($this->getCustomerId());
        $card->setCustomerEmail($this->getEmail());
        $card->setActive(true);
        $card->setProfileId($this->getCustomerProfileId());
        $card->setPaymentId($paymentProfile['customerPaymentProfileId']);

        $card = $card->getTypeInstance();
        $card->setData('no_sync', true);

        $this->setPaymentProfileDataOnCard($paymentProfile, $card);

        $this->cardRepository->save($card);

        $this->helper->log(
            $this->getMethodCode(),
            sprintf(
                "Imported card %s (ID %s) from CIM (profile_id '%s', payment_id '%s')",
                $card->getLabel(),
                $card->getId(),
                $card->getProfileId(),
                $card->getPaymentId()
            )
        );

        return $card;
    }

    /**
     * Update the given TokenBase Card from its source data in Authorize.net CIM.
     *
     * @param CardInterface $card
     * @return CardInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Payment\Gateway\Command\CommandException
     */
    public function updateCardFromPaymentProfile(CardInterface $card): CardInterface
    {
        /** @var \ParadoxLabs\Authnetcim\Model\Gateway $gateway */
        $gateway = $this->getMethod()->gateway();

        // Get CIM payment profile
        $gateway->setParameter('customerProfileId', $card->getProfileId());
        $gateway->setParameter('customerPaymentProfileId', $card->getPaymentId());
        $gateway->setParameter('unmaskExpirationDate', 'true');
        $gateway->setParameter('includeIssuerInfo', 'true');

        $response = $gateway->getCustomerPaymentProfile();

        if (!empty($response['messages']['message']['text'])
            && $response['messages']['message']['text'] !== 'Successful.') {
            throw new \Magento\Framework\Exception\LocalizedException(__($response['messages']['message']['text']));
        }

        $paymentProfile = $response['paymentProfile'];

        $this->setPaymentProfileDataOnCard($paymentProfile, $card);
        $this->cardRepository->save($card);

        $this->helper->log(
            $this->getMethodCode(),
            sprintf(
                "Updated card %s (ID %s) from CIM (profile_id '%s', payment_id '%s')",
                $card->getLabel(),
                $card->getId(),
                $paymentProfile['customerProfileId'],
                $paymentProfile['customerPaymentProfileId']
            )
        );

        return $card;
    }

    /**
     * Set credit card metadata from a payment profile onto a Card.
     *
     * @param array $paymentProfile
     * @param CardInterface $card
     * @return CardInterface
     */
    public function setPaymentProfileDataOnCard(array $paymentProfile, CardInterface $card): CardInterface
    {
        $paymentData = [];

        if (isset($paymentProfile['payment']['creditCard'])) {
            $creditCard = $paymentProfile['payment']['creditCard'];
            [$yr, $mo]  = explode('-', (string)$creditCard['expirationDate'], 2);
            $day        = date('t', strtotime($yr . '-' . $mo));
            $type       = $this->helper->mapCcTypeToMagento($creditCard['cardType']);

            $paymentData = [
                'cc_type' => $type,
                'cc_last4' => substr((string)$creditCard['cardNumber'], -4),
                'cc_exp_year' => $yr,
                'cc_exp_month' => $mo,
                'cc_bin' => $creditCard['issuerNumber'],
            ];

            $card->setData('expires', sprintf('%s-%s-%s 23:59:59', $yr, $mo, $day));
        } elseif (isset($paymentProfile['payment']['bankAccount'])) {
            $bankAccount = $paymentProfile['payment']['bankAccount'];
            $paymentData = [
                'echeck_account_type' => $bankAccount['accountType'],
                'echeck_account_name' => $bankAccount['nameOnAccount'],
                'echeck_bank_name' => $bankAccount['bankName'],
                'echeck_routing_number_last4' => substr((string)$bankAccount['routingNumber'], -4),
                'echeck_account_number_last4' => substr((string)$bankAccount['accountNumber'], -4),
                'cc_last4' => substr((string)$bankAccount['accountNumber'], -4),
            ];
        }

        $paymentData += (array)$card->getAdditional();
        $card->setData('additional', json_encode($paymentData));

        return $card;
    }

    /**
     * Get the CIM customer profile ID for the current session/context.
     *
     * @return string
     */
    abstract public function getCustomerProfileId(): string;

    /**
     * Get the CIM payment ID for the current session/context.
     *
     * @return string|null
     */
    abstract public function getCustomerPaymentId(): ?string;

    /**
     * Get customer email for the current session/context.
     *
     * @return string|null
     */
    abstract public function getEmail(): ?string;

    /**
     * Get customer ID for the current session/context.
     *
     * @return string|null
     */
    abstract public function getCustomerId(): ?string;

    /**
     * Get the current store ID, for config loading.
     *
     * @return int
     */
    abstract protected function getStoreId(): int;

    /**
     * Get the active payment method code.
     *
     * @return string
     */
    abstract protected function getMethodCode(): string;

    /**
     * Get the tokenbase card hash for the current session/context.
     *
     * @return string
     */
    abstract protected function getTokenbaseCardId(): string;

    /**
     * Save the given card to the active quote as the active payment method.
     *
     * @param CardInterface $card
     * @return void
     */
    abstract protected function saveCardToQuote(CardInterface $card): void;
}
