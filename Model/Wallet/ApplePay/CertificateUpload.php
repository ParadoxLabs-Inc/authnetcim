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

use Magento\Config\Model\Config\Backend\File\RequestData\RequestDataInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File;
use ParadoxLabs\Authnetcim\Model\Wallet\Pem;
use Throwable;

/**
 * The Merchant Identity certificate file (.cer) uploaded in the admin config save, as PEM.
 *
 * The temp file path comes from the PHP upload data ($_FILES, via RequestData), never from posted values.
 */
class CertificateUpload
{
    public const PATH = 'payment/authnetcim_wallet/applepay_identity_cert_file';

    private const MAX_BYTES = 65536;

    /**
     * @param RequestDataInterface $requestData
     * @param File $fileDriver
     * @param Pem $pem
     */
    public function __construct(
        private readonly RequestDataInterface $requestData,
        private readonly File $fileDriver,
        private readonly Pem $pem,
    ) {
    }

    /**
     * Get the uploaded certificate as PEM, or an empty string if no file was uploaded.
     *
     * The result is not validated as a certificate.
     *
     * @return string
     * @throws LocalizedException
     */
    public function getPem(): string
    {
        $tmpName = (string)$this->requestData->getTmpName(self::PATH);
        if ($tmpName === '') {
            return '';
        }

        try {
            $size     = (int)($this->fileDriver->stat($tmpName)['size'] ?? 0);
            $contents = $size <= self::MAX_BYTES ? $this->fileDriver->fileGetContents($tmpName) : null;
        } catch (Throwable) {
            throw new LocalizedException(__('The uploaded Apple Pay certificate file could not be read.'));
        }

        if ($contents === null) {
            throw new LocalizedException(__('The uploaded file is too large to be a certificate.'));
        }

        $pem = $this->pem->certificateFromFile($contents);
        if ($pem === '' || openssl_x509_parse($pem) === false) {
            throw new LocalizedException(
                __(
                    'The uploaded file is not a certificate. Upload the .cer file Apple Developer gives you for the'
                    . ' Apple Pay Merchant Identity Certificate.'
                )
            );
        }

        return $pem;
    }
}
