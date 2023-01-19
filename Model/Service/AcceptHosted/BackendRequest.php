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

use ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider as ConfigProviderAch;
use ParadoxLabs\Authnetcim\Model\ConfigProvider as ConfigProviderCc;
use ParadoxLabs\TokenBase\Api\Data\CardInterface;

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
     * @var \ParadoxLabs\TokenBase\Helper\Data
     */
    protected $tokenbaseHelper;

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
     * @param \ParadoxLabs\TokenBase\Helper\Data $tokenbaseHelper
     * @param \Magento\Quote\Model\ResourceModel\Quote\Payment $paymentResource
     */
    public function __construct(
        Context $context,
        \Magento\Backend\Model\Session\Quote $backendSession,
        \Magento\Framework\App\RequestInterface $request,
        \ParadoxLabs\TokenBase\Helper\Data $tokenbaseHelper,
        \Magento\Quote\Model\ResourceModel\Quote\Payment $paymentResource
    ) {
        parent::__construct($context);

        $this->backendSession = $backendSession;
        $this->request = $request;
        $this->tokenbaseHelper = $tokenbaseHelper;
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

        if ($this->request->getParam('source') !== 'paymentinfo') {
            $payment->setAdditionalInformation('profile_id', $profileId);
            $this->paymentResource->save($payment);
        } else {
            $this->backendSession->setData('authnetcim_profile_id_' . $this->getCustomerId(), $profileId);
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
        try {
            if ($this->request->getParam('source') === 'paymentinfo') {
                return $this->tokenbaseHelper->getCurrentCustomer()->getEmail();
            }

            return $this->getQuote()->getBillingAddress()->getEmail();
        } catch (\Exception $exception) {
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
        return (string)$this->tokenbaseHelper->getCurrentCustomer()->getId();
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
            if ($this->request->getParam('source') === 'paymentinfo') {
                return (int)$this->tokenbaseHelper->getCurrentCustomer()->getStoreId();
            }

            return (int)$this->getQuote()->getStoreId();
        } catch (\Exception $exception) {
            return (int)$this->tokenbaseHelper->getCurrentStoreId();
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
     * Get the tokenbase card hash for the current session/context.
     *
     * @return string
     */
    protected function getTokenbaseCardId(): string
    {
        return (string)$this->request->getParam('card_id');
    }

    /**
     * Save the given card to the active quote as the active payment method.
     *
     * @param CardInterface $card
     * @return void
     */
    protected function saveCardToQuote(\ParadoxLabs\TokenBase\Api\Data\CardInterface $card): void
    {
        if ($this->request->getParam('source') === 'paymentinfo') {
            return;
        }

        $payment = $this->getQuote()->getPayment();
        $payment->setMethod($this->getMethodCode());
        $method  = $payment->getMethodInstance();
        $method->assignData(new \Magento\Framework\DataObject(['card_id' => $card->getHash()]));

        $this->paymentResource->save($payment);
    }
}