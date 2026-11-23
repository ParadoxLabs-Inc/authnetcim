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
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use OpenSSLAsymmetricKey;
use ParadoxLabs\Authnetcim\Model\Config\Backend\ApplePayCertificateFile;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\CertificateUpload;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\IdentityCertificateValidator;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\PendingIdentityKey;
use ParadoxLabs\Authnetcim\Model\Wallet\Pem;
use ParadoxLabs\Authnetcim\Test\Unit\Model\Wallet\PemTest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ApplePayCertificateFileTest extends TestCase
{
    private const CERT_PATH = 'payment/authnetcim_wallet/applepay_identity_cert';

    private const KEY_PATH = 'payment/authnetcim_wallet/applepay_identity_key';

    /**
     * @var array<string, string> Config writes: path => value
     */
    private array $written = [];

    private PendingIdentityKey&MockObject $pendingIdentityKey;

    /**
     * @return void
     */
    public function testNoFileIsIgnored(): void
    {
        $model = $this->createModel(UPLOAD_ERR_NO_FILE, '');
        $model->beforeSave();

        $this->assertFalse($model->isSaveAllowed());
        $this->assertSame([], $this->written);
    }

    /**
     * @return void
     */
    public function testUploadErrorIsReported(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('could not be uploaded');

        $this->createModel(UPLOAD_ERR_INI_SIZE, '')->beforeSave();
    }

    /**
     * Certificate for the pending key: saves the certificate and the key, then removes the pending key. The upload
     * field itself never saves a value.
     *
     * @return void
     */
    public function testCertificateForPendingKeySavesCertificateAndKey(): void
    {
        $key         = $this->createKey();
        $certificate = PemTest::createCertificate(30, $key);
        $pendingKey  = $this->exportKey($key);

        $model = $this->createModel(UPLOAD_ERR_OK, $certificate, $pendingKey, $this->exportKey($this->createKey()));
        $this->pendingIdentityKey->expects($this->once())->method('delete')->with('websites', 1);
        $model->beforeSave();

        $this->assertFalse($model->isSaveAllowed());
        $this->assertSame(
            [
                self::CERT_PATH => $certificate,
                self::KEY_PATH => 'enc:' . (new Pem())->normalize($pendingKey),
            ],
            $this->written
        );
    }

    /**
     * Certificate for a key set with config:set (request made outside Magento): saves only the certificate.
     *
     * @return void
     */
    public function testCertificateForSavedKeySavesCertificate(): void
    {
        $key         = $this->createKey();
        $certificate = PemTest::createCertificate(30, $key);

        $model = $this->createModel(
            UPLOAD_ERR_OK,
            $certificate,
            $this->exportKey($this->createKey()),
            $this->exportKey($key)
        );
        $this->pendingIdentityKey->expects($this->never())->method('delete');
        $model->beforeSave();

        $this->assertSame([self::CERT_PATH => $certificate], $this->written);
    }

    /**
     * @return void
     */
    public function testCertificateMatchingNeitherKeyIsRejected(): void
    {
        $model = $this->createModel(
            UPLOAD_ERR_OK,
            PemTest::createCertificate(30),
            $this->exportKey($this->createKey()),
            $this->exportKey($this->createKey())
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('not issued for the latest certificate request');
        $model->beforeSave();
    }

    /**
     * @return void
     */
    public function testCertificateWithNoKeyIsRejected(): void
    {
        $model = $this->createModel(UPLOAD_ERR_OK, PemTest::createCertificate(30));

        try {
            $model->beforeSave();
            $this->fail('Expected the certificate to be rejected.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('Generate Certificate Request', $e->getMessage());
        }

        $this->assertSame([], $this->written);
    }

    /**
     * The posted Merchant ID is checked against the certificate.
     *
     * @return void
     */
    public function testMerchantIdMismatchIsRejected(): void
    {
        $key         = $this->createKey();
        $certificate = PemTest::createCertificate(
            30,
            $key,
            [
                'UID' => 'merchant.com.other',
                'commonName' => 'Merchant ID: merchant.com.other',
            ]
        );

        $model = $this->createModel(UPLOAD_ERR_OK, $certificate, $this->exportKey($key), '', 'merchant.com.example');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('is for Merchant ID "merchant.com.other"');
        $model->beforeSave();
    }

    /**
     * Model saving at website scope.
     *
     * @param int $error
     * @param string $uploadedCertificate
     * @param string $pendingKey Pending key at the website
     * @param string $savedKey Key effective at the website
     * @param string|null $postedMerchantId
     * @return ApplePayCertificateFile
     */
    private function createModel(
        int $error,
        string $uploadedCertificate,
        string $pendingKey = '',
        string $savedKey = '',
        ?string $postedMerchantId = null,
    ): ApplePayCertificateFile {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path, string $scope, $scopeCode = null) => $path === self::KEY_PATH
                && $scope === 'websites'
                && $scopeCode === 'base' ? $savedKey : null
        );

        $certificateUpload = $this->createMock(CertificateUpload::class);
        $certificateUpload->method('getPem')->willReturn($uploadedCertificate);

        $this->pendingIdentityKey = $this->createMock(PendingIdentityKey::class);
        $this->pendingIdentityKey->method('get')->willReturnCallback(
            static fn (string $scope, int $scopeId): string => $scope === 'websites' && $scopeId === 1
                ? $pendingKey
                : ''
        );

        $configWriter = $this->createMock(WriterInterface::class);
        $configWriter->method('save')->willReturnCallback(
            function (string $path, string $value, string $scope, int $scopeId): void {
                $this->assertSame('websites', $scope);
                $this->assertSame(1, $scopeId);
                $this->written[$path] = $value;
            }
        );

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnCallback(static fn (string $value): string => 'enc:' . $value);

        $pem = new Pem();

        return new ApplePayCertificateFile(
            $context,
            $this->createMock(Registry::class),
            $scopeConfig,
            $this->createMock(TypeListInterface::class),
            $certificateUpload,
            new IdentityCertificateValidator($pem),
            $this->pendingIdentityKey,
            $pem,
            $configWriter,
            $encryptor,
            null,
            null,
            [
                'value' => [
                    'name' => 'merchant_id.cer',
                    'tmp_name' => '',
                    'error' => $error,
                ],
                'scope' => 'websites',
                'scope_id' => 1,
                'scope_code' => 'base',
                'fieldset_data' => $postedMerchantId !== null
                    ? [
                        'applepay_merchant_identifier' => $postedMerchantId,
                    ]
                    : [],
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
