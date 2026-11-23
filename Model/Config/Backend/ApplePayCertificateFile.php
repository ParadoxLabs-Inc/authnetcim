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

namespace ParadoxLabs\Authnetcim\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\CertificateUpload;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\IdentityCertificateValidator;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\PendingIdentityKey;
use ParadoxLabs\Authnetcim\Model\Wallet\Pem;

/**
 * Apple Pay Merchant Identity certificate upload (.cer).
 *
 * Never saved itself: saves the certificate, and the pending key from Generate Certificate Request it was issued for,
 * to the certificate and key paths at the same scope.
 */
class ApplePayCertificateFile extends Value
{
    private const MERCHANT_ID_PATH = 'payment/authnetcim_wallet/applepay_merchant_identifier';

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param CertificateUpload $certificateUpload
     * @param IdentityCertificateValidator $certificateValidator
     * @param PendingIdentityKey $pendingIdentityKey
     * @param Pem $pem
     * @param WriterInterface $configWriter
     * @param EncryptorInterface $encryptor
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly CertificateUpload $certificateUpload,
        private readonly IdentityCertificateValidator $certificateValidator,
        private readonly PendingIdentityKey $pendingIdentityKey,
        private readonly Pem $pem,
        private readonly WriterInterface $configWriter,
        private readonly EncryptorInterface $encryptor,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = [],
    ) {
        parent::__construct(
            $context,
            $registry,
            $config,
            $cacheTypeList,
            $resource,
            $resourceCollection,
            $data,
        );
    }

    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $this->_dataSaveAllowed = false;

        $file  = $this->getValue();
        $error = is_array($file) ? (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_NO_FILE) {
            return $this;
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new LocalizedException(
                __('The Apple Pay certificate file could not be uploaded (error %1). Please try again.', $error)
            );
        }

        $pem = $this->certificateUpload->getPem();
        if ($pem === '') {
            return $this;
        }

        $certificate = $this->certificateValidator->validate($pem, $this->getMerchantIdentifier());
        $scope       = $this->getScope() ?: ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
        $scopeId     = (int)$this->getScopeId();
        $pendingKey  = $this->pendingIdentityKey->get($scope, $scopeId);

        if ($pendingKey !== '' && $this->pem->isKeyForCertificate($certificate, $pendingKey)) {
            $this->configWriter->save(ApplePayCertificate::PATH, $pem, $scope, $scopeId);
            $this->configWriter->save(
                ApplePayPrivateKey::PATH,
                $this->encryptor->encrypt($this->pem->normalize($pendingKey)),
                $scope,
                $scopeId
            );
            $this->pendingIdentityKey->delete($scope, $scopeId);

            return $this;
        }

        // A key set with config:set, for a certificate request made outside Magento. Decrypted by the config backend
        // model metadata.
        $savedKey = $this->pem->normalize((string)$this->_config->getValue(
            ApplePayPrivateKey::PATH,
            $scope,
            $this->getScopeCode()
        ));

        if ($savedKey !== '' && $this->pem->isKeyForCertificate($certificate, $savedKey)) {
            $this->configWriter->save(ApplePayCertificate::PATH, $pem, $scope, $scopeId);

            return $this;
        }

        throw new LocalizedException(
            $pendingKey !== ''
                ? __(
                    'This Apple Pay certificate was not issued for the latest certificate request generated here.'
                    . ' Upload the certificate Apple issued for that request.'
                )
                : __(
                    'This Apple Pay certificate was not issued for a certificate request generated here. Click'
                    . ' Generate Certificate Request, then create the certificate in Apple Developer with that file.'
                )
        );
    }

    /**
     * Get the Merchant ID saved with this certificate: the posted value, or the saved one when it's inherited.
     *
     * @return string
     */
    private function getMerchantIdentifier(): string
    {
        $postedMerchantId = $this->getFieldsetDataValue('applepay_merchant_identifier');
        if ($postedMerchantId !== null) {
            return trim((string)$postedMerchantId);
        }

        return trim((string)$this->_config->getValue(
            self::MERCHANT_ID_PATH,
            $this->getScope() ?: ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            $this->getScopeCode()
        ));
    }
}
