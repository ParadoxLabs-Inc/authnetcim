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
     * AbstractRequestHandler constructor.
     *
     * @param \Magento\Framework\UrlInterface $urlBuilder
     * @param \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
     */
    public function __construct(
        \Magento\Framework\UrlInterface $urlBuilder,
        \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
    ) {
        $this->urlBuilder = $urlBuilder;
        $this->methodFactory = $methodFactory;
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
}
