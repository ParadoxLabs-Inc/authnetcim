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
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use OpenSSLAsymmetricKey;
use ParadoxLabs\Authnetcim\Model\Config\Backend\ApplePayPrivateKey;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\CertificateUpload;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\PendingIdentityKey;
use ParadoxLabs\Authnetcim\Model\Wallet\Pem;
use ParadoxLabs\Authnetcim\Test\Unit\Model\Wallet\PemTest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ApplePayPrivateKeyTest extends TestCase
{
    private PendingIdentityKey&MockObject $pendingIdentityKey;

    /**
     * Unchanged key + uploaded certificate for the pending key: the pending key becomes active, then is removed.
     *
     * @return void
     */
    public function testPendingKeyActivatedByUploadedCertificate(): void
    {
        $pendingKey = $this->createKey();
        $pendingPem = $this->exportKey($pendingKey);

        $model = $this->createModel('******', '', PemTest::createCertificate(30, $pendingKey), $pendingPem);
        $model->beforeSave();

        $this->assertTrue($model->isSaveAllowed());
        $this->assertSame('enc:' . (new Pem())->normalize($pendingPem), $model->getValue());

        $this->pendingIdentityKey->expects($this->once())->method('delete')->with('websites', 1);
        $model->afterSave();
    }

    /**
     * First setup: empty key field, certificate pasted as PEM text.
     *
     * @return void
     */
    public function testPendingKeyActivatedByPostedCertificate(): void
    {
        $pendingKey = $this->createKey();
        $pendingPem = $this->exportKey($pendingKey);

        $model = $this->createModel('', PemTest::createCertificate(30, $pendingKey), '', $pendingPem);
        $model->beforeSave();

        $this->assertSame('enc:' . (new Pem())->normalize($pendingPem), $model->getValue());
    }

    /**
     * A certificate for some other key leaves the saved key (and the pending one) alone.
     *
     * @return void
     */
    public function testPendingKeyKeptWhenCertificateDoesNotMatch(): void
    {
        $model = $this->createModel(
            '******',
            PemTest::createCertificate(30, $this->createKey()),
            '',
            $this->exportKey($this->createKey())
        );
        $model->beforeSave();

        $this->assertFalse($model->isSaveAllowed());

        $this->pendingIdentityKey->expects($this->never())->method('delete');
        $model->afterSave();
    }

    /**
     * A manually entered key is checked against the uploaded certificate.
     *
     * @return void
     */
    public function testPostedKeyNotMatchingUploadedCertificateIsRejected(): void
    {
        $model = $this->createModel(
            $this->exportKey($this->createKey()),
            '',
            PemTest::createCertificate(30, $this->createKey()),
            ''
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('does not match the certificate');
        $model->beforeSave();
    }

    /**
     * @return void
     */
    public function testPostedKeyMatchingCertificateSaves(): void
    {
        $key = $this->createKey();
        $pem = $this->exportKey($key);

        $model = $this->createModel(str_replace("\n", ' ', $pem), PemTest::createCertificate(30, $key), '', '');
        $model->beforeSave();

        $this->assertSame('enc:' . (new Pem())->normalize($pem), $model->getValue());
    }

    /**
     * Key model saving at website scope.
     *
     * @param string $postedKey
     * @param string $postedCertificate PEM text posted in the certificate field
     * @param string $uploadedCertificate
     * @param string $pendingKey
     * @return ApplePayPrivateKey
     */
    private function createModel(
        string $postedKey,
        string $postedCertificate,
        string $uploadedCertificate,
        string $pendingKey,
    ): ApplePayPrivateKey {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnCallback(static fn (string $value): string => 'enc:' . $value);

        $certificateUpload = $this->createMock(CertificateUpload::class);
        $certificateUpload->method('getPem')->willReturn($uploadedCertificate);

        $this->pendingIdentityKey = $this->createMock(PendingIdentityKey::class);
        $this->pendingIdentityKey->method('get')->willReturnCallback(
            static fn (string $scope, int $scopeId): string => $scope === 'websites' && $scopeId === 1 ? $pendingKey : ''
        );

        return new ApplePayPrivateKey(
            $context,
            $this->createMock(Registry::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            $encryptor,
            new Pem(),
            $certificateUpload,
            $this->pendingIdentityKey,
            null,
            null,
            [
                'value' => $postedKey,
                'path' => 'payment/authnetcim_wallet/applepay_identity_key',
                'scope' => 'websites',
                'scope_id' => 1,
                'scope_code' => 'base',
                'fieldset_data' => [
                    'applepay_identity_cert' => $postedCertificate,
                    'applepay_identity_key' => $postedKey,
                ],
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
