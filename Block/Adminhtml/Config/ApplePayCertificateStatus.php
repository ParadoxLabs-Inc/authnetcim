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

namespace ParadoxLabs\Authnetcim\Block\Adminhtml\Config;

use DateTimeImmutable;
use IntlDateFormatter;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Magento\Store\Model\ScopeInterface;
use Override;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\PendingIdentityKey;
use ParadoxLabs\Authnetcim\Model\Wallet\Pem;
use Throwable;

/**
 * Apple Pay Merchant Identity status for the scope being edited: the saved certificate (Merchant ID, validity, key
 * match), and any certificate request waiting for its certificate (generate button and re-download link).
 */
class ApplePayCertificateStatus extends Field
{
    private const WALLET_PATH = 'payment/authnetcim_wallet/';

    private const WARNING_DAYS = 30;

    /**
     * @var string
     */
    protected $_template = 'ParadoxLabs_Authnetcim::config/applepay-certificate-status.phtml';

    /**
     * @param Context $context
     * @param Pem $pem
     * @param PendingIdentityKey $pendingIdentityKey
     * @param array $data
     * @param SecureHtmlRenderer|null $secureRenderer
     */
    public function __construct(
        Context $context,
        private readonly Pem $pem,
        private readonly PendingIdentityKey $pendingIdentityKey,
        array $data = [],
        ?SecureHtmlRenderer $secureRenderer = null,
    ) {
        parent::__construct($context, $data, $secureRenderer);
    }

    /**
     * Remove scope label and inheritance checkboxes: this field has no value of its own.
     *
     * @param AbstractElement $element
     * @return string
     */
    #[Override]
    public function render(AbstractElement $element)
    {
        $element->unsScope()
                ->unsCanUseWebsiteValue()
                ->unsCanUseDefaultValue();

        return parent::render($element);
    }

    /**
     * Render the status and generate button.
     *
     * @param AbstractElement $element
     * @return string
     */
    #[Override]
    protected function _getElementHtml(AbstractElement $element)
    {
        $this->setData('html_id', $element->getHtmlId());

        return $this->_toHtml();
    }

    /**
     * Get the saved certificate's details, or null if none is saved for this scope.
     *
     * @return array{merchant_ids: string[], valid_from: string|null, valid_to: string|null, days_left: int|null,
     *     severity: string, key_status: string}|null
     */
    public function getCertificateStatus(): ?array
    {
        $certificate = $this->pem->normalize($this->getConfigValue('applepay_identity_cert'));
        if ($certificate === '') {
            return null;
        }

        $start  = $this->pem->getCertificateStart($certificate);
        $expiry = $this->pem->getCertificateExpiry($certificate);
        $key    = $this->getConfigValue('applepay_identity_key');

        $daysLeft = $expiry !== null ? (int)floor(($expiry - time()) / 86400) : null;

        return [
            'merchant_ids' => $this->pem->getMerchantIds($certificate),
            'valid_from' => $start !== null ? $this->formatTimestamp($start) : null,
            'valid_to' => $expiry !== null ? $this->formatTimestamp($expiry) : null,
            'days_left' => $daysLeft,
            'severity' => match (true) {
                $daysLeft === null, $expiry < time() => 'error',
                $daysLeft <= self::WARNING_DAYS => 'warning',
                default => 'ok',
            },
            'key_status' => match (true) {
                trim($key) === '' => 'missing',
                $this->pem->isKeyForCertificate($certificate, $key) => 'match',
                default => 'mismatch',
            },
        ];
    }

    /**
     * Get when the pending certificate request for this scope was generated (formatted), or null if there is none.
     *
     * @return string|null
     */
    public function getPendingRequestDate(): ?string
    {
        [$scope, $scopeId] = $this->getConfigScope();

        $createdAt = $this->pendingIdentityKey->getCreatedAt($scope, $scopeId);
        if ($createdAt === null) {
            return null;
        }

        try {
            return $this->_localeDate->formatDateTime(
                $createdAt,
                IntlDateFormatter::MEDIUM,
                IntlDateFormatter::SHORT
            );
        } catch (Throwable) {
            return $createdAt;
        }
    }

    /**
     * Get the URL that generates a new certificate request for this scope.
     *
     * @return string
     */
    public function getGenerateUrl(): string
    {
        return $this->getUrl('authnetcim/system_config/generateApplePayCsr', $this->getScopeParams());
    }

    /**
     * Get the URL that downloads the pending certificate request for this scope again.
     *
     * @return string
     */
    public function getDownloadUrl(): string
    {
        return $this->getUrl('authnetcim/system_config/downloadApplePayCsr', $this->getScopeParams());
    }

    /**
     * Name of the Merchant ID input, to send its current (possibly unsaved) value with the request.
     *
     * @return string
     */
    public function getMerchantIdInputName(): string
    {
        return 'groups[authnetcim_wallet][fields][applepay_merchant_identifier][value]';
    }

    /**
     * Get a wallet config value at the scope being edited (inherited as the config form shows it).
     *
     * @param string $field
     * @return string
     */
    private function getConfigValue(string $field): string
    {
        [$scope, $scopeId] = $this->getConfigScope();

        return (string)$this->_scopeConfig->getValue(
            self::WALLET_PATH . $field,
            $scope,
            $scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT ? null : $scopeId
        );
    }

    /**
     * Get the scope being edited: [scope, scope ID].
     *
     * @return array{0: string, 1: int}
     */
    private function getConfigScope(): array
    {
        $websiteId = (int)$this->getRequest()->getParam('website');

        return $websiteId > 0
            ? [ScopeInterface::SCOPE_WEBSITES, $websiteId]
            : [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0];
    }

    /**
     * Get the URL params for the scope being edited.
     *
     * @return array
     */
    private function getScopeParams(): array
    {
        $websiteId = (int)$this->getRequest()->getParam('website');

        return $websiteId > 0 ? ['website' => $websiteId] : [];
    }

    /**
     * Format a unix timestamp as a date in the admin locale.
     *
     * @param int $timestamp
     * @return string
     */
    private function formatTimestamp(int $timestamp): string
    {
        return $this->_localeDate->formatDate(
            (new DateTimeImmutable('@' . $timestamp))->format('Y-m-d H:i:s'),
            IntlDateFormatter::MEDIUM
        );
    }
}
