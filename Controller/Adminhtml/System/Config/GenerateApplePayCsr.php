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

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Throwable;

/**
 * Generate a new private key (kept pending, encrypted) and return its certificate request for Apple.
 *
 * The active key and certificate are untouched until Apple's certificate for this request is saved.
 */
class GenerateApplePayCsr extends ApplePayCsr implements HttpPostActionInterface
{
    /**
     * @return Json
     */
    public function execute()
    {
        /** @var Json $resultJson */
        $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        try {
            [$scope, $scopeId] = $this->getConfigScope();

            $merchantId = trim((string)$this->getRequest()->getParam('merchantId'));
            if ($merchantId === '') {
                $merchantId = $this->getSavedMerchantId($scope, $scopeId);
            }

            $privateKey = $this->certificateRequest->createPrivateKey();
            $csr        = $this->certificateRequest->createSigningRequest($privateKey, $merchantId);

            $this->pendingIdentityKey->save($privateKey, $scope, $scopeId);

            $resultJson->setData([
                'csr' => $csr,
                'filename' => $this->getFilename($merchantId),
                'message' => __(
                    'Certificate request downloaded. In Apple Developer, create an Apple Pay Merchant Identity'
                    . ' Certificate for your Merchant ID with this file, then upload the .cer file Apple gives you'
                    . ' below and save. Your current certificate keeps working until then.'
                ),
            ]);
        } catch (LocalizedException $exception) {
            $resultJson->setStatusHeader(400);
            $resultJson->setData([
                'message' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            $this->logger->error((string)$exception);

            $resultJson->setStatusHeader(500);
            $resultJson->setData([
                'message' => __('The certificate request could not be created. Please check the exception log.'),
            ]);
        }

        return $resultJson;
    }
}
