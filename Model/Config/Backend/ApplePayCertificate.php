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
use ParadoxLabs\Authnetcim\Model\Wallet\Pem;

/**
 * Validate and normalize the Apple Pay merchant identity certificate on save.
 */
class ApplePayCertificate extends Value
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
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
        $certificate = $pem !== '' ? openssl_x509_read($pem) : false;
        if ($certificate === false) {
            throw new LocalizedException(
                __('The Apple Pay merchant identity certificate is not a valid PEM certificate.')
            );
        }

        $info = openssl_x509_parse($certificate);
        if (is_array($info) && isset($info['validTo_time_t']) && (int)$info['validTo_time_t'] < time()) {
            throw new LocalizedException(
                __(
                    'The Apple Pay merchant identity certificate expired on %1.',
                    date('Y-m-d', (int)$info['validTo_time_t'])
                )
            );
        }

        $this->setValue($pem);

        return parent::beforeSave();
    }
}
