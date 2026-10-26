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

namespace ParadoxLabs\Authnetcim\Model\Wallet\ApplePay;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Notification\MessageInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use ParadoxLabs\Authnetcim\Model\Wallet\Pem;

/**
 * Admin system message: an Apple Pay merchant identity certificate in use expires within 30 days, or has expired.
 *
 * Apple Pay is hidden at checkout once the certificate expires (see Config::isApplePayEnabled()), so warn ahead.
 * Messages are loaded on every admin page: certificates are only read and parsed for scopes with Apple Pay turned on.
 */
class CertificateExpiryMessage implements MessageInterface
{
    private const WALLET_PATH = 'payment/authnetcim_wallet/';

    private const WARNING_DAYS = 30;

    /**
     * Expiring/expired certificates by hash: ['expiry' => int, 'expired' => bool, 'scopes' => string[]].
     *
     * @var array[]|null
     */
    private ?array $alerts = null;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param AuthorizationInterface $authorization
     * @param Pem $pem
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly AuthorizationInterface $authorization,
        private readonly Pem $pem,
    ) {
    }

    /**
     * Unique per expiry date(s) and severity, so a renewed certificate (or one that has now expired) alerts again.
     *
     * @return string
     */
    public function getIdentity(): string
    {
        $expiries = array_column($this->getAlerts(), 'expiry');
        sort($expiries);

        return 'authnetcim_applepay_cert_' . hash(
            'sha256',
            $this->getSeverity() . ':' . implode(',', $expiries)
        );
    }

    /**
     * Show while a certificate in use is expiring or expired, to admins who can change the payment config.
     *
     * @return bool
     */
    public function isDisplayed(): bool
    {
        return $this->authorization->isAllowed('Magento_Payment::payment') && $this->getAlerts() !== [];
    }

    /**
     * Name each expiring/expired certificate's scope(s) and date, and what to do about it.
     *
     * @return string
     */
    public function getText(): string
    {
        $text = [];
        foreach ($this->getAlerts() as $alert) {
            $scopes = implode(', ', $alert['scopes']);
            $date   = date('Y-m-d', $alert['expiry']);

            $text[] = $alert['expired']
                ? __(
                    'The Apple Pay Merchant Identity certificate for %1 expired on %2, so Apple Pay is hidden at'
                    . ' checkout.',
                    $scopes,
                    $date
                )
                : __(
                    'The Apple Pay Merchant Identity certificate for %1 expires on %2. Apple Pay will be hidden at'
                    . ' checkout after that.',
                    $scopes,
                    $date
                );
        }

        $text[] = __(
            'Renew the Merchant Identity certificate in your Apple Developer account, then update it in the'
            . ' Authorize.net CIM - Apple Pay / Google Pay payment settings.'
        );

        return implode(' ', array_map('strval', $text));
    }

    /**
     * Critical once a certificate has expired (Apple Pay is already hidden), major while it's still expiring.
     *
     * @return int
     */
    public function getSeverity(): int
    {
        return in_array(true, array_column($this->getAlerts(), 'expired'), true)
            ? self::SEVERITY_CRITICAL
            : self::SEVERITY_MAJOR;
    }

    /**
     * Find the Apple Pay certificates in use (default config and each website) that are expiring or expired.
     *
     * @return array[]
     */
    private function getAlerts(): array
    {
        if ($this->alerts !== null) {
            return $this->alerts;
        }

        $this->alerts = [];
        $expiries     = [];
        $now          = time();
        $warnAfter    = $now + self::WARNING_DAYS * 86400;

        foreach ($this->getScopes() as [$scopeType, $scopeCode, $websiteName]) {
            $certificate = $this->getCertificate($scopeType, $scopeCode);
            if ($certificate === '') {
                continue;
            }

            // Parse each distinct certificate once; websites often inherit the default one.
            $hash = hash('sha256', $certificate);
            if (!array_key_exists($hash, $expiries)) {
                $expiries[$hash] = $this->pem->getCertificateExpiry($certificate);
            }

            // Unreadable certificates are rejected on save; there's no date to warn about.
            if ($expiries[$hash] === null || $expiries[$hash] > $warnAfter) {
                continue;
            }

            $this->alerts[$hash]['expiry']   = $expiries[$hash];
            $this->alerts[$hash]['expired']  = $expiries[$hash] < $now;
            $this->alerts[$hash]['scopes'][] = $websiteName === null
                ? (string)__('the default config')
                : (string)__('website "%1"', $websiteName);
        }

        return $this->alerts;
    }

    /**
     * Get the scopes the certificate can be set at: default config, then each website.
     *
     * @return array[] [scope type, scope code, website name (null for default)]
     */
    private function getScopes(): array
    {
        $scopes = [
            [
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                null,
                null,
            ],
        ];

        foreach ($this->storeManager->getWebsites() as $website) {
            $scopes[] = [
                ScopeInterface::SCOPE_WEBSITES,
                $website->getCode(),
                (string)$website->getName(),
            ];
        }

        return $scopes;
    }

    /**
     * Get the Apple Pay identity certificate at the given scope, or '' if Apple Pay isn't turned on there.
     *
     * @param string $scopeType
     * @param string|null $scopeCode
     * @return string
     */
    private function getCertificate(string $scopeType, ?string $scopeCode): string
    {
        if (!$this->scopeConfig->isSetFlag(self::WALLET_PATH . 'active', $scopeType, $scopeCode)
            || !$this->scopeConfig->isSetFlag(self::WALLET_PATH . 'applepay_active', $scopeType, $scopeCode)) {
            return '';
        }

        return trim((string)$this->scopeConfig->getValue(
            self::WALLET_PATH . 'applepay_identity_cert',
            $scopeType,
            $scopeCode
        ));
    }
}
