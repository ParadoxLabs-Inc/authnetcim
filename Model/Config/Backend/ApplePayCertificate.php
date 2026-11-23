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
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\IdentityCertificateValidator;
use ParadoxLabs\Authnetcim\Model\Wallet\Pem;

/**
 * Apple Pay Merchant Identity certificate (PEM), set with bin/magento config:set; not shown in admin.
 *
 * Admin setup saves the certificate through the .cer upload (ApplePayCertificateFile) instead. Set the private key
 * first: the certificate must match it.
 */
class ApplePayCertificate extends Value
{
    public const PATH = 'payment/authnetcim_wallet/applepay_identity_cert';

    private const KEY_PATH = 'payment/authnetcim_wallet/applepay_identity_key';

    private const MERCHANT_ID_PATH = 'payment/authnetcim_wallet/applepay_merchant_identifier';

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param Pem $pem
     * @param IdentityCertificateValidator $certificateValidator
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly Pem $pem,
        private readonly IdentityCertificateValidator $certificateValidator,
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
        $value = trim((string)$this->getValue());

        if ($value === '') {
            $this->setValue('');

            return parent::beforeSave();
        }

        $pem         = $this->pem->normalize($value);
        $certificate = $this->certificateValidator->validate($pem, $this->getScopeValue(self::MERCHANT_ID_PATH));

        // Decrypted by the config backend model metadata.
        $key = $this->pem->normalize($this->getScopeValue(self::KEY_PATH));
        if ($key === '') {
            throw new LocalizedException(
                __('Set the Apple Pay merchant identity private key (%1) before its certificate.', self::KEY_PATH)
            );
        }

        if (!$this->pem->isKeyForCertificate($certificate, $key)) {
            throw new LocalizedException(
                __('The Apple Pay merchant identity certificate does not match the saved private key.')
            );
        }

        $this->setValue($pem);

        return parent::beforeSave();
    }

    /**
     * @param string $path
     * @return string
     */
    private function getScopeValue(string $path): string
    {
        return trim((string)$this->_config->getValue(
            $path,
            $this->getScope() ?: ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            $this->getScopeCode()
        ));
    }
}
