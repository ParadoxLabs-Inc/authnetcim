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
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\IdentityCertificateValidator;
use ParadoxLabs\Authnetcim\Model\Wallet\Pem;
use ParadoxLabs\Authnetcim\Test\Unit\Model\Wallet\PemTest;
use PHPUnit\Framework\TestCase;

/**
 * Certificate set with bin/magento config:set.
 */
class ApplePayCertificateTest extends TestCase
{
    /**
     * @return void
     */
    public function testCertificateMatchingSavedKeySaves(): void
    {
        $key = $this->createKey();

        $model = $this->createModel(PemTest::createCertificate(30, $key), $this->exportKey($key));
        $model->beforeSave();

        $this->assertStringStartsWith('-----BEGIN CERTIFICATE-----', (string)$model->getValue());
    }

    /**
     * @return void
     */
    public function testCertificateNotMatchingSavedKeyIsRejected(): void
    {
        $model = $this->createModel(PemTest::createCertificate(30), $this->exportKey($this->createKey()));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('does not match the saved private key');
        $model->beforeSave();
    }

    /**
     * @return void
     */
    public function testCertificateWithNoSavedKeyIsRejected(): void
    {
        $model = $this->createModel(PemTest::createCertificate(30), '');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('payment/authnetcim_wallet/applepay_identity_key');
        $model->beforeSave();
    }

    /**
     * @return void
     */
    public function testInvalidCertificateIsRejected(): void
    {
        $model = $this->createModel(PemTest::EXPIRED_CERTIFICATE, '');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('certificate expired on');
        $model->beforeSave();
    }

    /**
     * The saved Merchant ID for the scope is checked.
     *
     * @return void
     */
    public function testMerchantIdMismatchIsRejected(): void
    {
        $key   = $this->createKey();
        $model = $this->createModel(
            PemTest::createCertificate(
                30,
                $key,
                [
                    'UID' => 'merchant.com.other',
                    'commonName' => 'Merchant ID: merchant.com.other',
                ]
            ),
            $this->exportKey($key),
            'merchant.com.example'
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('is for Merchant ID "merchant.com.other"');
        $model->beforeSave();
    }

    /**
     * @return void
     */
    public function testEmptyValueClears(): void
    {
        $model = $this->createModel('  ', '');
        $model->beforeSave();

        $this->assertSame('', $model->getValue());
    }

    /**
     * Model saving at website scope.
     *
     * @param string $certificate
     * @param string $savedKey Key effective at the website
     * @param string $savedMerchantId Merchant ID effective at the website
     * @return ApplePayCertificate
     */
    private function createModel(
        string $certificate,
        string $savedKey,
        string $savedMerchantId = '',
    ): ApplePayCertificate {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path, string $scope, $scopeCode = null) => match (true) {
                $scope !== 'websites' || $scopeCode !== 'base' => null,
                $path === 'payment/authnetcim_wallet/applepay_identity_key' => $savedKey,
                $path === 'payment/authnetcim_wallet/applepay_merchant_identifier' => $savedMerchantId,
                default => null,
            }
        );

        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));

        $pem = new Pem();

        return new ApplePayCertificate(
            $context,
            $this->createMock(Registry::class),
            $scopeConfig,
            $this->createMock(TypeListInterface::class),
            $pem,
            new IdentityCertificateValidator($pem),
            null,
            null,
            [
                'value' => $certificate,
                'path' => ApplePayCertificate::PATH,
                'scope' => 'websites',
                'scope_id' => 1,
                'scope_code' => 'base',
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
