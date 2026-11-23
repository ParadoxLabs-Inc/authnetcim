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

namespace ParadoxLabs\Authnetcim\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Apple Pay Merchant Identity certificate upload (.cer). Never saved itself: ApplePayCertificate stores its contents.
 */
class ApplePayCertificateFile extends Value
{
    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $this->_dataSaveAllowed = false;

        $file  = $this->getValue();
        $error = is_array($file) ? (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_NO_FILE) {
            return $this;
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new LocalizedException(
                __('The Apple Pay certificate file could not be uploaded (error %1). Please try again.', $error)
            );
        }

        // The certificate field is not posted while it inherits its value, so it would not save the upload.
        if ($this->getFieldsetDataValue('applepay_identity_cert') === null) {
            throw new LocalizedException(
                __(
                    'To upload an Apple Pay certificate here, first uncheck "Use Default" / "Use Website" for'
                    . ' Merchant Identity Certificate (PEM).'
                )
            );
        }

        return $this;
    }
}
