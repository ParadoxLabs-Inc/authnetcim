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

namespace ParadoxLabs\Authnetcim\Model\Service\AcceptHosted;

use ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider as ConfigProviderAch;
use ParadoxLabs\Authnetcim\Model\ConfigProvider as ConfigProviderCc;
use ParadoxLabs\TokenBase\Api\Data\CardInterface;

class FrontendRequest extends AbstractRequestHandler
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;

    /**
     * @var \Magento\Customer\Model\Session
     */
    protected $customerSession;

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
     * @param \ParadoxLabs\Authnetcim\Model\Service\AcceptCustomer\Context $context
     * @param \Magento\Checkout\Model\Session $checkoutSession *Proxy
     * @param \Magento\Customer\Model\Session $customerSession *Proxy
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \Magento\Quote\Model\ResourceModel\Quote\Payment $paymentResource
     */
    public function __construct(
        Context $context,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Quote\Model\ResourceModel\Quote\Payment $paymentResource
    ) {
        parent::__construct($context);

        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->request = $request;
        $this->paymentResource = $paymentResource;
    }

    /**
     * Get the CIM customer profile ID for the current session/context.
     *
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getCustomerProfileId(): string
    {
        $payment = $this->checkoutSession->getQuote()->getPayment();

        if ($this->request->getParam('source') === 'paymentinfo') {
            // If we were given a card ID, get the profile ID from that instead of creating new
            $cardId = $this->request->getParam('card_id') ?? $this->request->getParam('id');

            if (!empty($cardId)) {
                $card = $this->cardRepository->getByHash($cardId);

                if ($card->hasOwner((int)$this->getCustomerId()) === false) {
                    throw new \Magento\Framework\Exception\LocalizedException(__('Could not load payment profile'));
                }

                return (string)$card->getProfileId();
            }

            if ($this->customerSession->getData('authnetcim_profile_id')) {
                return $this->customerSession->getData('authnetcim_profile_id');
            }
        } elseif ($payment->hasAdditionalInformation('profile_id')) {
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
            $this->customerSession->setData('authnetcim_profile_id', $profileId);
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
        if ($this->request->getParam('source') === 'paymentinfo') {
            // If we were given a card ID, get the profile ID from that instead of creating new
            $cardId = $this->request->getParam('card_id') ?? $this->request->getParam('id');

            if (!empty($cardId)) {
                $card = $this->cardRepository->getByHash($cardId);

                if ($card->hasOwner((int)$this->getCustomerId()) === false) {
                    throw new \Magento\Framework\Exception\LocalizedException(__('Could not load payment profile'));
                }

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
        if ($this->request->getParam('source') === 'paymentinfo') {
            return $this->customerSession->getCustomerData()->getEmail();
        }

        if (!empty($this->checkoutSession->getQuote()->getBillingAddress()->getEmail())) {
            return $this->checkoutSession->getQuote()->getBillingAddress()->getEmail();
        }

        // Fall back to guest email parameter iff there's none on the quote.
        return $this->request->getParam('guest_email');
    }

    /**
     * Get customer ID for the current session/context.
     *
     * @return string|null
     */
    public function getCustomerId(): ?string
    {
        if ($this->checkoutSession->getQuoteId()) {
            return (string)$this->checkoutSession->getQuote()->getCustomerId();
        }

        return (string)$this->customerSession->getCustomerId();
    }

    /**
     * Get the current store ID, for config loading.
     *
     * @return int
     */
    protected function getStoreId(): int
    {
        return (int)$this->helper->getCurrentStoreId();
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
        return (string)($this->request->getParam('card_id') ?? $this->request->getParam('id'));
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

        $payment = $this->checkoutSession->getQuote()->getPayment();
        $payment->setMethod($this->getMethodCode());
        $method  = $payment->getMethodInstance();
        $method->assignData(new \Magento\Framework\DataObject(['card_id' => $card->getHash()]));

        $this->paymentResource->save($payment);
    }
}
