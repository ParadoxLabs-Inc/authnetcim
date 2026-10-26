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

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Notification\MessageInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\CertificateExpiryMessage;
use ParadoxLabs\Authnetcim\Model\Wallet\Pem;
use ParadoxLabs\Authnetcim\Test\Unit\Model\Wallet\PemTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CertificateExpiryMessageTest extends TestCase
{
    private const CERT_PATH = 'payment/authnetcim_wallet/applepay_identity_cert';

    /**
     * @return array
     */
    public static function disabledProvider(): array
    {
        return [
            'wallet off' => [false, true],
            'apple pay off' => [true, false],
            'both off' => [false, false],
        ];
    }

    /**
     * Loaded on every admin page: with Apple Pay off, the certificate must not even be read.
     *
     * @dataProvider disabledProvider
     * @param bool $walletActive
     * @param bool $applePayActive
     * @return void
     */
    #[DataProvider('disabledProvider')]
    public function testDisabledIsNotDisplayedWithoutParsing(bool $walletActive, bool $applePayActive): void
    {
        $pem = $this->createMock(Pem::class);
        $pem->expects($this->never())->method('getCertificateExpiry');

        $config = [
            'default' => $this->createScopeConfig($walletActive, $applePayActive, PemTest::EXPIRED_CERTIFICATE),
            'websites' => [
                'base' => $this->createScopeConfig($walletActive, $applePayActive, PemTest::EXPIRED_CERTIFICATE),
            ],
        ];

        $scopeConfig = $this->createScopeConfigMock($config);
        $scopeConfig->expects($this->never())->method('getValue');

        $message = $this->createMessage($scopeConfig, $pem);

        $message->getIdentity();
        $this->assertFalse($message->isDisplayed());
    }

    /**
     * @return void
     */
    public function testValidCertificateIsNotDisplayed(): void
    {
        $message = $this->createMessage(
            $this->createScopeConfigMock([
                'default' => $this->createScopeConfig(true, true, PemTest::createCertificate(365)),
            ])
        );

        $this->assertFalse($message->isDisplayed());
    }

    /**
     * @return void
     */
    public function testExpiringWebsiteCertificateIsMajor(): void
    {
        $certificate = PemTest::createCertificate(10);
        $message     = $this->createMessage(
            $this->createScopeConfigMock([
                'default' => $this->createScopeConfig(false, true, ''),
                'websites' => [
                    'base' => $this->createScopeConfig(true, true, $certificate),
                ],
            ])
        );

        $this->assertTrue($message->isDisplayed());
        $this->assertSame(MessageInterface::SEVERITY_MAJOR, $message->getSeverity());
        $this->assertStringContainsString(
            sprintf(
                'certificate for website "Main Website" expires on %s.',
                date('Y-m-d', (new Pem())->getCertificateExpiry($certificate))
            ),
            $message->getText()
        );
        $this->assertStringContainsString('Apple Developer account', $message->getText());
    }

    /**
     * Default config certificate, with the only website turning Apple Pay off.
     *
     * @return void
     */
    public function testExpiredCertificateIsCritical(): void
    {
        $message = $this->createMessage(
            $this->createScopeConfigMock([
                'default' => $this->createScopeConfig(true, true, PemTest::EXPIRED_CERTIFICATE),
                'websites' => [
                    'base' => [
                        'payment/authnetcim_wallet/applepay_active' => '0',
                    ],
                ],
            ])
        );

        $this->assertTrue($message->isDisplayed());
        $this->assertSame(MessageInterface::SEVERITY_CRITICAL, $message->getSeverity());
        $this->assertStringContainsString(
            sprintf(
                'certificate for the default config expired on %s, so Apple Pay is hidden at checkout.',
                date('Y-m-d', 1609459200)
            ),
            $message->getText()
        );
    }

    /**
     * A website inheriting the default certificate is listed with it, and the certificate is parsed once.
     *
     * @return void
     */
    public function testInheritedCertificateIsParsedOnce(): void
    {
        $pem = $this->createMock(Pem::class);
        $pem->expects($this->once())->method('getCertificateExpiry')->willReturn(time() + 5 * 86400);

        $message = $this->createMessage(
            $this->createScopeConfigMock([
                'default' => $this->createScopeConfig(true, true, PemTest::EXPIRED_CERTIFICATE),
                'websites' => [
                    'base' => [],
                ],
            ]),
            $pem
        );

        $this->assertTrue($message->isDisplayed());
        $this->assertStringContainsString(
            'certificate for the default config, website "Main Website" expires on',
            $message->getText()
        );
    }

    /**
     * A renewed certificate that is expiring again, or one that has now expired, must alert again.
     *
     * @return void
     */
    public function testIdentityChangesWithExpiry(): void
    {
        $certificates = [
            PemTest::createCertificate(10),
            PemTest::createCertificate(20),
            PemTest::EXPIRED_CERTIFICATE,
        ];

        $identities = [];
        foreach ($certificates as $cert) {
            $message = $this->createMessage(
                $this->createScopeConfigMock([
                    'default' => $this->createScopeConfig(true, true, $cert),
                ])
            );

            $identity = $message->getIdentity();
            $this->assertSame($identity, $message->getIdentity());
            $this->assertLessThanOrEqual(100, strlen($identity));

            $identities[] = $identity;
        }

        $this->assertCount(3, array_unique($identities));
    }

    /**
     * @return void
     */
    public function testNotDisplayedWithoutPaymentConfigAccess(): void
    {
        $message = $this->createMessage(
            $this->createScopeConfigMock([
                'default' => $this->createScopeConfig(true, true, PemTest::EXPIRED_CERTIFICATE),
            ]),
            new Pem(),
            false
        );

        $this->assertFalse($message->isDisplayed());
    }

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Pem|null $pem
     * @param bool $allowed
     * @return CertificateExpiryMessage
     */
    private function createMessage(
        ScopeConfigInterface $scopeConfig,
        ?Pem $pem = null,
        bool $allowed = true,
    ): CertificateExpiryMessage {
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getCode')->willReturn('base');
        $website->method('getName')->willReturn('Main Website');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn([$website]);

        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->with('Magento_Payment::payment')->willReturn($allowed);

        return new CertificateExpiryMessage($scopeConfig, $storeManager, $authorization, $pem ?? new Pem());
    }

    /**
     * Scope config mock: values by scope ('default', or 'websites' => [code => values]); websites inherit default.
     *
     * @param array $config
     * @return ScopeConfigInterface&MockObject
     */
    private function createScopeConfigMock(array $config): ScopeConfigInterface&MockObject
    {
        $lookup = static function (string $path, string $scope, $scopeCode) use ($config) {
            $values = $scope === 'websites' ? ($config['websites'][$scopeCode] ?? []) : [];

            return $values[$path] ?? $config['default'][$path] ?? null;
        };

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path, string $scope, $scopeCode = null): bool => (bool)$lookup(
                $path,
                $scope,
                $scopeCode
            )
        );
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path, string $scope, $scopeCode = null) => $lookup($path, $scope, $scopeCode)
        );

        return $scopeConfig;
    }

    /**
     * @param bool $walletActive
     * @param bool $applePayActive
     * @param string $certificate
     * @return array
     */
    private function createScopeConfig(bool $walletActive, bool $applePayActive, string $certificate): array
    {
        return [
            'payment/authnetcim_wallet/active' => $walletActive ? '1' : '0',
            'payment/authnetcim_wallet/applepay_active' => $applePayActive ? '1' : '0',
            self::CERT_PATH => $certificate,
        ];
    }
}
