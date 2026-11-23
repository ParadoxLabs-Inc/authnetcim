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
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use OpenSSLCertificate;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\CertificateUpload;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\PendingIdentityKey;
use ParadoxLabs\Authnetcim\Model\Wallet\Pem;

/**
 * Validate and normalize the Apple Pay merchant identity certificate on save.
 *
 * An uploaded .cer file (CertificateUpload) replaces the posted PEM text.
 */
class ApplePayCertificate extends Value
{
    private const MERCHANT_ID_PATH = 'payment/authnetcim_wallet/applepay_merchant_identifier';

    /**
     * Subject CN prefix of the Payment Processing certificate (held by Authorize.net, not entered here).
     */
    private const PROCESSING_CERTIFICATE_CN = 'Apple Pay Payment Processing';

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param Pem $pem
     * @param CertificateUpload $certificateUpload
     * @param PendingIdentityKey $pendingIdentityKey
     * @param ManagerInterface $messageManager
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
        private readonly CertificateUpload $certificateUpload,
        private readonly PendingIdentityKey $pendingIdentityKey,
        private readonly ManagerInterface $messageManager,
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
        $uploaded = $this->certificateUpload->getPem();
        $value    = $uploaded !== '' ? $uploaded : trim((string)$this->getValue());

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

        $expiry = $this->pem->getCertificateExpiry($pem);
        if ($expiry !== null && $expiry < time()) {
            throw new LocalizedException(
                __(
                    'The Apple Pay merchant identity certificate expired on %1.',
                    date('Y-m-d', $expiry)
                )
            );
        }

        $this->validateSubject($certificate);
        $this->validateStoredKey($certificate);

        $this->setValue($pem);

        return parent::beforeSave();
    }

    /**
     * Reject the wrong certificate before Apple does at checkout.
     *
     * Catches the Payment Processing certificate pasted instead of the identity certificate, and an identity
     * certificate issued for a different Merchant ID.
     *
     * Apple names the Merchant ID in the identity certificate's subject, as the UID and in the CN
     * (UID=merchant.com.example, CN=Merchant ID: merchant.com.example, ...). It matches if either one names the
     * configured Merchant ID, so an unexpected subject layout can't block a valid certificate. A certificate naming no
     * Merchant ID is not checked against it.
     *
     * @param OpenSSLCertificate $certificate
     * @return void
     * @throws LocalizedException
     */
    private function validateSubject(OpenSSLCertificate $certificate): void
    {
        $info    = openssl_x509_parse($certificate);
        $subject = is_array($info) && is_array($info['subject'] ?? null) ? $info['subject'] : [];

        foreach ($this->getSubjectValues($subject, 'CN') as $commonName) {
            if (stripos($commonName, self::PROCESSING_CERTIFICATE_CN) === 0) {
                throw new LocalizedException(
                    __(
                        'This is the Apple Pay Payment Processing certificate, which Authorize.net holds. Enter the'
                        . ' Apple Pay Merchant Identity certificate for your Merchant ID instead.'
                    )
                );
            }
        }

        $certificateMerchantIds = $this->pem->getMerchantIds($certificate);
        $merchantId             = $this->getMerchantIdentifier();

        if ($certificateMerchantIds === [] || $merchantId === '') {
            return;
        }

        if (!in_array($merchantId, $certificateMerchantIds, true)) {
            throw new LocalizedException(
                __(
                    'The Apple Pay merchant identity certificate is for Merchant ID "%1", but the Apple Merchant ID'
                    . ' is "%2". Use the identity certificate for that Merchant ID, or correct the Merchant ID.',
                    implode('", "', $certificateMerchantIds),
                    $merchantId
                )
            );
        }
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

    /**
     * Get the non-empty values of a certificate subject field (openssl returns an array for repeated fields).
     *
     * @param array $subject
     * @param string $field
     * @return string[]
     */
    private function getSubjectValues(array $subject, string $field): array
    {
        $values = array_map(
            static fn ($value): string => trim((string)$value),
            (array)($subject[$field] ?? [])
        );

        return array_values(array_filter($values, static fn (string $value): bool => $value !== ''));
    }

    /**
     * Check a new certificate against the saved or pending private key, when no new key is posted in the same save.
     *
     * A newly posted key is checked against the posted certificate by ApplePayPrivateKey instead. A certificate for
     * the pending key (from Generate Certificate Request) is accepted; ApplePayPrivateKey then makes that key active.
     *
     * @param OpenSSLCertificate $certificate
     * @return void
     * @throws LocalizedException
     */
    private function validateStoredKey(OpenSSLCertificate $certificate): void
    {
        $postedKey = $this->getFieldsetDataValue('applepay_identity_key');
        $postedKey = $postedKey !== null ? trim((string)$postedKey) : null;

        if ($postedKey !== null && $postedKey !== '' && preg_match('/^\*+$/', $postedKey) !== 1) {
            return;
        }

        $pendingKey = $this->pendingIdentityKey->get(
            $this->getScope() ?: ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            (int)$this->getScopeId()
        );

        if ($pendingKey !== '' && $this->pem->isKeyForCertificate($certificate, $pendingKey)) {
            // The key field is not posted while it inherits its value, so it could not take the pending key.
            if ($postedKey === null) {
                throw new LocalizedException(
                    __(
                        'To save this certificate, first uncheck "Use Default" / "Use Website" for Merchant Identity'
                        . ' Private Key (PEM), so the key generated for its certificate request can be saved with it.'
                    )
                );
            }

            return;
        }

        // Obscured (unchanged) key: compare with the saved one, decrypted by the config backend model metadata.
        $storedKey = $this->pem->normalize((string)$this->_config->getValue(
            'payment/authnetcim_wallet/applepay_identity_key',
            $this->getScope() ?: ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            $this->getScopeCode()
        ));

        // No key to check against: save, but say so when a certificate request is waiting for a different certificate.
        if ($storedKey === '') {
            if ($pendingKey !== '') {
                $this->messageManager->addWarningMessage(
                    __(
                        'The Apple Pay merchant identity certificate was not issued for the latest certificate'
                        . ' request generated here, and no private key is saved for it. Apple Pay will not work'
                        . ' until you upload the certificate Apple issued for that request.'
                    )
                );
            }

            return;
        }

        if (!$this->pem->isKeyForCertificate($certificate, $storedKey)) {
            throw new LocalizedException(
                $pendingKey !== ''
                    ? __(
                        'The Apple Pay merchant identity certificate does not match the saved private key or the'
                        . ' latest certificate request generated here. Upload the certificate Apple issued for the'
                        . ' latest request.'
                    )
                    : __('The Apple Pay merchant identity certificate does not match the saved private key.')
            );
        }
    }
}
