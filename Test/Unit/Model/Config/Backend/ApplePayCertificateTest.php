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

namespace ParadoxLabs\Authnetcim\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use OpenSSLAsymmetricKey;
use ParadoxLabs\Authnetcim\Model\Config\Backend\ApplePayCertificate;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\CertificateUpload;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\PendingIdentityKey;
use ParadoxLabs\Authnetcim\Model\Wallet\Pem;
use ParadoxLabs\Authnetcim\Test\Unit\Model\Wallet\PemTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ApplePayCertificateTest extends TestCase
{
    /**
     * Cert-only save (key obscured) is checked against the saved key.
     *
     * @return void
     */
    public function testCertificateMatchingSavedKeySaves(): void
    {
        $key = $this->createKey();

        $model = $this->createModel(PemTest::createCertificate(30, $key), '******', $this->exportKey($key));
        $model->beforeSave();

        $this->assertStringStartsWith('-----BEGIN CERTIFICATE-----', (string)$model->getValue());
    }

    /**
     * @return void
     */
    public function testCertificateNotMatchingSavedKeyIsRejected(): void
    {
        $model = $this->createModel(
            PemTest::createCertificate(30, $this->createKey()),
            '******',
            $this->exportKey($this->createKey())
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('does not match the saved private key');
        $model->beforeSave();
    }

    /**
     * A key posted in the same save is checked by the key backend model, against the posted certificate.
     *
     * @return void
     */
    public function testNewKeyInSameSaveSkipsSavedKeyCheck(): void
    {
        $model = $this->createModel(
            PemTest::createCertificate(30, $this->createKey()),
            $this->exportKey($this->createKey()),
            $this->exportKey($this->createKey())
        );
        $model->beforeSave();

        $this->assertStringStartsWith('-----BEGIN CERTIFICATE-----', (string)$model->getValue());
    }

    /**
     * @return void
     */
    public function testExpiredCertificateIsRejected(): void
    {
        $model = $this->createModel(PemTest::EXPIRED_CERTIFICATE, '******', '');

        $this->expectException(LocalizedException::class);
        // The date renders in the server timezone (2020-12-31 or 2021-01-01).
        $this->expectExceptionMessage('certificate expired on');
        $model->beforeSave();
    }

    /**
     * An uploaded .cer file replaces the posted PEM text.
     *
     * @return void
     */
    public function testUploadedCertificateReplacesPostedText(): void
    {
        $key      = $this->createKey();
        $uploaded = PemTest::createCertificate(30, $key);

        $model = $this->createModel(
            PemTest::createCertificate(30, $this->createKey()),
            '******',
            $this->exportKey($key),
            uploadedCertificate: $uploaded
        );
        $model->beforeSave();

        $this->assertSame((new Pem())->normalize($uploaded), $model->getValue());
    }

    /**
     * A certificate for the pending key (renewal) is accepted even though it doesn't match the active key.
     *
     * @return void
     */
    public function testCertificateMatchingPendingKeySaves(): void
    {
        $pendingKey = $this->createKey();

        $model = $this->createModel(
            PemTest::createCertificate(30, $pendingKey),
            '******',
            $this->exportKey($this->createKey()),
            pendingKey: $this->exportKey($pendingKey)
        );
        $model->beforeSave();

        $this->assertStringStartsWith('-----BEGIN CERTIFICATE-----', (string)$model->getValue());
    }

    /**
     * First setup: no key saved yet, certificate for the pending key.
     *
     * @return void
     */
    public function testCertificateMatchingPendingKeyWithNoSavedKeySaves(): void
    {
        $pendingKey = $this->createKey();

        $model = $this->createModel(
            PemTest::createCertificate(30, $pendingKey),
            '',
            '',
            pendingKey: $this->exportKey($pendingKey)
        );
        $model->beforeSave();

        $this->assertStringStartsWith('-----BEGIN CERTIFICATE-----', (string)$model->getValue());
    }

    /**
     * @return void
     */
    public function testCertificateMatchingNeitherKeyIsRejected(): void
    {
        $model = $this->createModel(
            PemTest::createCertificate(30, $this->createKey()),
            '',
            '',
            pendingKey: $this->exportKey($this->createKey())
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('latest certificate request generated here');
        $model->beforeSave();
    }

    /**
     * An inherited (unposted) key field can't take the pending key, so the save must stop.
     *
     * @return void
     */
    public function testCertificateMatchingPendingKeyWithInheritedKeyFieldIsRejected(): void
    {
        $pendingKey = $this->createKey();

        $model = $this->createModel(
            PemTest::createCertificate(30, $pendingKey),
            null,
            '',
            pendingKey: $this->exportKey($pendingKey)
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Merchant Identity Private Key');
        $model->beforeSave();
    }

    /**
     * Merchant ID cases: certificate subject UID(s), subject CN (null = "Merchant ID: <first UID>"), posted Merchant ID
     * (null = inherited, not posted), saved Merchant ID at the save scope, and the expected error (null = saves).
     *
     * @return array
     */
    public static function merchantIdProvider(): array
    {
        $mismatch = 'is for Merchant ID "merchant.com.other", but the Apple Merchant ID is "merchant.com.example"';

        return [
            'posted match' => ['merchant.com.example', null, 'merchant.com.example', '', null],
            'posted match, padded' => ['merchant.com.example', null, ' merchant.com.example ', '', null],
            'posted mismatch' => ['merchant.com.other', null, 'merchant.com.example', '', $mismatch],
            'posted overrides saved' => [
                'merchant.com.example',
                null,
                'merchant.com.example',
                'merchant.com.old',
                null,
            ],
            'posted blank' => ['merchant.com.other', null, '', 'merchant.com.example', null],
            'inherited match' => ['merchant.com.example', null, null, 'merchant.com.example', null],
            'inherited mismatch' => ['merchant.com.other', null, null, 'merchant.com.example', $mismatch],
            'no merchant id' => ['merchant.com.other', null, null, '', null],
            'multiple uids' => [
                [
                    'merchant.com.old',
                    'merchant.com.example',
                ],
                null,
                'merchant.com.example',
                '',
                null,
            ],
            'uid differs, cn matches' => [
                'ABCDE12345',
                'Merchant ID: merchant.com.example',
                'merchant.com.example',
                '',
                null,
            ],
            'no uid, cn matches' => [null, 'Merchant ID: merchant.com.example', 'merchant.com.example', '', null],
            'no uid, cn mismatch' => [null, 'Merchant ID: merchant.com.other', 'merchant.com.example', '', $mismatch],
            'no merchant id in subject' => [null, 'Example Store', 'merchant.com.example', '', null],
        ];
    }

    /**
     * The certificate subject (UID or "Merchant ID:" CN) must name the Merchant ID being saved, or the saved one when
     * it's inherited.
     *
     * @dataProvider merchantIdProvider
     * @param string|string[]|null $uid
     * @param string|null $commonName
     * @param string|null $postedMerchantId
     * @param string $savedMerchantId
     * @param string|null $error
     * @return void
     */
    #[DataProvider('merchantIdProvider')]
    public function testCertificateMerchantId(
        string|array|null $uid,
        ?string $commonName,
        ?string $postedMerchantId,
        string $savedMerchantId,
        ?string $error,
    ): void {
        if (is_array($uid) && PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('openssl_csr_new() only accepts multi-valued subject fields on PHP 8.4+.');
        }

        $subject = [];
        if ($uid !== null) {
            $subject['UID']        = $uid;
            $subject['commonName'] = 'Merchant ID: ' . ((array)$uid)[0];
        }

        if ($commonName !== null) {
            $subject['commonName'] = $commonName;
        }

        $model = $this->createModel(
            PemTest::createCertificate(30, null, $this->createIdentitySubject($subject)),
            '******',
            '',
            $postedMerchantId !== null ? ['applepay_merchant_identifier' => $postedMerchantId] : [],
            $savedMerchantId
        );

        if ($error !== null) {
            $this->expectException(LocalizedException::class);
            $this->expectExceptionMessage($error);
        }

        $model->beforeSave();

        $this->assertStringStartsWith('-----BEGIN CERTIFICATE-----', (string)$model->getValue());
    }

    /**
     * The Payment Processing certificate (held by Authorize.net) carries the same UID, so it needs its own check.
     *
     * @return void
     */
    public function testPaymentProcessingCertificateIsRejected(): void
    {
        $model = $this->createModel(
            PemTest::createCertificate(
                30,
                null,
                [
                    'UID' => 'merchant.com.example',
                    'commonName' => 'Apple Pay Payment Processing:merchant.com.example',
                    'organizationalUnitName' => 'ABCDE12345',
                    'organizationName' => 'Example Inc',
                    'countryName' => 'US',
                ]
            ),
            '******',
            '',
            [
                'applepay_merchant_identifier' => 'merchant.com.example',
            ]
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('This is the Apple Pay Payment Processing certificate');
        $model->beforeSave();
    }

    /**
     * Subject in Apple's merchant identity certificate format, with the given fields added/overridden.
     *
     * @param array $fields
     * @return array
     */
    private function createIdentitySubject(array $fields): array
    {
        return $fields + [
            'commonName' => 'Merchant ID: merchant.com.example',
            'organizationalUnitName' => 'ABCDE12345',
            'organizationName' => 'Example Inc',
            'countryName' => 'US',
        ];
    }

    /**
     * Model saving at website scope (where the certificate is usually set), with the given posted fieldset values.
     *
     * @param string $certificate
     * @param string|null $postedKey Null: key field inherited (not posted)
     * @param string $savedKey
     * @param array $fieldsetData
     * @param string $savedMerchantId Merchant ID saved for the website
     * @param string $uploadedCertificate
     * @param string $pendingKey Pending key at the save scope
     * @return ApplePayCertificate
     */
    private function createModel(
        string $certificate,
        ?string $postedKey,
        string $savedKey,
        array $fieldsetData = [],
        string $savedMerchantId = '',
        string $uploadedCertificate = '',
        string $pendingKey = '',
    ): ApplePayCertificate {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path, string $scope, $scopeCode = null) => match (true) {
                $path === 'payment/authnetcim_wallet/applepay_identity_key' => $savedKey,
                $path === 'payment/authnetcim_wallet/applepay_merchant_identifier'
                    && $scope === 'websites'
                    && $scopeCode === 'base' => $savedMerchantId,
                default => null,
            }
        );

        $certificateUpload = $this->createMock(CertificateUpload::class);
        $certificateUpload->method('getPem')->willReturn($uploadedCertificate);

        $pendingIdentityKey = $this->createMock(PendingIdentityKey::class);
        $pendingIdentityKey->method('get')->willReturnCallback(
            static fn (string $scope, int $scopeId): string => $scope === 'websites' && $scopeId === 1 ? $pendingKey : ''
        );

        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));

        return new ApplePayCertificate(
            $context,
            $this->createMock(Registry::class),
            $scopeConfig,
            $this->createMock(TypeListInterface::class),
            new Pem(),
            $certificateUpload,
            $pendingIdentityKey,
            null,
            null,
            [
                'value' => $certificate,
                'path' => 'payment/authnetcim_wallet/applepay_identity_cert',
                'scope' => 'websites',
                'scope_id' => 1,
                'scope_code' => 'base',
                'fieldset_data' => array_filter(
                    [
                        'applepay_identity_key' => $postedKey,
                    ],
                    static fn ($value): bool => $value !== null
                ) + $fieldsetData,
            ],
        );
    }

    /**
     * @return OpenSSLAsymmetricKey
     */
    private function createKey(): OpenSSLAsymmetricKey
    {
        return openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
    }

    /**
     * @param OpenSSLAsymmetricKey $key
     * @return string
     */
    private function exportKey(OpenSSLAsymmetricKey $key): string
    {
        openssl_pkey_export($key, $pem);

        return $pem;
    }
}
