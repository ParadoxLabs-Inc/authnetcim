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

namespace ParadoxLabs\Authnetcim\Controller\Adminhtml\System\Config;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Throwable;

/**
 * Download the certificate request for the pending key again, without generating a new key.
 *
 * A CSR holds only the public key, so rebuilding it from the same key gives Apple an equivalent request.
 */
class DownloadApplePayCsr extends ApplePayCsr implements HttpGetActionInterface
{
    /**
     * @return Raw|Redirect
     */
    public function execute()
    {
        try {
            [$scope, $scopeId] = $this->getConfigScope();

            $pendingKey = $this->pendingIdentityKey->get($scope, $scopeId);
            if ($pendingKey === '') {
                $this->messageManager->addErrorMessage(
                    __('There is no Apple Pay certificate request waiting for a certificate here. Generate a new one.')
                );

                return $this->redirectToConfig();
            }

            $merchantId = $this->getSavedMerchantId($scope, $scopeId);
            $csr        = $this->certificateRequest->createSigningRequest($pendingKey, $merchantId);

            /** @var Raw $result */
            $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
            $result->setHeader('Content-Type', 'application/pkcs10', true);
            $result->setHeader(
                'Content-Disposition',
                'attachment; filename="' . $this->getFilename($merchantId) . '"',
                true
            );
            $result->setContents($csr);

            return $result;
        } catch (Throwable $exception) {
            $this->logger->error((string)$exception);
            $this->messageManager->addErrorMessage(__('The certificate request could not be downloaded.'));

            return $this->redirectToConfig();
        }
    }

    /**
     * @return Redirect
     */
    private function redirectToConfig(): Redirect
    {
        $params = [
            'section' => 'payment',
        ];

        $websiteId = (int)$this->getRequest()->getParam('website');
        if ($websiteId > 0) {
            $params['website'] = $websiteId;
        }

        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        return $redirect->setPath('adminhtml/system_config/edit', $params);
    }
}
