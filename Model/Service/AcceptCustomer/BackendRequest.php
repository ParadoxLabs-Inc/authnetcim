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
 *
 * @link https://support.paradoxlabs.com
 */

namespace ParadoxLabs\Authnetcim\Model\Service\AcceptCustomer;

use Magento\Payment\Gateway\Command\CommandException;
use ParadoxLabs\Authnetcim\Model\Gateway;
use Magento\Backend\Model\Session\Quote;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Quote\Model\ResourceModel\Quote\Payment;
use ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider as ConfigProviderAch;
use ParadoxLabs\Authnetcim\Model\ConfigProvider as ConfigProviderCc;
use ParadoxLabs\TokenBase\Api\Data\CardInterface;
use ParadoxLabs\TokenBase\Helper\Data;
use Throwable;

class BackendRequest extends AbstractRequestHandler
{
    /**
     * AbstractRequestHandler constructor.
     *
     * @param Context $context
     * @param Quote $backendSession *Proxy
     * @param RequestInterface $request
     * @param Data $tokenbaseHelper
     * @param \Magento\Quote\Model\ResourceModel\Quote\Payment $paymentResource
     */
    public function __construct(
        Context $context,
        protected readonly Quote $backendSession,
        protected readonly RequestInterface $request,
        protected readonly Data $tokenbaseHelper,
        protected readonly Payment $paymentResource
    ) {
        parent::__construct($context);
    }

    /**
     * Get the CIM customer profile ID for the current session/context.
     *
     * @return string
     * @throws CommandException
     * @throws LocalizedException
     */
    public function getCustomerProfileId(): string
    {
        if ($this->request->getParam('source') === 'paymentinfo') {
            // If we were given a card ID, get the profile ID from that instead of creating new
            $cardId = $this->request->getParam('card_id');

            if (!empty($cardId)) {
                $card = $this->cardRepository->getByHash($cardId);

                if ($card->hasOwner((int)$this->getCustomerId()) === false) {
                    throw new LocalizedException(__('Could not load payment profile'));
                }

                return (string)$card->getProfileId();
            }

            if ($this->backendSession->getData('authnetcim_profile_id_' . $this->getCustomerId())) {
                return (string)$this->backendSession->getData('authnetcim_profile_id_' . $this->getCustomerId());
            }
        } else {
            $payment = $this->backendSession->getQuote()->getPayment();

            if ($payment->hasAdditionalInformation('profile_id')) {
                return $payment->getAdditionalInformation('profile_id');
            }
        }

        /** @var Gateway $gateway */
        $gateway = $this->getMethod()->gateway();
        $gateway->setParameter('email', $this->getEmail());
        $gateway->setParameter('merchantCustomerId', $this->getCustomerId());
        $gateway->setParameter('description', 'Magento ' . date('c'));

        $profileId = $gateway->createCustomerProfile();

        if ($this->request->getParam('source') !== 'paymentinfo' && $payment instanceof QuotePayment) {
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
     * @throws LocalizedException
     */
    public function getCustomerPaymentId(): ?string
    {
        if ($this->request->getParam('source') === 'paymentinfo') {
            // If we were given a card ID, get the profile ID from that instead of creating new
            $cardId = $this->request->getParam('card_id');

            if (!empty($cardId)) {
                $card = $this->cardRepository->getByHash($cardId);

                if ($card->hasOwner((int)$this->getCustomerId()) === false) {
                    throw new LocalizedException(__('Could not load payment profile'));
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
        } catch (Throwable) {
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
        } catch (Throwable) {
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
        $methodCode = $this->methodCode ?? $this->request->getParam('method');

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
    protected function saveCardToQuote(CardInterface $card): void
    {
        if ($this->request->getParam('source') === 'paymentinfo') {
            return;
        }

        $payment = $this->backendSession->getQuote()->getPayment();
        $payment->setMethod($this->getMethodCode());
        $method = $payment->getMethodInstance();
        $method->assignData(new DataObject(['card_id' => $card->getHash()]));

        $this->paymentResource->save($payment);
    }

    /**
     * Clear the stored profile ID for the current session/context.
     *
     * @return void
     */
    protected function clearProfileId(): void
    {
        if ($this->request->getParam('source') === 'paymentinfo') {
            $this->backendSession->unsetData('authnetcim_profile_id_' . $this->getCustomerId());

            return;
        }

        $payment = $this->backendSession->getQuote()->getPayment();
        $payment->unsAdditionalInformation('profile_id');
    }
}
