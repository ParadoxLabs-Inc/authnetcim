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
 * @link https://support.paradoxlabs.com
 */

namespace ParadoxLabs\Authnetcim\Block\Info;

/**
 * ACH payment info block for Authorize.Net CIM.
 */
class Ach extends \ParadoxLabs\TokenBase\Block\Info\Ach
{
    /**
     * @var \ParadoxLabs\Authnetcim\Helper\Data
     */
    protected $helper;

    /**
     * Prepare payment info
     *
     * @param \Magento\Framework\DataObject|array $transport
     * @return \Magento\Framework\DataObject
     */
    protected function _prepareSpecificInformation($transport = null)
    {
        $transport  = parent::_prepareSpecificInformation($transport);
        $data       = [];

        if ($this->getIsSecureMode() === false && $this->isEcheck() === true) {
            /** @var \Magento\Sales\Model\Order\Payment $info */
            $info = $this->getInfo();

            $type = $info->getAdditionalInformation('echeck_account_type');

            if (!empty($type)) {
                $data[(string)__('Type')] = $this->helper->getAchAccountTypes($type);
            }
        }

        $transport->setData(array_merge($transport->getData(), $data));

        return $transport;
    }
}
