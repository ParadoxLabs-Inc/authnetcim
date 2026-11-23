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
use ParadoxLabs\Authnetcim\Model\Config\Backend\ApplePayPrivateKey;
use ParadoxLabs\Authnetcim\Model\Wallet\Pem;
use PHPUnit\Framework\TestCase;

/**
 * Key set with bin/magento config:set.
 */
class ApplePayPrivateKeyTest extends TestCase
{
    /**
     * A key pasted on one line (as a shell argument may be) is normalized and encrypted.
     *
     * @return void
     */
    public function testValidKeyIsNormalizedAndEncrypted(): void
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        openssl_pkey_export($key, $pem);

        $model = $this->createModel(str_replace("\n", ' ', $pem));
        $model->beforeSave();

        $this->assertSame('enc:' . (new Pem())->normalize($pem), $model->getValue());
    }

    /**
     * @return void
     */
    public function testInvalidKeyIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('not a valid, unencrypted PEM private key');

        $this->createModel('-----BEGIN PRIVATE KEY-----nope-----END PRIVATE KEY-----')->beforeSave();
    }

    /**
     * @return void
     */
    public function testEmptyValueClears(): void
    {
        $model = $this->createModel('');
        $model->beforeSave();

        $this->assertSame('', (string)$model->getValue());
    }

    /**
     * @param string $value
     * @return ApplePayPrivateKey
     */
    private function createModel(string $value): ApplePayPrivateKey
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnCallback(static fn (string $value): string => 'enc:' . $value);

        return new ApplePayPrivateKey(
            $context,
            $this->createMock(Registry::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            $encryptor,
            new Pem(),
            null,
            null,
            [
                'value' => $value,
                'path' => ApplePayPrivateKey::PATH,
                'scope' => 'websites',
                'scope_id' => 1,
                'scope_code' => 'base',
            ],
        );
    }
}
