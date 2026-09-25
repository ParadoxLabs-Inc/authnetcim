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

namespace ParadoxLabs\Authnetcim\Gateway\Wallet;

use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use ParadoxLabs\Authnetcim\Model\Wallet\Config;

/**
 * The wallet method is only available when at least one wallet is fully configured.
 *
 * Device support (can this browser actually pay with Apple/Google Pay?) is decided client-side; the renderer hides
 * the method when no enabled wallet is usable.
 */
class AvailabilityValidator extends AbstractValidator
{
    /**
     * @param ResultInterfaceFactory $resultFactory
     * @param Config $config
     */
    public function __construct(
        ResultInterfaceFactory $resultFactory,
        private readonly Config $config,
    ) {
        parent::__construct($resultFactory);
    }

    /**
     * @param array $validationSubject
     * @return ResultInterface
     */
    public function validate(array $validationSubject)
    {
        $storeId = null;
        if (isset($validationSubject['payment'])) {
            $order   = SubjectReader::readPayment($validationSubject)->getOrder();
            $storeId = $order->getStoreId() !== null ? (int)$order->getStoreId() : null;
        }

        $isValid = !empty($this->config->getEnabledWallets($storeId));

        return $this->createResult(
            $isValid,
            $isValid ? [] : [__('No digital wallet is configured.')]
        );
    }
}
