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
 * @link https://support.paradoxlabs.com
 */

namespace ParadoxLabs\Authnetcim\Model\Service\AcceptHosted;

use Magento\Quote\Api\Data\AddressInterface;
use ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider as ConfigProviderAch;
use ParadoxLabs\Authnetcim\Model\ConfigProvider as ConfigProviderCc;

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
     * @param \ParadoxLabs\Authnetcim\Model\Service\AcceptHosted\Context $context
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
        // Check for email in billing address from request first
        $billingFromRequest = $this->request->getParam('billing');
        if (!empty($billingFromRequest) && !empty($billingFromRequest['email'])) {
            return $billingFromRequest['email'];
        }

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
     * @return \Magento\Quote\Api\Data\CartInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function getQuote(): \Magento\Quote\Api\Data\CartInterface
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
