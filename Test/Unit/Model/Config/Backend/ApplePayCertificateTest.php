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
use ParadoxLabs\Authnetcim\Model\Wallet\Pem;
use ParadoxLabs\Authnetcim\Test\Unit\Model\Wallet\PemTest;
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
     * @param string $certificate
     * @param string $postedKey
     * @param string $savedKey
     * @return ApplePayCertificate
     */
    private function createModel(string $certificate, string $postedKey, string $savedKey): ApplePayCertificate
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => $path === 'payment/authnetcim_wallet/applepay_identity_key' ? $savedKey : null
        );

        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));

        return new ApplePayCertificate(
            $context,
            $this->createMock(Registry::class),
            $scopeConfig,
            $this->createMock(TypeListInterface::class),
            new Pem(),
            null,
            null,
            [
                'value' => $certificate,
                'path' => 'payment/authnetcim_wallet/applepay_identity_cert',
                'scope' => 'default',
                'scope_id' => 0,
                'fieldset_data' => [
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
