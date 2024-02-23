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
 * @link https://support.paradoxlabs.com
 */

namespace ParadoxLabs\Authnetcim\Model\Service\AcceptHosted;

use Magento\Quote\Api\Data\AddressInterface;

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
     * @var \ParadoxLabs\Authnetcim\Helper\Data
     */
    protected $helper;

    /**
     * @var \ParadoxLabs\Authnetcim\Model\Method
     */
    protected $method;

    /**
     * @var \Magento\Quote\Api\CartRepositoryInterface
     */
    protected $quoteRepository;

    /**
     * @var \ParadoxLabs\TokenBase\Helper\Address
     */
    protected $addressHelper;

    /**
     * @var string
     */
    protected $methodCode;

    /**
     * AbstractRequestHandler constructor.
     *
     * @param \ParadoxLabs\Authnetcim\Model\Service\AcceptHosted\Context $context
     */
    public function __construct(
        Context $context
    ) {
        $this->urlBuilder = $context->getUrlBuilder();
        $this->methodFactory = $context->getMethodFactory();
        $this->helper = $context->getHelper();
        $this->quoteRepository = $context->getQuoteRepository();
        $this->addressHelper = $context->getAddressHelper();
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
        return [
            'iframeAction' => $this->getEndpoint() . 'payment/payment',
            'iframeParams' => [
                'token' => $this->getToken(),
            ],
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

        // Get payment form token
        $customCommunicatorUrl = $this->method->getConfigData('hosted_custom_communicator_url');
        $communicatorUrl = $customCommunicatorUrl ?: $this->urlBuilder->getUrl('authnetcim/hosted/communicator');

        $allowSaveOptIn  = !empty($this->getCustomerId()) ? (bool)$method->getConfigData('allow_unsaved') : false;
        $gateway->setParameter('hostedProfileIFrameCommunicatorUrl', $communicatorUrl);
        $gateway->setParameter('hostedProfileHeadingBgColor', $method->getConfigData('accent_color'));
        $gateway->setParameter('hostedPaymentAddProfile', $allowSaveOptIn);
        $gateway->setParameter('hostedPaymentValidateCaptcha', (bool)$method->getConfigData('enable_hosted_captcha'));
        $gateway->setParameter('customerProfileId', $this->getCustomerProfileId());

        if ($this->getMethodCode() === \ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider::CODE) {
            $gateway->setParameter('hostedPaymentCardCodeRequired', false);
            $gateway->setParameter('hostedPaymentShowCreditCard', false);
            $gateway->setParameter('hostedPaymentShowBankAccount', true);
        }

        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $this->getQuote();

        $gateway->setParameter('merchantCustomerId', $this->getCustomerId());
        $gateway->setParameter('email', $this->getEmail());
        $gateway->setParameter('amount', $quote->getBaseGrandTotal());
        $gateway->setParameter('shipAmount', 0);
        $gateway->setParameter('taxAmount', $quote->getBillingAddress()->getBaseTaxAmount());
        $gateway->setParameter('invoiceNumber', $this->getOrderIncrementId($quote));
        $gateway->setLineItems($quote->getItems());

        $this->setBillingParams($gateway);
        $this->setShippingParams($gateway);

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
     * Get/reserve an order ID for the quote
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return string
     */
    public function getOrderIncrementId(\Magento\Quote\Model\Quote $quote): string
    {
        if (empty($quote->getReservedOrderId())) {
            $quote->reserveOrderId();
            $this->quoteRepository->save($quote);
        }

        return (string)$quote->getReservedOrderId();
    }

    /**
     * Set shipping address parameters on the Gateway
     *
     * @param \ParadoxLabs\Authnetcim\Model\Gateway $gateway
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Payment\Gateway\Command\CommandException
     */
    protected function setShippingParams(\ParadoxLabs\Authnetcim\Model\Gateway $gateway): void
    {
        $quote    = $this->getQuote();
        $shipping = $quote->getShippingAddress();

        if ((bool)$quote->isVirtual() === false && $shipping instanceof AddressInterface) {
            $gateway->setShipTo($shipping->getDataModel());

            $gateway->setParameter('shipAmount', $quote->getShippingAddress()->getBaseShippingAmount());
            $gateway->setParameter('taxAmount', $quote->getShippingAddress()->getBaseTaxAmount());
        }
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
     * Get the active quote.
     *
     * @return \Magento\Quote\Api\Data\CartInterface
     */
    abstract protected function getQuote(): \Magento\Quote\Api\Data\CartInterface;

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
     * Set billing address parameters on the Gateway
     *
     * @param \ParadoxLabs\Authnetcim\Model\Gateway $gateway
     * @return void
     */
    abstract protected function setBillingParams(\ParadoxLabs\Authnetcim\Model\Gateway $gateway): void;
}
