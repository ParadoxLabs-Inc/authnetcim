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
