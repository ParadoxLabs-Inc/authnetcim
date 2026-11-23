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
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use ParadoxLabs\Authnetcim\Model\Config\Backend\ApplePayCertificateFile;
use PHPUnit\Framework\TestCase;

class ApplePayCertificateFileTest extends TestCase
{
    /**
     * The upload field never saves a value of its own.
     *
     * @return void
     */
    public function testUploadIsNotSaved(): void
    {
        $model = $this->createModel(UPLOAD_ERR_OK, true);
        $model->beforeSave();

        $this->assertFalse($model->isSaveAllowed());
    }

    /**
     * @return void
     */
    public function testNoFileIsIgnored(): void
    {
        $model = $this->createModel(UPLOAD_ERR_NO_FILE, false);
        $model->beforeSave();

        $this->assertFalse($model->isSaveAllowed());
    }

    /**
     * @return void
     */
    public function testUploadErrorIsReported(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('could not be uploaded');

        $this->createModel(UPLOAD_ERR_INI_SIZE, true)->beforeSave();
    }

    /**
     * An inherited certificate field is not posted, so it would drop the upload.
     *
     * @return void
     */
    public function testUploadWithInheritedCertificateFieldIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('uncheck');

        $this->createModel(UPLOAD_ERR_OK, false)->beforeSave();
    }

    /**
     * @param int $error
     * @param bool $certificatePosted
     * @return ApplePayCertificateFile
     */
    private function createModel(int $error, bool $certificatePosted): ApplePayCertificateFile
    {
        return new ApplePayCertificateFile(
            $this->createMock(Context::class),
            $this->createMock(Registry::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            null,
            null,
            [
                'value' => [
                    'name' => 'merchant_id.cer',
                    'tmp_name' => '',
                    'error' => $error,
                ],
                'fieldset_data' => $certificatePosted
                    ? [
                        'applepay_identity_cert' => '',
                    ]
                    : [],
            ],
        );
    }
}
