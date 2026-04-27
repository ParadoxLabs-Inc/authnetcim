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

namespace ParadoxLabs\Authnetcim\Model\Service\AcceptHosted;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Customer\Model\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\ResourceModel\Quote\Payment;
use ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider as ConfigProviderAch;
use ParadoxLabs\Authnetcim\Model\ConfigProvider as ConfigProviderCc;
use ParadoxLabs\Authnetcim\Model\Gateway;

class FrontendRequest extends AbstractRequestHandler
{
    /**
     * AbstractRequestHandler constructor.
     *
     * @param Context $context
     * @param \Magento\Checkout\Model\Session $checkoutSession *Proxy
     * @param Session $customerSession *Proxy
     * @param RequestInterface $request
     * @param Payment $paymentResource
     */
    public function __construct(
        Context $context,
        protected readonly \Magento\Checkout\Model\Session $checkoutSession,
        protected readonly Session $customerSession,
        protected readonly RequestInterface $request,
        protected readonly Payment $paymentResource
    ) {
        parent::__construct($context);
    }

    /**
     * Get the CIM customer profile ID for the current session/context.
     *
     * @return string
     * @throws LocalizedException
     */
    public function getCustomerProfileId(): string
    {
        $payment    = $this->checkoutSession->getQuote()->getPayment();
        $email      = $this->getEmail();
        $customerId = $this->getCustomerId();

        if ($payment->hasAdditionalInformation('profile_id')
            && $payment->getAdditionalInformation('email') === $email
            && $payment->getAdditionalInformation('merchantCustomerId') === $customerId) {
            return $payment->getAdditionalInformation('profile_id');
        }

        /** @var Gateway $gateway */
        $gateway = $this->getMethod()->gateway();
        $gateway->setParameter('email', $email);
        $gateway->setParameter('merchantCustomerId', $customerId);
        $gateway->setParameter('description', 'Magento ' . date('c'));

        $profileId = $gateway->createCustomerProfile();

        $payment->setAdditionalInformation('profile_id', $profileId);
        $payment->setAdditionalInformation('email', $email);
        $payment->setAdditionalInformation('merchantCustomerId', $customerId);
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
        // If we're logged in, go straight to the quote data.
        if ((int)$this->getCustomerId() > 0) {
            return $this->getQuote()->getBillingAddress()->getEmail();
        }

        // Check for email in billing address from request first
        $billingFromRequest = $this->request->getParam('billing');
        if (!empty($billingFromRequest) && !empty($billingFromRequest['email'])) {
            return $billingFromRequest['email'];
        }

        // Then check existing quote billing address data
        if (!empty($this->getQuote()->getBillingAddress()->getEmail())) {
            return $this->getQuote()->getBillingAddress()->getEmail();
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
     * Get the active quote.
     *
     * @return CartInterface
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    protected function getQuote(): CartInterface
    {
        return $this->checkoutSession->getQuote();
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
        $methodCode = $this->methodCode ?? $this->request->getParam('method');

        if (in_array($methodCode, [ConfigProviderCc::CODE, ConfigProviderAch::CODE], true)) {
            return $methodCode;
        }

        return ConfigProviderCc::CODE;
    }

    /**
     * Set billing address parameters on the Gateway
     *
     * @param Gateway $gateway
     * @return void
     * @throws LocalizedException
     */
    protected function setBillingParams(Gateway $gateway): void
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
