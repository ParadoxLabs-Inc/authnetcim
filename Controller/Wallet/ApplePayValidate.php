<?php
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

declare(strict_types=1);

namespace ParadoxLabs\Authnetcim\Controller\Wallet;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Exception\LocalizedException;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\DomainResolver;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\MerchantValidator;
use Throwable;

/**
 * Apple Pay merchant validation for the storefront checkout (ApplePaySession onvalidatemerchant).
 */
class ApplePayValidate implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * Merchant validation runs once per payment sheet; this bounds abuse of the outbound Apple call per session.
     */
    private const MAX_PER_SESSION = 30;

    private const SESSION_COUNTER_KEY = 'authnetcim_applepay_validations';

    /**
     * @param Http $request
     * @param JsonFactory $jsonFactory
     * @param Validator $formKeyValidator
     * @param CheckoutSession $checkoutSession
     * @param MerchantValidator $merchantValidator
     * @param DomainResolver $domainResolver
     */
    public function __construct(
        private readonly Http $request,
        private readonly JsonFactory $jsonFactory,
        private readonly Validator $formKeyValidator,
        private readonly CheckoutSession $checkoutSession,
        private readonly MerchantValidator $merchantValidator,
        private readonly DomainResolver $domainResolver,
    ) {
    }

    /**
     * @return ResultInterface
     */
    public function execute()
    {
        /** @var Json $result */
        $result = $this->jsonFactory->create();

        try {
            $quote = $this->checkoutSession->getQuote();
            if (!$quote->getIsActive() || !$quote->hasItems()) {
                throw new LocalizedException(__('Your cart is empty.'));
            }

            $this->enforceRateLimit();

            $storeId = (int)$quote->getStoreId();
            $session = $this->merchantValidator->getMerchantSession(
                (string)$this->request->getParam('validationUrl'),
                $this->domainResolver->resolve($storeId, $this->request->getHttpHost(false)),
                $storeId
            );

            $result->setData($session);
        } catch (LocalizedException $exception) {
            $result->setHttpResponseCode(400);
            $result->setData([
                'message' => $exception->getMessage(),
            ]);
        } catch (Throwable) {
            $result->setHttpResponseCode(400);
            $result->setData([
                'message' => __('Apple Pay is unavailable right now. Please try again.'),
            ]);
        }

        return $result;
    }

    /**
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        $message = __('Invalid Form Key. Please refresh the page.');

        /** @var Json $result */
        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode(403);
        $result->setData([
            'message' => $message,
        ]);

        return new InvalidRequestException(
            $result,
            [$message]
        );
    }

    /**
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return $this->formKeyValidator->validate($request);
    }

    /**
     * Limit validation calls per customer session.
     *
     * @return void
     * @throws LocalizedException
     */
    private function enforceRateLimit(): void
    {
        $count = (int)$this->checkoutSession->getData(self::SESSION_COUNTER_KEY) + 1;
        if ($count > self::MAX_PER_SESSION) {
            throw new LocalizedException(__('Too many Apple Pay attempts. Please use another payment method.'));
        }

        $this->checkoutSession->setData(self::SESSION_COUNTER_KEY, $count);
    }
}
