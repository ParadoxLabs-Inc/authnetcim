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

namespace ParadoxLabs\Authnetcim\Block\Form;

use ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider;

/**
 * ACH input form on checkout
 */
class Ach extends \ParadoxLabs\TokenBase\Block\Form\Ach
{
    /**
     * @var string
     */
    protected $brandingImage = 'ParadoxLabs_Authnetcim::images/logo.png';

    /**
     * Swap form template for Accept Hosted vs inline
     *
     * @return string
     */
    protected function _toHtml()
    {
        $method = $this->getTokenbaseMethod();
        if ($method->getConfigData('form_type') === ConfigProvider::FORM_HOSTED) {
            $this->_template = 'ParadoxLabs_Authnetcim::checkout/hosted/form.phtml';
        }

        return parent::_toHtml();
    }

    /**
     * Retrieve has verification configuration
     *
     * @return bool
     */
    public function hasVerification()
    {
        return false;
    }
}
