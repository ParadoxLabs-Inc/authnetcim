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

namespace ParadoxLabs\Authnetcim\Model\Ach;

use Magento\Framework\Exception\LocalizedException;

/**
 * Authorize.Net CIM API Gateway - custom built for perfection.
 */
class Gateway extends \ParadoxLabs\Authnetcim\Model\Gateway
{
    /**
     * @var string
     */
    protected $code = 'authnetcim_ach';

    /**
     * Turn transaction results and directResponse into a usable object.
     *
     * @param string $transactionResult
     * @return \ParadoxLabs\TokenBase\Model\Gateway\Response
     * @throws LocalizedException
     * @throws LocalizedException
     */
    protected function interpretTransaction($transactionResult)
    {
        $response = parent::interpretTransaction($transactionResult);

        if ($response->getAuthCode() == '' && $response->getMethod() == 'ECHECK') {
            $response->setData('auth_code', 'ACH');
        }

        return $response;
    }

    /**
     * Find a duplicate CIM record matching the one we just tried to create.
     *
     * @return string|bool CIM payment id, or false if none
     */
    public function findDuplicateCard()
    {
        $profile            = $this->getCustomerProfile();
        $accountLastFour    = substr((string)$this->getParameter('accountNumber'), -4);
        $routingLastFour    = substr((string)$this->getParameter('routingNumber'), -4);

        if (isset($profile['profile']['paymentProfiles']) && !empty($profile['profile']['paymentProfiles'])) {
            // If there's only one, just stop. It has to be the match.
            if (isset($profile['profile']['paymentProfiles']['billTo'])) {
                $card = $profile['profile']['paymentProfiles'];
                return $card['customerPaymentProfileId'];
            } else {
                // Otherwise, compare end of routing number and account number for each until one matches.
                foreach ($profile['profile']['paymentProfiles'] as $card) {
                    if (isset($card['payment']['bankAccount'])
                        && $accountLastFour == substr((string)$card['payment']['bankAccount']['accountNumber'], -4)
                        && $routingLastFour == substr((string)$card['payment']['bankAccount']['routingNumber'], -4)) {
                        return $card['customerPaymentProfileId'];
                    }
                }
            }
        }

        return false;
    }
}
