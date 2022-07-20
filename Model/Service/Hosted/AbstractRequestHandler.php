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
        $gateway->setParameter('customerProfileId', $this->getCustomerProfileId($gateway));

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
     * Get the most recent card added to a CIM profile (via hosted form, presumably)
     *
     * @return \ParadoxLabs\TokenBase\Api\Data\CardInterface
     */
    public function getCard()
    {
        $newestCardProfile = $this->fetchAddedCard();
        $card              = $this->importPaymentProfile($newestCardProfile);

        if ($this->getRequest()->getParam('source') !== 'paymentinfo') {
            $this->saveCardToQuote($card);
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
     * @param array $newestCard
     * @return mixed
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function importPaymentProfile(array $newestCard)
    {
        $card = $this->cardFactory->create();
        $card->setMethod($this->getMethodCode());
        $card->setCustomerId($this->getCustomerId());
        $card->setCustomerEmail($this->getEmail());
        $card->setActive(true);
        $card->setProfileId($newestCard['customerProfileId']);
        $card->setPaymentId($newestCard['customerPaymentProfileId']);

        if (isset($newestCard['payment']['creditCard'])) {
            [$yr, $mo] = explode('-', (string)$newestCard['payment']['creditCard']['expirationDate'], 2);
            $day  = date('t', strtotime($yr . '-' . $mo));
            $type = $this->helper->mapCcTypeToMagento($newestCard['payment']['creditCard']['cardType']);

            $paymentData = [
                'cc_type' => $type,
                'cc_last4' => substr((string)$newestCard['payment']['creditCard']['cardNumber'], -4),
                'cc_exp_year' => $yr,
                'cc_exp_month' => $mo,
                'cc_bin' => $newestCard['payment']['creditCard']['issuerNumber'],
            ];

            $card->setData('additional', json_encode($paymentData));
            $card->setData('expires', sprintf('%s-%s-%s 23:59:59', $yr, $mo, $day));
        }

        $this->cardRepository->save($card);

        return $card;
    }

    /**
     * @param \ParadoxLabs\Authnetcim\Model\Gateway $gateway
     * @return string
     */
    abstract public function getCustomerProfileId(\ParadoxLabs\Authnetcim\Model\Gateway $gateway): string;

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
     * @param \ParadoxLabs\TokenBase\Api\Data\CardInterface $card
     * @return void
     */
    abstract protected function saveCardToQuote(\ParadoxLabs\TokenBase\Api\Data\CardInterface $card): void;
}
