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
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\IdentityCertificateValidator;
use ParadoxLabs\Authnetcim\Model\Wallet\Pem;
use ParadoxLabs\Authnetcim\Test\Unit\Model\Wallet\PemTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IdentityCertificateValidatorTest extends TestCase
{
    /**
     * @return void
     */
    public function testInvalidCertificateIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('not a valid PEM certificate');

        $this->createValidator()->validate('', '');
    }

    /**
     * @return void
     */
    public function testExpiredCertificateIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        // The date renders in the server timezone (2020-12-31 or 2021-01-01).
        $this->expectExceptionMessage('certificate expired on');

        $this->createValidator()->validate(PemTest::EXPIRED_CERTIFICATE, '');
    }

    /**
     * Merchant ID cases: certificate subject UID(s), subject CN (null = "Merchant ID: <first UID>"), configured
     * Merchant ID, and the expected error (null = valid).
     *
     * @return array
     */
    public static function merchantIdProvider(): array
    {
        $mismatch = 'is for Merchant ID "merchant.com.other", but the Apple Merchant ID is "merchant.com.example"';

        return [
            'match' => ['merchant.com.example', null, 'merchant.com.example', null],
            'mismatch' => ['merchant.com.other', null, 'merchant.com.example', $mismatch],
            'no merchant id configured' => ['merchant.com.other', null, '', null],
            'multiple uids' => [
                [
                    'merchant.com.old',
                    'merchant.com.example',
                ],
                null,
                'merchant.com.example',
                null,
            ],
            'uid differs, cn matches' => [
                'ABCDE12345',
                'Merchant ID: merchant.com.example',
                'merchant.com.example',
                null,
            ],
            'no uid, cn matches' => [null, 'Merchant ID: merchant.com.example', 'merchant.com.example', null],
            'no uid, cn mismatch' => [null, 'Merchant ID: merchant.com.other', 'merchant.com.example', $mismatch],
            'no merchant id in subject' => [null, 'Example Store', 'merchant.com.example', null],
        ];
    }

    /**
     * The certificate subject (UID or "Merchant ID:" CN) must name the configured Merchant ID.
     *
     * @dataProvider merchantIdProvider
     * @param string|string[]|null $uid
     * @param string|null $commonName
     * @param string $merchantId
     * @param string|null $error
     * @return void
     */
    #[DataProvider('merchantIdProvider')]
    public function testCertificateMerchantId(
        string|array|null $uid,
        ?string $commonName,
        string $merchantId,
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

        if ($error !== null) {
            $this->expectException(LocalizedException::class);
            $this->expectExceptionMessage($error);
        }

        $this->createValidator()->validate(
            PemTest::createCertificate(30, null, $this->createIdentitySubject($subject)),
            $merchantId
        );

        $this->addToAssertionCount(1);
    }

    /**
     * The Payment Processing certificate (held by Authorize.net) carries the same UID, so it needs its own check.
     *
     * @return void
     */
    public function testPaymentProcessingCertificateIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('This is the Apple Pay Payment Processing certificate');

        $this->createValidator()->validate(
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
            'merchant.com.example'
        );
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
     * @return IdentityCertificateValidator
     */
    private function createValidator(): IdentityCertificateValidator
    {
        return new IdentityCertificateValidator(new Pem());
    }
}
