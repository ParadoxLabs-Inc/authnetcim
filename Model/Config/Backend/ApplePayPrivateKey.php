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

use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\CertificateUpload;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\PendingIdentityKey;
use ParadoxLabs\Authnetcim\Model\Wallet\Pem;

/**
 * Validate, normalize, and encrypt the Apple Pay merchant identity private key on save.
 *
 * The admin field is single-line (obscured), so pasted keys lose their line breaks; normalize them back.
 *
 * When the key is left unchanged and the certificate saved with it was issued for the pending key (from Generate
 * Certificate Request), the pending key becomes the active key.
 */
class ApplePayPrivateKey extends Encrypted
{
    private bool $pendingKeyActivated = false;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param EncryptorInterface $encryptor
     * @param Pem $pem
     * @param CertificateUpload $certificateUpload
     * @param PendingIdentityKey $pendingIdentityKey
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        EncryptorInterface $encryptor,
        private readonly Pem $pem,
        private readonly CertificateUpload $certificateUpload,
        private readonly PendingIdentityKey $pendingIdentityKey,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = [],
    ) {
        parent::__construct(
            $context,
            $registry,
            $config,
            $cacheTypeList,
            $encryptor,
            $resource,
            $resourceCollection,
            $data,
        );
    }

    /**
     * @return void
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value       = trim((string)$this->getValue());
        $certificate = $this->getPostedCertificate();

        if ($value !== '' && preg_match('/^\*+$/', $value) !== 1) {
            $pem = $this->pem->normalize($value);
            $key = $pem !== '' ? openssl_pkey_get_private($pem) : false;
            if ($key === false) {
                throw new LocalizedException(
                    __('The Apple Pay merchant identity private key is not a valid, unencrypted PEM private key.')
                );
            }

            if ($certificate !== '' && !$this->pem->isKeyForCertificate($certificate, $pem)) {
                throw new LocalizedException(
                    __('The Apple Pay merchant identity private key does not match the certificate.')
                );
            }

            $this->setValue($pem);
        } elseif ($certificate !== '') {
            $this->activatePendingKey($certificate);
        }

        // Obscured or empty values are handled as-is by the parent (unchanged or cleared).
        parent::beforeSave();
    }

    /**
     * Remove the pending key once it has been saved as the active key.
     *
     * @return $this
     */
    public function afterSave()
    {
        if ($this->pendingKeyActivated) {
            $this->pendingIdentityKey->delete($this->getConfigScope(), (int)$this->getScopeId());
        }

        return parent::afterSave();
    }

    /**
     * Make the pending key active if the certificate being saved was issued for it.
     *
     * @param string $certificate
     * @return void
     */
    private function activatePendingKey(string $certificate): void
    {
        $pendingKey = $this->pendingIdentityKey->get($this->getConfigScope(), (int)$this->getScopeId());

        if ($pendingKey !== '' && $this->pem->isKeyForCertificate($certificate, $pendingKey)) {
            $this->setValue($this->pem->normalize($pendingKey));
            $this->pendingKeyActivated = true;
        }
    }

    /**
     * Get the certificate saved alongside this key: an uploaded .cer file, else the posted PEM text.
     *
     * @return string
     * @throws LocalizedException
     */
    private function getPostedCertificate(): string
    {
        $uploaded = $this->certificateUpload->getPem();

        return $uploaded !== ''
            ? $uploaded
            : $this->pem->normalize((string)$this->getFieldsetDataValue('applepay_identity_cert'));
    }

    /**
     * @return string
     */
    private function getConfigScope(): string
    {
        return $this->getScope() ?: ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
    }
}
