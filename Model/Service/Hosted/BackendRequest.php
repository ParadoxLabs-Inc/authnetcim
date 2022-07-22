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
     * @param \Magento\Framework\UrlInterface $urlBuilder
     * @param \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
     * @param \ParadoxLabs\TokenBase\Model\Card\Factory $cardFactory
     * @param \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository
     * @param \ParadoxLabs\Authnetcim\Helper\Data $helper
     * @param \Magento\Backend\Model\Session\Quote $backendSession
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \ParadoxLabs\TokenBase\Helper\Data $tokenbaseHelper
     * @param \Magento\Quote\Model\ResourceModel\Quote\Payment $paymentResource
     */
    public function __construct(
        \Magento\Framework\UrlInterface $urlBuilder,
        \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory,
        \ParadoxLabs\TokenBase\Model\Card\Factory $cardFactory,
        \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository,
        \ParadoxLabs\Authnetcim\Helper\Data $helper,
        \Magento\Backend\Model\Session\Quote $backendSession,
        \Magento\Framework\App\RequestInterface $request,
        \ParadoxLabs\TokenBase\Helper\Data $tokenbaseHelper,
        \Magento\Quote\Model\ResourceModel\Quote\Payment $paymentResource
    ) {
        parent::__construct($urlBuilder, $methodFactory, $cardFactory, $cardRepository, $helper);

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
        if ($this->request->getParam('source') === 'paymentinfo') {
            // If we were given a card ID, get the profile ID from that instead of creating new
            $cardId = $this->request->getParam('card_id');

            if (!empty($cardId)) {
                $card = $this->cardRepository->getByHash($cardId);

                if ($card->hasOwner((int)$this->getCustomerId()) === false) {
                    throw new \Magento\Framework\Exception\LocalizedException(__('Could not load payment profile'));
                }

                return (string)$card->getProfileId();
            }

            if ($this->backendSession->getData('authnetcim_profile_id_' . $this->getCustomerId())) {
                return (string)$this->backendSession->getData('authnetcim_profile_id_' . $this->getCustomerId());
            }
        }

        /** @var \ParadoxLabs\Authnetcim\Model\Gateway $gateway */
        $gateway = $this->getMethod()->gateway();
        $gateway->setParameter('email', $this->getEmail());
        $gateway->setParameter('merchantCustomerId', $this->getCustomerId());
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
     * Get the CIM payment ID for the current session/context.
     *
     * @return string|null
     * @throws \Magento\Framework\Exception\LocalizedException
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

            return $this->backendSession->getQuote()->getBillingAddress()->getEmail();
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

            return (int)$this->backendSession->getQuote()->getStoreId();
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
        // TODO: Constrain to allowed methods
        return (string)$this->request->getParam('method');
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

        $payment = $this->backendSession->getQuote()->getPayment();
        $method  = $payment->getMethodInstance();
        $method->assignData(new \Magento\Framework\DataObject(['card_id' => $card->getHash()]));

        $this->paymentResource->save($payment);
    }
}
