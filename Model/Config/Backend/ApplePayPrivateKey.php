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
use ParadoxLabs\Authnetcim\Model\Wallet\Pem;

/**
 * Validate, normalize, and encrypt the Apple Pay merchant identity private key on save.
 *
 * The admin field is single-line (obscured), so pasted keys lose their line breaks; normalize them back.
 */
class ApplePayPrivateKey extends Encrypted
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param EncryptorInterface $encryptor
     * @param Pem $pem
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
        $value = trim((string)$this->getValue());

        // Obscured or empty values are handled as-is by the parent (unchanged or cleared).
        if ($value !== '' && preg_match('/^\*+$/', $value) !== 1) {
            $pem = $this->pem->normalize($value);
            $key = $pem !== '' ? openssl_pkey_get_private($pem) : false;
            if ($key === false) {
                throw new LocalizedException(
                    __('The Apple Pay merchant identity private key is not a valid, unencrypted PEM private key.')
                );
            }

            $certificate = $this->pem->normalize((string)$this->getFieldsetDataValue('applepay_identity_cert'));
            if ($certificate !== '' && openssl_x509_check_private_key($certificate, $key) !== true) {
                throw new LocalizedException(
                    __('The Apple Pay merchant identity private key does not match the certificate.')
                );
            }

            $this->setValue($pem);
        }

        parent::beforeSave();
    }
}
