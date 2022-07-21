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

use ParadoxLabs\TokenBase\Api\Data\CardInterface;

/**
 * AbstractRequestHandler Class
 */
abstract class AbstractRequestHandler
{
    public const HOSTED_ENDPOINTS = [
        'live'    => 'https://accept.authorize.net/',
        'sandbox' => 'https://test.authorize.net/',
    ];

    /**
     * @var \Magento\Framework\UrlInterface
     */
    protected $urlBuilder;

    /**
     * @var \ParadoxLabs\TokenBase\Model\Method\Factory
     */
    protected $methodFactory;

    /**
     * @var \ParadoxLabs\TokenBase\Api\MethodInterface
     */
    protected $method;

    /**
     * @var \ParadoxLabs\TokenBase\Model\Card\Factory
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
     * AbstractRequestHandler constructor.
     *
     * @param \Magento\Framework\UrlInterface $urlBuilder
     * @param \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
     * @param \ParadoxLabs\TokenBase\Model\Card\Factory $cardFactory
     * @param \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository
     * @param \ParadoxLabs\Authnetcim\Helper\Data $helper
     */
    public function __construct(
        \Magento\Framework\UrlInterface $urlBuilder,
        \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory,
        \ParadoxLabs\TokenBase\Model\Card\Factory $cardFactory,
        \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository,
        \ParadoxLabs\Authnetcim\Helper\Data $helper
    ) {
        $this->urlBuilder = $urlBuilder;
        $this->methodFactory = $methodFactory;
        $this->cardFactory = $cardFactory;
        $this->cardRepository = $cardRepository;
        $this->helper = $helper;
    }

    /**
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
     * @return array
     */
    public function getParams(): array
    {
        $action = 'customer/addPayment';
        $params = [
            'token' => $this->getToken(), // TODO: ACH
        ];

        $paymentId = $this->getCustomerPaymentId();
        if ($paymentId) {
            $action = 'customer/editPayment';
            $params['paymentProfileId'] = $paymentId;
        }

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

        $response = $gateway->getHostedProfilePage();

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
     */
    public function getCard()
    {
        $cardId = $this->getTokenbaseCardId();

        if (empty($cardId)) {
            // Find the newest card on the CIM profile and import it
            $newestCardProfile = $this->fetchAddedCard();
            $card              = $this->importPaymentProfile($newestCardProfile);

            if ($this->getRequest()->getParam('source') !== 'paymentinfo') {
                $this->saveCardToQuote($card);
            }
        } else {
            // Card already exists; refresh the payment info from the CIM profile
            $card = $this->cardRepository->getByHash($cardId);

            if ($card->hasOwner((int)$this->helper->getCurrentCustomer()->getId()) === false) {
                throw new \Magento\Framework\Exception\LocalizedException(__('Could not load payment profile'));
            }

            $this->updateCardFromPaymentProfile($card);
        }

        return $card;
    }

    /**
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Payment\Gateway\Command\CommandException
     */
    public function fetchAddedCard(): array
    {
        /** @var \ParadoxLabs\Authnetcim\Model\Gateway $gateway */
        $gateway = $this->getMethod()->gateway();

        // Get CIM profile ID
        $profileId  = $this->getCustomerProfileId($gateway);

        // Get CIM profile cards
        $gateway->setParameter('customerProfileId', $profileId);
        $gateway->setParameter('unmaskExpirationDate', 'true');
        $gateway->setParameter('includeIssuerInfo', 'true');

        $response = $gateway->getCustomerProfile();

        if (!empty($response['messages']['message']['text'])
            && $response['messages']['message']['text'] !== 'Successful.') {
            throw new \Magento\Framework\Exception\LocalizedException(__($response['messages']['message']['text']));
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
     * @param array $paymentProfile
     * @return CardInterface
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function importPaymentProfile(array $paymentProfile): CardInterface
    {
        $card = $this->cardFactory->create();
        $card->setMethod($this->getMethodCode());
        $card->setCustomerId($this->getCustomerId());
        $card->setCustomerEmail($this->getEmail());
        $card->setActive(true);
        $card->setProfileId($paymentProfile['customerProfileId']);
        $card->setPaymentId($paymentProfile['customerPaymentProfileId']);

        $this->setPaymentProfileDataOnCard($paymentProfile, $card);

        $this->cardRepository->save($card);

        return $card;
    }

    /**
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

        return $card;
    }

    /**
     * @param array $paymentProfile
     * @param CardInterface $card
     * @return CardInterface
     */
    public function setPaymentProfileDataOnCard(array $paymentProfile, CardInterface $card): CardInterface
    {
        if (isset($paymentProfile['payment']['creditCard'])) {
            [$yr, $mo] = explode('-', (string)$paymentProfile['payment']['creditCard']['expirationDate'], 2);
            $day  = date('t', strtotime($yr . '-' . $mo));
            $type = $this->helper->mapCcTypeToMagento($paymentProfile['payment']['creditCard']['cardType']);

            $paymentData = [
                'cc_type' => $type,
                'cc_last4' => substr((string)$paymentProfile['payment']['creditCard']['cardNumber'], -4),
                'cc_exp_year' => $yr,
                'cc_exp_month' => $mo,
                'cc_bin' => $paymentProfile['payment']['creditCard']['issuerNumber'],
            ];
            $paymentData += $card->getAdditional();

            $card->setData('additional', json_encode($paymentData));
            $card->setData('expires', sprintf('%s-%s-%s 23:59:59', $yr, $mo, $day));
        }

        return $card;
    }

    /**
     * @return string
     */
    abstract public function getCustomerProfileId(): string;

    /**
     * @return string|null
     */
    abstract public function getCustomerPaymentId(): ?string;

    /**
     * Get customer email for the payment request.
     *
     * @return string|null
     */
    abstract public function getEmail();

    /**
     * Get customer ID for the payment request.
     *
     * @return int|null
     */
    abstract public function getCustomerId();

    /**
     * Get the current store ID, for config scoping.
     *
     * @return string
     */
    abstract protected function getStoreId();

    /**
     * Get the active payment method code.
     *
     * @return string
     */
    abstract protected function getMethodCode(): string;

    /**
     * @return string
     */
    abstract protected function getTokenbaseCardId(): string;

    /**
     * @param CardInterface $card
     * @return void
     */
    abstract protected function saveCardToQuote(CardInterface $card): void;
}
