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
 * BackendRequest Class
 */
class BackendRequest extends AbstractRequestHandler
{
    /**
     * @var \Magento\Backend\Model\Session\Quote
     */
    protected $backendSession;

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
     * @var \ParadoxLabs\TokenBase\Helper\Data
     */
    protected $tokenbaseHelper;

    /**
     * @var \Magento\Quote\Model\ResourceModel\Quote\Payment
     */
    protected $paymentResource;

    /**
     * BackendRequest constructor.
     *
     * @param \Magento\Framework\Url $urlBuilder
     * @param \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
     * @param \Magento\Backend\Model\Session\Quote $backendSession
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository
     * @param \ParadoxLabs\TokenBase\Helper\Data $tokenbaseHelper
     * @param \Magento\Quote\Model\ResourceModel\Quote\Payment $paymentResource
     */
    public function __construct(
        \Magento\Framework\Url $urlBuilder,
        \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory,
        \Magento\Backend\Model\Session\Quote $backendSession,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\App\RequestInterface $request,
        \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository,
        \ParadoxLabs\TokenBase\Helper\Data $tokenbaseHelper,
        \Magento\Quote\Model\ResourceModel\Quote\Payment $paymentResource
    ) {
        parent::__construct($urlBuilder, $methodFactory);

        $this->backendSession = $backendSession;
        $this->storeManager = $storeManager;
        $this->request = $request;
        $this->cardRepository = $cardRepository;
        $this->tokenbaseHelper = $tokenbaseHelper;
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
            $cardId = $this->request->getParam('card_id');

            if (!empty($cardId)) {
                $card = $this->cardRepository->getByHash($cardId);

                if ($card->hasOwner((int)$this->getCustomerId()) === false) {
                    throw new \Magento\Framework\Exception\LocalizedException(__('Could not load payment profile'));
                }

                return (string)$card->getProfileId();
            } elseif ($this->backendSession->getData('authnetcim_profile_id_' . $this->getCustomerId())) {
                return $this->backendSession->getData('authnetcim_profile_id_' . $this->getCustomerId());
            }
        }

        $gateway->setParameter('email', $this->getEmail());
        $gateway->setParameter('merchantCustomerId', (int)$this->getCustomerId());
        $gateway->setParameter('description', 'Magento ' . date('c'));

        $profileId = $gateway->createCustomerProfile();

        if ($this->request->getParam('source') !== 'paymentinfo') {
            $quote   = $this->backendSession->getQuote();
            $payment = $quote->getPayment();
            $payment->setAdditionalInformation('profile_id', $profileId);
            $this->paymentResource->save($payment);
        } else {
            $this->backendSession->setData('authnetcim_profile_id_' . $this->getCustomerId(), $profileId);
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
            $cardId = $this->request->getParam('card_id');

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
                return $this->tokenbaseHelper->getCurrentCustomer()->getEmail();
            }

            return $this->backendSession->getQuote()->getBillingAddress()->getEmail();
        } catch (\Exception $exception) {
            return null;
        }
    }

    /**
     * Get customer ID for the payment request.
     *
     * @return int|null
     */
    public function getCustomerId()
    {
        return $this->tokenbaseHelper->getCurrentCustomer()->getId();
    }

    /**
     * Get the current store ID, for config scoping.
     *
     * @return string
     */
    protected function getStoreId()
    {
        try {
            if ($this->request->getParam('source') === 'paymentinfo') {
                return $this->tokenbaseHelper->getCurrentCustomer()->getStoreId();
            }

            return $this->backendSession->getQuote()->getStoreId();
        } catch (\Exception $exception) {
            return $this->storeManager->getStore()->getId();
        }
    }

    /**
     * @return string
     */
    protected function getMethodCode(): string
    {
        // TODO: Constrain to allowed methods
        return $this->request->getParam('method');
    }
}
