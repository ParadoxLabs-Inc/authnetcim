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

namespace ParadoxLabs\Authnetcim\Test\Unit\Model\Wallet;

use Magento\Framework\App\Config\ScopeConfigInterface;
use ParadoxLabs\Authnetcim\Model\Wallet\Config;
use ParadoxLabs\Authnetcim\Model\Wallet\Pem;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    /**
     * @return void
     */
    public function testApplePayEnabledWithCurrentCertificate(): void
    {
        $config = $this->createConfig(PemTest::createCertificate(30));

        $this->assertTrue($config->isApplePayEnabled(1));
    }

    /**
     * An expired identity certificate fails every merchant validation; Apple Pay must hide instead.
     *
     * @return void
     */
    public function testApplePayDisabledWithExpiredCertificate(): void
    {
        $config = $this->createConfig(PemTest::EXPIRED_CERTIFICATE);

        $this->assertFalse($config->isApplePayEnabled(1));
    }

    /**
     * @param string $certificate
     * @return Config
     */
    private function createConfig(string $certificate): Config
    {
        $values = [
            'payment/authnetcim/login' => 'login',
            'payment/authnetcim/trans_key' => 'key',
            'payment/authnetcim_wallet/applepay_merchant_identifier' => 'merchant.test',
            'payment/authnetcim_wallet/applepay_identity_cert' => $certificate,
            'payment/authnetcim_wallet/applepay_identity_key' => 'stored key',
        ];

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(true);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => $values[$path] ?? null
        );

        return new Config($scopeConfig, new Pem());
    }
}
