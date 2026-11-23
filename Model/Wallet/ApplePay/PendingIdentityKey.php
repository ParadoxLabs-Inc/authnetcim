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

use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Private key generated for an Apple Pay certificate request, kept until Apple's certificate for it is saved.
 *
 * Stored apart from the active key so a renewal doesn't break Apple Pay before the new certificate arrives. Read
 * straight from the database at the exact scope (not through config cache or inheritance): it is written outside the
 * config save, and only belongs to the scope it was generated for.
 */
class PendingIdentityKey
{
    public const PATH = 'payment/authnetcim_wallet/applepay_identity_key_pending';

    /**
     * @param WriterInterface $configWriter
     * @param CollectionFactory $configCollectionFactory
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly CollectionFactory $configCollectionFactory,
        private readonly EncryptorInterface $encryptor,
    ) {
    }

    /**
     * Save a newly generated private key for the scope, replacing any earlier pending key.
     *
     * @param string $privateKey
     * @param string $scope
     * @param int $scopeId
     * @return void
     */
    public function save(
        string $privateKey,
        string $scope,
        int $scopeId,
    ): void {
        $this->configWriter->save(self::PATH, $this->encryptor->encrypt($privateKey), $scope, $scopeId);
    }

    /**
     * Get the pending private key (PEM) for the scope, or an empty string if there is none.
     *
     * @param string $scope
     * @param int $scopeId
     * @return string
     */
    public function get(string $scope, int $scopeId): string
    {
        $value = (string)$this->load($scope, $scopeId)?->getData('value');

        return $value !== '' ? $this->encryptor->decrypt($value) : '';
    }

    /**
     * Get when the pending key for the scope was generated (database time), or null if there is none.
     *
     * @param string $scope
     * @param int $scopeId
     * @return string|null
     */
    public function getCreatedAt(string $scope, int $scopeId): ?string
    {
        $updatedAt = $this->load($scope, $scopeId)?->getData('updated_at');

        return $updatedAt ? (string)$updatedAt : null;
    }

    /**
     * Remove the pending key for the scope.
     *
     * @param string $scope
     * @param int $scopeId
     * @return void
     */
    public function delete(string $scope, int $scopeId): void
    {
        $this->configWriter->delete(self::PATH, $scope, $scopeId);
    }

    /**
     * @param string $scope
     * @param int $scopeId
     * @return DataObject|null
     */
    private function load(string $scope, int $scopeId): ?DataObject
    {
        $collection = $this->configCollectionFactory->create();
        $collection->addFieldToFilter('path', self::PATH)
                   ->addFieldToFilter('scope', $scope)
                   ->addFieldToFilter('scope_id', $scopeId)
                   ->setPageSize(1);

        $item = $collection->getFirstItem();

        return $item->getData('value') !== null ? $item : null;
    }
}
