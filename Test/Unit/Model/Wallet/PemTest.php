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

use OpenSSLAsymmetricKey;
use ParadoxLabs\Authnetcim\Model\Wallet\Pem;
use PHPUnit\Framework\TestCase;

class PemTest extends TestCase
{
    /**
     * A key pasted into a single-line field loses its line breaks; normalize must restore valid PEM.
     *
     * @return void
     */
    public function testRestoresFlattenedKey(): void
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $pem);

        $flattened  = str_replace("\n", ' ', $pem);
        $normalized = (new Pem())->normalize($flattened);

        $this->assertNotFalse(openssl_pkey_get_private($normalized));
        $this->assertStringStartsWith("-----BEGIN PRIVATE KEY-----\n", $normalized);
    }

    /**
     * @return void
     */
    public function testNonPemIsEmpty(): void
    {
        $this->assertSame('', (new Pem())->normalize('not a key'));
    }

    /**
     * @return void
     */
    public function testCurrentCertificateIsNotExpired(): void
    {
        $pem    = self::createCertificate(30);
        $expiry = (new Pem())->getCertificateExpiry($pem);

        $this->assertNotNull($expiry);
        $this->assertGreaterThan(time(), $expiry);
        $this->assertFalse((new Pem())->isCertificateExpired($pem));
    }

    /**
     * @return void
     */
    public function testExpiredCertificate(): void
    {
        $pem = self::EXPIRED_CERTIFICATE;

        $this->assertSame(1609459200, (new Pem())->getCertificateExpiry($pem));
        $this->assertTrue((new Pem())->isCertificateExpired($pem));
    }

    /**
     * An unreadable certificate can't pass merchant validation either, so it counts as expired.
     *
     * @return void
     */
    public function testUnreadableCertificateIsExpired(): void
    {
        $this->assertNull((new Pem())->getCertificateExpiry('not a certificate'));
        $this->assertTrue((new Pem())->isCertificateExpired('not a certificate'));
    }

    /**
     * Self-signed EC certificate valid for the given number of days.
     *
     * @param int $days
     * @param OpenSSLAsymmetricKey|null $key
     * @return string
     */
    public static function createCertificate(int $days, ?OpenSSLAsymmetricKey $key = null): string
    {
        $key = $key ?? openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $csr  = openssl_csr_new(['commonName' => 'merchant.test'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, $days, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $pem);

        return $pem;
    }

    /**
     * Public certificate (no key) valid 2020-01-01 to 2021-01-01.
     */
    public const EXPIRED_CERTIFICATE = <<<PEM
-----BEGIN CERTIFICATE-----
MIIBGTCBwaADAgECAgEBMAoGCCqGSM49BAMCMBcxFTATBgNVBAMMDGV4cGlyZWQt
dGVzdDAeFw0yMDAxMDEwMDAwMDBaFw0yMTAxMDEwMDAwMDBaMBcxFTATBgNVBAMM
DGV4cGlyZWQtdGVzdDBZMBMGByqGSM49AgEGCCqGSM49AwEHA0IABInPPMzJFTEG
5f3QMm5u8jhv+FWW0KGBFsNc//49MLKn/MIXI/r0DEAYb8U+CiiEBRaSlpdVU+eg
V32F3IXIWpcwCgYIKoZIzj0EAwIDRwAwRAIgR3dN0GGmN5AflnRDf/fN5njKdUka
gMmSZygNYncrxSgCIHswYiOKfXx4iU9elog+Cxx58xoKicUMyKhNQp1MrQLD
-----END CERTIFICATE-----

PEM;
}
