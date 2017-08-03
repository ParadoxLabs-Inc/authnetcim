<?php
/**
 * Paradox Labs, Inc.
 * http://www.paradoxlabs.com
 * 717-431-3330
 *
 * Need help? Open a ticket in our support system:
 *  http://support.paradoxlabs.com
 *
 * @author      Ryan Hoerr <support@paradoxlabs.com>
 * @license     http://store.paradoxlabs.com/license.html
 */

namespace ParadoxLabs\Authnetcim\Block\Adminhtml\Config;

/**
 * ApiTest Class
 */
class ApiTest extends \ParadoxLabs\TokenBase\Block\Adminhtml\Config\ApiTest
{
    /**
     * @var string
     */
    protected $code = 'authnetcim';

    /**
     * Test the API connection and report common errors.
     *
     * @return \Magento\Framework\Phrase|string
     */
    protected function testApi()
    {
        /** @var \ParadoxLabs\Authnetcim\Model\Method $method */
        $method = $this->methodFactory->getMethodInstance($this->code);
        $method->setStore($this->getStoreId());

        // Don't bother if details aren't entered.
        if ($method->getConfigData('login') == '' || $method->getConfigData('trans_key') == '') {
            return __('Enter API credentials and save to test.');
        }

        // Verify no invalid characters -- suggests changed encryption key/corrupted data.
        if ($this->containsInvalidCharacters($method->getConfigData('login'))
            || $this->containsInvalidCharacters($method->getConfigData('trans_key'))) {
            return __('Please re-enter your API Login ID and Transaction Key. They may be corrupted.');
        }

        if ($method->getConfigData('acceptjs') == 1 && $method->getConfigData('client_key') == '') {
            return __('Accept.js is enabled, but you have not entered your Client Key.');
        }

        /** @var \ParadoxLabs\Authnetcim\Model\Gateway $gateway */
        $gateway = $method->gateway();

        try {
            // Run the test call -- simple profile request. It won't exist, that's okay.
            $gateway->setParameter('customerProfileId', '1');
            $gateway->getCustomerProfile();

            return __('Authorize.Net CIM connected successfully.');
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
            } elseif ($errorCode === 'E00009') {
                // Test mode active
                return __(
                    'Your account has test mode enabled. It must be disabled for CIM to work properly. (%1)',
                    $errorCode
                );
            } elseif ($errorCode === 'E00044') {
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
}
