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
 * FrontendRequest Class
 */
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
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    protected $request;

    /**
     * @var \ParadoxLabs\TokenBase\Api\CardRepositoryInterface
     */
    protected $cardRepository;

    /**
     * @var \Magento\Quote\Model\ResourceModel\Quote\Payment
     */
    protected $paymentResource;

    /**
     * FrontendRequest constructor.
     *
     * @param \Magento\Framework\UrlInterface $urlBuilder
     * @param \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository
     * @param \Magento\Quote\Model\ResourceModel\Quote\Payment $paymentResource
     */
    public function __construct(
        \Magento\Framework\UrlInterface $urlBuilder,
        \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\App\RequestInterface $request,
        \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository,
        \Magento\Quote\Model\ResourceModel\Quote\Payment $paymentResource
    ) {
        parent::__construct($urlBuilder, $methodFactory);

        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->storeManager = $storeManager;
        $this->request = $request;
        $this->cardRepository = $cardRepository;
        $this->paymentResource = $paymentResource;
    }

    /**
     * @param \ParadoxLabs\Authnetcim\Model\Gateway $gateway
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Payment\Gateway\Command\CommandException
     */
    public function getCustomerProfileId(\ParadoxLabs\Authnetcim\Model\Gateway $gateway): string
    {
        if ($this->request->getParam('source') === 'paymentinfo') {
            // If we were given a card ID, get the profile ID from that instead of creating new
            $cardId = $this->request->getParam('card_id') ?? $this->request->getParam('id');

            if (!empty($cardId)) {
                $card = $this->cardRepository->getByHash($cardId);

                if ($card->hasOwner((int)$this->getCustomerId()) === false) {
                    throw new \Magento\Framework\Exception\LocalizedException(__('Could not load payment profile'));
                }

                return (string)$card->getProfileId();
            } elseif ($this->customerSession->getData('authnetcim_profile_id')) {
                return $this->customerSession->getData('authnetcim_profile_id');
            }
        }

        $gateway->setParameter('email', $this->getEmail());
        $gateway->setParameter('merchantCustomerId', (int)$this->getCustomerId());
        $gateway->setParameter('description', 'Magento ' . date('c'));

        $profileId = $gateway->createCustomerProfile();

        if ($this->request->getParam('source') !== 'paymentinfo') {
            $quote   = $this->checkoutSession->getQuote();
            $payment = $quote->getPayment();
            $payment->setAdditionalInformation('profile_id', $profileId);
            $this->paymentResource->save($payment);
        } else {
            $this->customerSession->setData('authnetcim_profile_id', $profileId);
        }

        return (string)$profileId;
    }

    /**
     * @return string|null
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
     * Get customer email for the payment request.
     *
     * @return string|null
     */
    public function getEmail()
    {
        try {
            if ($this->request->getParam('source') === 'paymentinfo') {
                return $this->customerSession->getCustomerData()->getEmail();
            }

            if (!empty($this->checkoutSession->getQuote()->getBillingAddress()->getEmail())) {
                return $this->checkoutSession->getQuote()->getBillingAddress()->getEmail();
            }

            // Fall back to guest email parameter iff there's none on the quote.
            return $this->request->getParam('guest_email');
        } catch (\Exception $exception) {
            throw $exception;
        }
    }

    /**
     * Get customer ID for the payment request.
     *
     * @return int|null
     */
    public function getCustomerId()
    {
        if ($this->checkoutSession->getQuoteId()) {
            return $this->checkoutSession->getQuote()->getCustomerId();
        }

        return $this->customerSession->getCustomerId();
    }

    /**
     * Get the current store ID, for config scoping.
     *
     * @return string
     */
    protected function getStoreId()
    {
        return $this->storeManager->getStore()->getId();
    }

    /**
     * @return string
     */
    protected function getMethodCode(): string
    {
        // TODO: Constrain to allowed methods
        return 'authnetcim';//$this->request->getParam('method');
    }
}
