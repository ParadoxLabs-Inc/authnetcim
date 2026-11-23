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

namespace ParadoxLabs\Authnetcim\Model\Wallet;

use OpenSSLCertificate;

/**
 * PEM helpers for the Apple Pay merchant identity certificate and key.
 */
class Pem
{
    /**
     * Normalize a PEM block that may have lost its line breaks (e.g. pasted into a single-line admin field).
     *
     * Returns an empty string if no PEM block is found.
     *
     * @param string $pem
     * @return string
     */
    public function normalize(string $pem): string
    {
        if (preg_match('/-----BEGIN ([A-Z0-9 ]+)-----(.+?)-----END \1-----/s', $pem, $matches) !== 1) {
            return '';
        }

        $label = $matches[1];
        $body  = (string)preg_replace('/\s+/', '', $matches[2]);

        return sprintf(
            "-----BEGIN %s-----\n%s-----END %s-----\n",
            $label,
            chunk_split($body, 64, "\n"),
            $label
        );
    }

    /**
     * Get a certificate file's contents as PEM: binary DER (Apple's .cer download) is converted, PEM is normalized.
     *
     * The result is not validated; read it with openssl_x509_read() to check it is a certificate.
     *
     * @param string $contents
     * @return string
     */
    public function certificateFromFile(string $contents): string
    {
        if (str_contains($contents, '-----BEGIN')) {
            return $this->normalize($contents);
        }

        if ($contents === '') {
            return '';
        }

        return sprintf(
            "-----BEGIN CERTIFICATE-----\n%s-----END CERTIFICATE-----\n",
            chunk_split(base64_encode($contents), 64, "\n")
        );
    }

    /**
     * Whether a PEM private key belongs to the given certificate.
     *
     * @param OpenSSLCertificate|string $certificate
     * @param string $privateKey
     * @return bool
     */
    public function isKeyForCertificate(OpenSSLCertificate|string $certificate, string $privateKey): bool
    {
        $privateKey = $this->normalize($privateKey);
        $key        = $privateKey !== '' ? openssl_pkey_get_private($privateKey) : false;

        if (is_string($certificate)) {
            $certificate = $this->normalize($certificate);
        }

        if ($key === false || $certificate === '') {
            return false;
        }

        return openssl_x509_check_private_key($certificate, $key) === true;
    }

    /**
     * Get a certificate's expiry (validTo) as a unix timestamp, or null if it can't be read.
     *
     * @param string $pem
     * @return int|null
     */
    public function getCertificateExpiry(string $pem): ?int
    {
        $pem         = $this->normalize($pem);
        $certificate = $pem !== '' ? openssl_x509_read($pem) : false;

        if ($certificate === false) {
            return null;
        }

        $info = openssl_x509_parse($certificate);

        return is_array($info) && isset($info['validTo_time_t']) ? (int)$info['validTo_time_t'] : null;
    }

    /**
     * Get the Merchant ID(s) a certificate names: subject UID(s), and a "Merchant ID: ..." common name.
     *
     * @param OpenSSLCertificate|string $certificate
     * @return string[]
     */
    public function getMerchantIds(OpenSSLCertificate|string $certificate): array
    {
        if (is_string($certificate)) {
            $certificate = $this->normalize($certificate);
            $certificate = $certificate !== '' ? openssl_x509_read($certificate) : false;
        }

        $info    = $certificate !== false ? openssl_x509_parse($certificate) : false;
        $subject = is_array($info) && is_array($info['subject'] ?? null) ? $info['subject'] : [];
        $ids     = (array)($subject['UID'] ?? []);

        foreach ((array)($subject['CN'] ?? []) as $commonName) {
            if (preg_match('/^Merchant ID:\s*(\S+)$/i', trim((string)$commonName), $matches) === 1) {
                $ids[] = $matches[1];
            }
        }

        $ids = array_map(static fn ($id): string => trim((string)$id), $ids);

        return array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== '')));
    }

    /**
     * Get a certificate's start (validFrom) as a unix timestamp, or null if it can't be read.
     *
     * @param string $pem
     * @return int|null
     */
    public function getCertificateStart(string $pem): ?int
    {
        $pem         = $this->normalize($pem);
        $certificate = $pem !== '' ? openssl_x509_read($pem) : false;
        $info        = $certificate !== false ? openssl_x509_parse($certificate) : false;

        return is_array($info) && isset($info['validFrom_time_t']) ? (int)$info['validFrom_time_t'] : null;
    }

    /**
     * Whether a certificate is unreadable or past its expiry.
     *
     * @param string $pem
     * @return bool
     */
    public function isCertificateExpired(string $pem): bool
    {
        $expiry = $this->getCertificateExpiry($pem);

        return $expiry === null || $expiry < time();
    }
}
