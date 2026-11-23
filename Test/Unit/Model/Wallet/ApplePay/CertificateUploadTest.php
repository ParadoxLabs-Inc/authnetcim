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

use Magento\Config\Model\Config\Backend\File\RequestData\RequestDataInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File;
use ParadoxLabs\Authnetcim\Model\Wallet\ApplePay\CertificateUpload;
use ParadoxLabs\Authnetcim\Model\Wallet\Pem;
use ParadoxLabs\Authnetcim\Test\Unit\Model\Wallet\PemTest;
use PHPUnit\Framework\TestCase;

class CertificateUploadTest extends TestCase
{
    /**
     * @var string[]
     */
    private array $files = [];

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @return void
     */
    public function testNoUploadIsEmpty(): void
    {
        $this->assertSame('', $this->createModel(null)->getPem());
    }

    /**
     * @return void
     */
    public function testDerUploadIsConvertedToPem(): void
    {
        $pem = PemTest::createCertificate(30);
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem));

        $this->assertSame((new Pem())->normalize($pem), $this->createModel($der)->getPem());
    }

    /**
     * @return void
     */
    public function testPemUploadIsAccepted(): void
    {
        $pem = PemTest::createCertificate(30);

        $this->assertSame((new Pem())->normalize($pem), $this->createModel($pem)->getPem());
    }

    /**
     * @return void
     */
    public function testNonCertificateIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('is not a certificate');

        $this->createModel('not a certificate')->getPem();
    }

    /**
     * @return void
     */
    public function testOversizedFileIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('too large');

        $this->createModel(str_repeat('a', 70000))->getPem();
    }

    /**
     * @param string|null $contents Uploaded file contents, or null for no upload
     * @return CertificateUpload
     */
    private function createModel(?string $contents): CertificateUpload
    {
        $tmpName = null;
        if ($contents !== null) {
            $tmpName       = tempnam(sys_get_temp_dir(), 'cer');
            $this->files[] = $tmpName;
            file_put_contents($tmpName, $contents);
        }

        $requestData = $this->createMock(RequestDataInterface::class);
        $requestData->method('getTmpName')
                    ->with(CertificateUpload::PATH)
                    ->willReturn($tmpName);

        return new CertificateUpload($requestData, new File(), new Pem());
    }
}
