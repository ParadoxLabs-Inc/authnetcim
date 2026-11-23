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

use Magento\Framework\Exception\LocalizedException;
use OpenSSLCertificate;
use ParadoxLabs\Authnetcim\Model\Wallet\Pem;

/**
 * Checks an Apple Pay Merchant Identity certificate before it is saved.
 */
class IdentityCertificateValidator
{
    /**
     * Subject CN prefix of the Payment Processing certificate (held by Authorize.net, not entered here).
     */
    private const PROCESSING_CERTIFICATE_CN = 'Apple Pay Payment Processing';

    /**
     * @param Pem $pem
     */
    public function __construct(
        private readonly Pem $pem,
    ) {
    }

    /**
     * Validate a PEM certificate and return it parsed.
     *
     * @param string $pem
     * @param string $merchantId Configured Apple Merchant ID; empty skips the Merchant ID check
     * @return OpenSSLCertificate
     * @throws LocalizedException
     */
    public function validate(
        string $pem,
        string $merchantId,
    ): OpenSSLCertificate {
        $certificate = $pem !== '' ? openssl_x509_read($pem) : false;
        if ($certificate === false) {
            throw new LocalizedException(
                __('The Apple Pay merchant identity certificate is not a valid PEM certificate.')
            );
        }

        $expiry = $this->pem->getCertificateExpiry($pem);
        if ($expiry !== null && $expiry < time()) {
            throw new LocalizedException(
                __(
                    'The Apple Pay merchant identity certificate expired on %1.',
                    date('Y-m-d', $expiry)
                )
            );
        }

        $this->validateSubject($certificate, $merchantId);

        return $certificate;
    }

    /**
     * Reject the wrong certificate before Apple does at checkout.
     *
     * Catches the Payment Processing certificate in place of the identity certificate, and an identity certificate
     * issued for a different Merchant ID.
     *
     * Apple names the Merchant ID in the identity certificate's subject, as the UID and in the CN
     * (UID=merchant.com.example, CN=Merchant ID: merchant.com.example, ...). It matches if either one names the
     * configured Merchant ID, so an unexpected subject layout can't block a valid certificate. A certificate naming no
     * Merchant ID is not checked against it.
     *
     * @param OpenSSLCertificate $certificate
     * @param string $merchantId
     * @return void
     * @throws LocalizedException
     */
    private function validateSubject(
        OpenSSLCertificate $certificate,
        string $merchantId,
    ): void {
        $info    = openssl_x509_parse($certificate);
        $subject = is_array($info) && is_array($info['subject'] ?? null) ? $info['subject'] : [];

        foreach ($this->getSubjectValues($subject, 'CN') as $commonName) {
            if (stripos($commonName, self::PROCESSING_CERTIFICATE_CN) === 0) {
                throw new LocalizedException(
                    __(
                        'This is the Apple Pay Payment Processing certificate, which Authorize.net holds. Use the'
                        . ' Apple Pay Merchant Identity certificate for your Merchant ID instead.'
                    )
                );
            }
        }

        $certificateMerchantIds = $this->pem->getMerchantIds($certificate);

        if ($certificateMerchantIds === [] || $merchantId === '') {
            return;
        }

        if (!in_array($merchantId, $certificateMerchantIds, true)) {
            throw new LocalizedException(
                __(
                    'The Apple Pay merchant identity certificate is for Merchant ID "%1", but the Apple Merchant ID'
                    . ' is "%2". Use the identity certificate for that Merchant ID, or correct the Merchant ID.',
                    implode('", "', $certificateMerchantIds),
                    $merchantId
                )
            );
        }
    }

    /**
     * Get the non-empty values of a certificate subject field (openssl returns an array for repeated fields).
     *
     * @param array $subject
     * @param string $field
     * @return string[]
     */
    private function getSubjectValues(
        array $subject,
        string $field,
    ): array {
        $values = array_map(
            static fn ($value): string => trim((string)$value),
            (array)($subject[$field] ?? [])
        );

        return array_values(array_filter($values, static fn (string $value): bool => $value !== ''));
    }
}
