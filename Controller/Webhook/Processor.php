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

namespace ParadoxLabs\Authnetcim\Controller\Webhook;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Data\Form\FormKey;
use ParadoxLabs\Authnetcim\Model\Service\WebhookProcessor;
use Throwable;

class Processor extends Action
{
    /**
     * @param Context $context
     * @param WebhookProcessor $webhookProcessor
     * @param FormKey $formKey
     * @throws LocalizedException
     */
    public function __construct(
        Context $context,
        protected readonly WebhookProcessor $webhookProcessor,
        FormKey $formKey
    ) {
        parent::__construct($context);

        // CSRF/form key protection compatibility
        if (interface_exists(CsrfAwareActionInterface::class)) {
            $request = $this->getRequest();
            if ($request instanceof Http && $request->isPost() && empty($request->getParam('form_key'))) {
                $request->setParam('form_key', $formKey->getFormKey());
            }
        }
    }

    /**
     * Process webhook based on request and return result
     *
     * @return ResultInterface|ResponseInterface
     * @throws NotFoundException
     */
    public function execute()
    {
        $jsonResponse = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        try {
            $this->webhookProcessor->process();
            $jsonResponse->setStatusHeader(200);
            $jsonResponse->setData([]);
        } catch (Throwable $exception) {
            $jsonResponse->setStatusHeader(400);
            $jsonResponse->setData(['error' => $exception->getMessage()]);
        }

        return $jsonResponse;
    }
}
