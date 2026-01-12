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

namespace ParadoxLabs\Authnetcim\Block\Adminhtml\Config;

use ParadoxLabs\Authnetcim\Model\ConfigProvider;

class ApiTest extends \ParadoxLabs\TokenBase\Block\Adminhtml\Config\ApiTest
{
    /**
     * @var string
     */
    protected $code = 'authnetcim';

    /**
     * @var \ParadoxLabs\Authnetcim\Model\Method
     */
    protected $method;

    /**
     * Test the API connection and report common errors.
     *
     * @return \Magento\Framework\Phrase|string
     */
    public function testApi()
    {
        $method = $this->getMethodInstance();

        // Don't bother if details aren't entered.
        if ($method->getConfigData('login') == '' || $method->getConfigData('trans_key') == '') {
            return __('Enter API credentials and save to test.');
        }

        // Verify no invalid characters -- suggests changed encryption key/corrupted data.
        if ($this->containsInvalidCharacters($method->getConfigData('login'))
            || $this->containsInvalidCharacters($method->getConfigData('trans_key'))) {
            return __('Please re-enter your API Login ID and Transaction Key. They may be corrupted.');
        }

        if ($method->getConfigData('form_type') === ConfigProvider::FORM_ACCEPTJS
            && empty($method->getConfigData('client_key'))) {
            return __('Accept.js is enabled, but you have not entered your Client Key.');
        }

        /** @var \ParadoxLabs\Authnetcim\Model\Gateway $gateway */
        $gateway = $method->gateway();

        try {
            // Run the test call -- simple profile request. It won't exist, that's okay.
            $gateway->setParameter('customerProfileId', '1');
            $gateway->getCustomerProfile();

            return __('Authorize.Net CIM connected successfully.') . ($method->getConfigData('test')
                    ? __(' (SANDBOX)')
                    : __(' (PRODUCTION)'));
        } catch (\Exception $e) {
            /**
             * Handle common configuration errors.
             */

            $result       = $gateway->getLastResponse();

            if (is_array($result)) {
                $errorCode = $this->helper->getArrayValue($result, 'message/message/code');
            } else {
                $errorCode = 'E00001';
            }

            if (in_array($errorCode, ['E00005', 'E00006', 'E00007', 'E00008'])) {
                // Bad login ID / trans key
                return __('Your API credentials are invalid. (%1)', $errorCode);
            }
            if ($errorCode === 'E00009') {
                // Test mode active
                return __(
                    'Your account has test mode enabled. It must be disabled for CIM to work properly. (%1)',
                    $errorCode
                );
            }
            if ($errorCode === 'E00044') {
                // CIM not enabled
                return __(
                    'Your account does not have CIM enabled. Please contact your Authorize.Net support rep '
                    . 'to resolve this. (%1)',
                    $errorCode
                );
            }

            return __($e->getMessage());
        }
    }

    /**
     * Before rendering html, but after trying to load cache
     *
     * @param \Magento\Framework\Data\Form\Element\AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(\Magento\Framework\Data\Form\Element\AbstractElement $element)
    {
        $html = parent::_getElementHtml($element);

        // If API creds work and Accept.js is enabled, output Accept.js test (must be done client-side in JS).
        if (strpos((string)$html, '#0a0') !== false
            && $this->getMethodInstance()->isAcceptJsEnabled()) {
            $acceptJsTest = $this->getLayout()->createBlock(AcceptjsTest::class, 'acceptjs_test');
            $html .= $acceptJsTest->toHtml();
        }

        return $html;
    }

    /**
     * @return \ParadoxLabs\Authnetcim\Model\Method
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getMethodInstance()
    {
        /** @var \ParadoxLabs\Authnetcim\Model\Method $method */
        $this->method = $this->methodFactory->getMethodInstance($this->code);
        $this->method->setStore($this->getStoreId());

        return $this->method;
    }
}
