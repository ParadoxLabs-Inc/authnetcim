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
use Magento\Framework\Module\Dir;
use Magento\Framework\Phrase;
use OpenSSLCertificateSigningRequest;
use ParadoxLabs\Authnetcim\Model\Wallet\Pem;

/**
 * Create the private key and certificate signing request (CSR) for an Apple Pay Merchant Identity certificate.
 *
 * Apple requires an RSA 2048-bit key for Merchant Identity certificates. Apple takes its own subject for the issued
 * certificate; the CSR's subject only labels the request.
 *
 * Uses the module's minimal OpenSSL config rather than the server's openssl.cnf, which can be missing (key generation
 * then fails) and adds sample subject fields to the request.
 */
class CertificateRequest
{
    private const DEFAULT_COMMON_NAME = 'Apple Pay Merchant Identity';

    private const OPENSSL_CONFIG_FILE = 'applepay-csr-openssl.cnf';

    /**
     * @param Pem $pem
     * @param Dir $moduleDir
     */
    public function __construct(
        private readonly Pem $pem,
        private readonly Dir $moduleDir,
    ) {
    }

    /**
     * Generate a new unencrypted RSA 2048 private key, as PEM.
     *
     * @return string
     * @throws LocalizedException
     */
    public function createPrivateKey(): string
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ] + $this->getOpenSslOptions());

        $pem = '';
        if ($key === false || openssl_pkey_export($key, $pem, null, $this->getOpenSslOptions()) !== true) {
            throw $this->createException(__('This server could not generate a private key.'));
        }

        return $pem;
    }

    /**
     * Create a certificate signing request (PEM) for the given private key.
     *
     * @param string $privateKey
     * @param string $merchantId Used as the request's common name, if valid
     * @return string
     * @throws LocalizedException
     */
    public function createSigningRequest(
        string $privateKey,
        string $merchantId,
    ): string {
        $privateKey = $this->pem->normalize($privateKey);
        $key        = $privateKey !== '' ? openssl_pkey_get_private($privateKey) : false;
        if ($key === false) {
            throw $this->createException(__('The private key for this certificate request could not be read.'));
        }

        $csr = openssl_csr_new(
            [
                'commonName' => $this->getCommonName($merchantId),
            ],
            $key,
            [
                'digest_alg' => 'sha256',
            ] + $this->getOpenSslOptions()
        );

        $pem = '';
        if (!$csr instanceof OpenSSLCertificateSigningRequest || openssl_csr_export($csr, $pem) !== true) {
            throw $this->createException(__('This server could not create the certificate request.'));
        }

        return $pem;
    }

    /**
     * Get the CSR common name: the Merchant ID if it looks like one, else a generic label.
     *
     * @param string $merchantId
     * @return string
     */
    public function getCommonName(string $merchantId): string
    {
        $merchantId = trim($merchantId);

        return preg_match('/^[A-Za-z0-9._-]{1,64}$/', $merchantId) === 1 ? $merchantId : self::DEFAULT_COMMON_NAME;
    }

    /**
     * @return array
     */
    private function getOpenSslOptions(): array
    {
        return [
            'config' => $this->moduleDir->getDir('ParadoxLabs_Authnetcim', Dir::MODULE_ETC_DIR)
                . '/' . self::OPENSSL_CONFIG_FILE,
        ];
    }

    /**
     * Build an exception with OpenSSL's reason, pointing to the manual process.
     *
     * @param Phrase $message
     * @return LocalizedException
     */
    private function createException(Phrase $message): LocalizedException
    {
        $errors = [];
        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return new LocalizedException(
            __(
                '%1 %2 You can create the certificate request with OpenSSL instead (see the extension README) and'
                . ' enter the certificate and private key manually.',
                $message,
                $errors !== [] ? '(' . implode('; ', array_slice($errors, 0, 3)) . ')' : ''
            )
        );
    }
}
