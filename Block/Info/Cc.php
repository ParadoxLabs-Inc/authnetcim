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
 * Credit card info block
 */
class Cc extends \ParadoxLabs\TokenBase\Block\Info\Cc
{
    /**
     * @var \ParadoxLabs\Authnetcim\Helper\Data
     */
    protected $helper;

    /**
     * Prepare credit card related payment info
     *
     * @param \Magento\Framework\DataObject|array $transport
     * @return \Magento\Framework\DataObject
     */
    protected function _prepareSpecificInformation($transport = null)
    {
        $transport  = parent::_prepareSpecificInformation($transport);
        $data       = [];

        if ($this->getIsSecureMode() === false && $this->isEcheck() === false) {
            /** @var \Magento\Sales\Model\Order\Payment\Info $info */
            $info = $this->getInfo();

            if ($info->getData('cc_avs_status')) {
                $avs = $info->getData('cc_avs_status');
            } else {
                $avs = $info->getAdditionalInformation('avs_result_code');
            }

            if ($info->getData('cc_cid_status')) {
                $ccv = $info->getData('cc_cid_status');
            } else {
                $ccv = $info->getAdditionalInformation('card_code_response_code');
            }

            if ($info->getData('cc_status')) {
                $cavv = $info->getData('cc_status');
            } else {
                $cavv = $info->getAdditionalInformation('cavv_response_code');
            }

            if (!empty($avs)) {
                $data[(string)__('AVS Response')]   = $this->helper->translateAvs($avs);
            }

            if (!empty($ccv)) {
                $data[(string)__('CCV Response')]   = $this->helper->translateCcv($ccv);
            }

            if (!empty($cavv)) {
                $data[(string)__('CAVV Response')]  = $this->helper->translateCavv($cavv);
            }
        }

        $transport->setData(array_merge($transport->getData(), $data));

        return $transport;
    }
}
