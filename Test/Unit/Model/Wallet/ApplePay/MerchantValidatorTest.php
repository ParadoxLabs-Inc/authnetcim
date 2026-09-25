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
use Magento\Framework\Filesystem;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Math\Random;
use Magento\Framework\Serialize\Serializer\Json;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\MerchantValidator;
use ParadoxLabs\Authnetcim\Model\Wallet\Config;
use ParadoxLabs\Authnetcim\Model\Wallet\Pem;
use ParadoxLabs\TokenBase\Helper\Data as Helper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MerchantValidatorTest extends TestCase
{
    private Config|MockObject $config;
    private CurlFactory|MockObject $curlFactory;
    private MerchantValidator $validator;

    protected function setUp(): void
    {
        $this->config      = $this->createMock(Config::class);
        $this->curlFactory = $this->createMock(CurlFactory::class);

        $this->validator = new MerchantValidator(
            $this->config,
            $this->curlFactory,
            new Json(),
            $this->createMock(Filesystem::class),
            $this->createMock(Random::class),
            $this->createMock(Helper::class),
            new Pem()
        );
    }

    /**
     * @return array
     */
    public static function urlProvider(): array
    {
        return [
            'production' => ['https://apple-pay-gateway.apple.com/paymentservices/startSession', true],
            'pod' => ['https://apple-pay-gateway-nc-pod5.apple.com/paymentservices/paymentSession', true],
            'sandbox' => ['https://apple-pay-gateway-cert.apple.com/paymentservices/startSession', true],
            'china' => ['https://cn-apple-pay-gateway-sh-pod2.apple.com/paymentservices/startSession', true],
            'http' => ['http://apple-pay-gateway.apple.com/paymentservices/startSession', false],
            'other apple host' => ['https://www.apple.com/paymentservices/startSession', false],
            'suffix attack' => ['https://apple-pay-gateway.apple.com.evil.test/x', false],
            'userinfo attack' => ['https://apple-pay-gateway.apple.com@evil.test/x', false],
            'internal host' => ['https://169.254.169.254/latest/meta-data', false],
            'odd port' => ['https://apple-pay-gateway.apple.com:8443/x', false],
            'garbage' => ['not a url', false],
        ];
    }

    /**
     * @dataProvider urlProvider
     * @param string $url
     * @param bool $expected
     * @return void
     */
    #[DataProvider('urlProvider')]
    public function testValidationUrlAllowList(string $url, bool $expected): void
    {
        $this->assertSame($expected, $this->validator->isValidationUrlAllowed($url));
    }

    /**
     * @return void
     */
    public function testDisallowedUrlNeverMakesARequest(): void
    {
        $this->config->method('isApplePayEnabled')->willReturn(true);
        $this->curlFactory->expects($this->never())->method('create');

        $this->expectException(LocalizedException::class);

        $this->validator->getMerchantSession('https://evil.test/startSession', 'example.com', 1);
    }

    /**
     * @return void
     */
    public function testReturnsMerchantSession(): void
    {
        $this->config->method('isApplePayEnabled')->willReturn(true);
        $this->config->method('getApplePayMerchantIdentifier')->willReturn('merchant.com.example');
        $this->config->method('getApplePayDisplayName')->willReturn('Example');
        $this->config->method('getApplePayIdentityCert')
            ->willReturn("-----BEGIN CERTIFICATE-----\nQUJD\n-----END CERTIFICATE-----");
        $this->config->method('getApplePayIdentityKey')
            ->willReturn("-----BEGIN PRIVATE KEY-----\nREVG\n-----END PRIVATE KEY-----");

        $curl = $this->createMock(Curl::class);
        $curl->expects($this->once())
            ->method('post')
            ->with(
                'https://apple-pay-gateway.apple.com/paymentservices/startSession',
                $this->callback(
                    static function (string $payload): bool {
                        $data = json_decode($payload, true);

                        return $data['merchantIdentifier'] === 'merchant.com.example'
                            && $data['initiative'] === 'web'
                            && $data['initiativeContext'] === 'example.com';
                    }
                )
            );
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn('{"merchantSessionIdentifier":"abc","signature":"sig"}');
        $this->curlFactory->method('create')->willReturn($curl);

        $session = $this->validator->getMerchantSession(
            'https://apple-pay-gateway.apple.com/paymentservices/startSession',
            'example.com',
            1
        );

        $this->assertSame('abc', $session['merchantSessionIdentifier']);
    }

    /**
     * @return void
     */
    public function testAppleErrorIsGeneric(): void
    {
        $this->config->method('isApplePayEnabled')->willReturn(true);
        $this->config->method('getApplePayIdentityCert')
            ->willReturn("-----BEGIN CERTIFICATE-----\nQUJD\n-----END CERTIFICATE-----");
        $this->config->method('getApplePayIdentityKey')
            ->willReturn("-----BEGIN PRIVATE KEY-----\nREVG\n-----END PRIVATE KEY-----");

        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn(400);
        $curl->method('getBody')->willReturn('{"statusMessage":"Payment Services Exception merchantId=abc"}');
        $this->curlFactory->method('create')->willReturn($curl);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Apple Pay is unavailable right now. Please try again.');

        $this->validator->getMerchantSession(
            'https://apple-pay-gateway.apple.com/paymentservices/startSession',
            'example.com',
            1
        );
    }
}
