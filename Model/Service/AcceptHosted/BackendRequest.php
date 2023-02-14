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

use Magento\Quote\Api\Data\AddressInterface;
use ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider as ConfigProviderAch;
use ParadoxLabs\Authnetcim\Model\ConfigProvider as ConfigProviderCc;

class BackendRequest extends AbstractRequestHandler
{
    /**
     * @var \Magento\Backend\Model\Session\Quote
     */
    protected $backendSession;

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    protected $request;

    /**
     * @var \Magento\Quote\Model\ResourceModel\Quote\Payment
     */
    protected $paymentResource;

    /**
     * AbstractRequestHandler constructor.
     *
     * @param \ParadoxLabs\Authnetcim\Model\Service\AcceptHosted\Context $context
     * @param \Magento\Backend\Model\Session\Quote $backendSession *Proxy
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \Magento\Quote\Model\ResourceModel\Quote\Payment $paymentResource
     */
    public function __construct(
        Context $context,
        \Magento\Backend\Model\Session\Quote $backendSession,
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Quote\Model\ResourceModel\Quote\Payment $paymentResource
    ) {
        parent::__construct($context);

        $this->backendSession = $backendSession;
        $this->request = $request;
        $this->paymentResource = $paymentResource;
    }

    /**
     * Get the CIM customer profile ID for the current session/context.
     *
     * @return string
     * @throws \Magento\Payment\Gateway\Command\CommandException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getCustomerProfileId(): string
    {
        $payment = $this->backendSession->getQuote()->getPayment();

        if ($payment->hasAdditionalInformation('profile_id')) {
            return $payment->getAdditionalInformation('profile_id');
        }

        /** @var \ParadoxLabs\Authnetcim\Model\Gateway $gateway */
        $gateway = $this->getMethod()->gateway();
        $gateway->setParameter('email', $this->getEmail());
        $gateway->setParameter('merchantCustomerId', $this->getCustomerId());
        $gateway->setParameter('description', 'Magento ' . date('c'));

        $profileId = $gateway->createCustomerProfile();

        $payment->setAdditionalInformation('profile_id', $profileId);
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
        try {
            return $this->getQuote()->getBillingAddress()->getEmail();
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /**
     * Get customer ID for the current session/context.
     *
     * @return string|null
     */
    public function getCustomerId(): ?string
    {
        return (string)$this->helper->getCurrentCustomer()->getId();
    }

    /**
     * Get the active quote.
     *
     * @return \Magento\Quote\Api\Data\CartInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function getQuote(): \Magento\Quote\Api\Data\CartInterface
    {
        return $this->backendSession->getQuote();
    }

    /**
     * Get the current store ID, for config loading.
     *
     * @return int
     */
    protected function getStoreId(): int
    {
        try {
            return (int)$this->getQuote()->getStoreId();
        } catch (\Exception $exception) {
            return (int)$this->helper->getCurrentStoreId();
        }
    }

    /**
     * Get the active payment method code.
     *
     * @return string
     */
    protected function getMethodCode(): string
    {
        $methodCode = $this->request->getParam('method');

        if (in_array($methodCode, [ConfigProviderCc::CODE, ConfigProviderAch::CODE], true)) {
            return $methodCode;
        }

        return ConfigProviderCc::CODE;
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
        // Use billing params over quote data, if given
        $post = $this->request->getPostValue('billing');
        if (!empty($post)) {
            $post['country_id']  = $post['country_id'] ?? $post['countryId'] ?? null;
            $post['region_id']   = $post['region_id'] ?? $post['regionId'] ?? null;
            $post['region_code'] = $post['region_code'] ?? $post['regionCode'] ?? null;

            $address = $this->addressHelper->buildAddressFromInput($post);
            $gateway->setBillTo($address);

            return;
        }

        $billing = $this->getQuote()->getBillingAddress();
        if ($billing instanceof AddressInterface) {
            $gateway->setBillTo($billing->getDataModel());
        }
    }
}
