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
namespace ParadoxLabs\Authnetcim\Test\Unit\Model\Wallet\ApplePay;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\Dir;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\CertificateRequest;
use ParadoxLabs\Authnetcim\Model\Wallet\Pem;
use PHPUnit\Framework\TestCase;

class CertificateRequestTest extends TestCase
{
    /**
     * Apple requires RSA 2048 for the Merchant Identity key; the CSR must carry that key's public half.
     *
     * @return void
     */
    public function testCreatesRsaKeyAndMatchingRequest(): void
    {
        $request = $this->createModel();

        $privateKey = $request->createPrivateKey();
        $details    = openssl_pkey_get_details(openssl_pkey_get_private($privateKey));

        $this->assertSame(OPENSSL_KEYTYPE_RSA, $details['type']);
        $this->assertSame(2048, $details['bits']);

        $csr = $request->createSigningRequest($privateKey, 'merchant.com.example');

        $this->assertStringStartsWith('-----BEGIN CERTIFICATE REQUEST-----', $csr);
        $this->assertSame(['CN' => 'merchant.com.example'], openssl_csr_get_subject($csr));
        $this->assertSame(
            $details['key'],
            openssl_pkey_get_details(openssl_csr_get_public_key($csr))['key']
        );
    }

    /**
     * Rebuilding the request from the same key (re-download) gives the same public key.
     *
     * @return void
     */
    public function testRequestCanBeRebuiltFromSameKey(): void
    {
        $request    = $this->createModel();
        $privateKey = $request->createPrivateKey();

        $first  = openssl_csr_get_public_key($request->createSigningRequest($privateKey, ''));
        $second = openssl_csr_get_public_key($request->createSigningRequest($privateKey, 'merchant.com.example'));

        $this->assertSame(openssl_pkey_get_details($first)['key'], openssl_pkey_get_details($second)['key']);
    }

    /**
     * @return void
     */
    public function testCommonNameFallsBackForMissingOrOddMerchantId(): void
    {
        $request = $this->createModel();

        $this->assertSame('merchant.com.example', $request->getCommonName(' merchant.com.example '));
        $this->assertSame('Apple Pay Merchant Identity', $request->getCommonName(''));
        $this->assertSame('Apple Pay Merchant Identity', $request->getCommonName('a/b'));
    }

    /**
     * @return void
     */
    public function testUnreadableKeyIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('could not be read');

        ($this->createModel())->createSigningRequest('not a key', '');
    }

    /**
     * @return CertificateRequest
     */
    private function createModel(): CertificateRequest
    {
        $moduleDir = $this->createMock(Dir::class);
        $moduleDir->method('getDir')
                  ->with('ParadoxLabs_Authnetcim', Dir::MODULE_ETC_DIR)
                  ->willReturn(dirname(__DIR__, 5) . '/etc');

        return new CertificateRequest(new Pem(), $moduleDir);
    }
}
