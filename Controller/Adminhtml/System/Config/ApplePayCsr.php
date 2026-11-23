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

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\CertificateRequest;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\PendingIdentityKey;
use Psr\Log\LoggerInterface;

/**
 * Shared scope handling for the Apple Pay certificate request actions.
 */
abstract class ApplePayCsr extends Action
{
    public const ADMIN_RESOURCE = 'Magento_Payment::payment';

    protected const MERCHANT_ID_PATH = 'payment/authnetcim_wallet/applepay_merchant_identifier';

    /**
     * @param Context $context
     * @param CertificateRequest $certificateRequest
     * @param PendingIdentityKey $pendingIdentityKey
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        protected readonly CertificateRequest $certificateRequest,
        protected readonly PendingIdentityKey $pendingIdentityKey,
        protected readonly StoreManagerInterface $storeManager,
        protected readonly ScopeConfigInterface $scopeConfig,
        protected readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    /**
     * Get the config scope being edited: [scope, scope ID]. The Apple Pay key is set at default or website scope.
     *
     * @return array{0: string, 1: int}
     * @throws LocalizedException
     */
    protected function getConfigScope(): array
    {
        $websiteId = (int)$this->getRequest()->getParam('website');

        if ($websiteId > 0) {
            return [
                ScopeInterface::SCOPE_WEBSITES,
                (int)$this->storeManager->getWebsite($websiteId)->getId(),
            ];
        }

        return [
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            0,
        ];
    }

    /**
     * Get the saved Merchant ID for the scope.
     *
     * @param string $scope
     * @param int $scopeId
     * @return string
     */
    protected function getSavedMerchantId(string $scope, int $scopeId): string
    {
        return trim((string)$this->scopeConfig->getValue(
            self::MERCHANT_ID_PATH,
            $scope,
            $scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT ? null : $scopeId
        ));
    }

    /**
     * Get the download filename for a request.
     *
     * @param string $merchantId
     * @return string
     */
    protected function getFilename(string $merchantId): string
    {
        $commonName = $this->certificateRequest->getCommonName($merchantId);

        return ($commonName === $merchantId ? $merchantId : 'merchant_id') . '.csr';
    }
}
