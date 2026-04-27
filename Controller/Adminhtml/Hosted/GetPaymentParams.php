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

namespace ParadoxLabs\Authnetcim\Controller\Adminhtml\Hosted;

use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use ParadoxLabs\Authnetcim\Model\Service\AcceptHosted\BackendRequest;
use Throwable;

class GetPaymentParams extends Action implements CsrfAwareActionInterface, HttpPostActionInterface
{
    /**
     * GetPaymentParams constructor.
     *
     * @param Context $context
     * @param Validator $formKey
     * @param BackendRequest $acceptHosted
     * @param Registry $registry
     * @param CustomerRepositoryInterface $customerRepository
     */
    public function __construct(
        Context $context,
        protected readonly Validator $formKey,
        protected readonly BackendRequest $acceptHosted,
        protected readonly Registry $registry,
        protected readonly CustomerRepositoryInterface $customerRepository
    ) {
        parent::__construct($context);
    }

    /**
     * Get hosted form parameters for a session
     *
     * @return ResultInterface
     */
    public function execute()
    {
        $this->initCustomer();

        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        try {
            $params = $this->acceptHosted->getParams();

            $result->setData($params);
        } catch (Throwable $exception) {
            $result->setHttpResponseCode(400);
            $result->setData([
                'message' => $exception->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Create exception in case CSRF validation failed.
     *
     * @param RequestInterface $request
     *
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(
        RequestInterface $request
    ): ?InvalidRequestException {
        $message = __('Invalid Form Key. Please refresh the page.');

        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
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
     * Perform custom request validation.
     *
     * @param RequestInterface $request
     *
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return $this->formKey->validate($request);
    }

    /**
     * Get current customer model.
     *
     * @return void
     */
    protected function initCustomer(): void
    {
        if ($this->registry->registry('current_customer')) {
            return;
        }

        $customerId = $this->acceptHosted->getCustomerId();

        if ($customerId > 0) {
            try {
                $customer = $this->customerRepository->getById($customerId);

                $this->registry->register('current_customer', $customer);
            } catch (NoSuchEntityException $exception) {
                // Ignore 'no such customer' errors, remainder code will handle accordingly.
            }
        }
    }
}
