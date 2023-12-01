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
 * @link https://support.paradoxlabs.com
 */

namespace ParadoxLabs\Authnetcim\Model\Service\AcceptCustomer;

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
     * @var \ParadoxLabs\Authnetcim\Model\Service\CustomerProfile
     */
    protected $customerProfileService;

    /**
     * @var string
     */
    protected $methodCode;

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
        $this->customerProfileService = $context->getCustomerProfileService();
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

            $this->customerProfileService->setMethod($this->method);
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
        $action = 'customer/addPayment';
        $params = [
            'token' => $this->getToken(),
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
     * @throws \Magento\Payment\Gateway\Command\CommandException
     * @throws \Magento\Framework\Exception\LocalizedException
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

        if ($this->getMethodCode() === \ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider::CODE) {
            $gateway->setParameter('hostedProfilePaymentOptions', 'showBankAccount');
            $gateway->setParameter('hostedProfileCardCodeRequired', false);
        }

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
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getCard(): CardInterface
    {
        $cardId = $this->getTokenbaseCardId();

        if (empty($cardId)) {
            // Find the newest card on the CIM profile and import it
            $newestCardProfile = $this->customerProfileService->fetchAddedCard(
                $this->getCustomerProfileId()
            );

            /** @var \ParadoxLabs\Authnetcim\Model\Card $card */
            $card = $this->cardFactory->create();
            $card->setMethod($this->getMethodCode());
            $card->setCustomerId($this->getCustomerId());
            $card->setCustomerEmail($this->getEmail());
            $card->setProfileId($this->getCustomerProfileId());
            $card->setActive(true);

            $card = $this->customerProfileService->importPaymentProfile(
                $card,
                $newestCardProfile
            );

            $this->saveCardToQuote($card);
        } else {
            // Card already exists; refresh the payment info from the CIM profile
            $card = $this->cardRepository->getByHash($cardId);

            if ($card->hasOwner((int)$this->helper->getCurrentCustomer()->getId()) === false) {
                throw new \Magento\Framework\Exception\LocalizedException(__('Could not load payment profile'));
            }

            $card = $card->getTypeInstance();
            $card->setData('no_sync', true);

            $card = $this->customerProfileService->updateCardFromPaymentProfile($card);
        }

        return $card;
    }

    /**
     * @param string $methodCode
     * @return void
     */
    public function setMethodCode(string $methodCode): void
    {
        $this->methodCode = $methodCode;
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
