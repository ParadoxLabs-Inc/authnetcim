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

namespace ParadoxLabs\Authnetcim\Model\Ach;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Command\CommandException;

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

    /**
     * Run a refund transaction for $amount with the given payment info
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @param string $transactionId
     * @return \ParadoxLabs\TokenBase\Model\Gateway\Response
     * @throws CommandException
     */
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount, $transactionId = null)
    {
        // Send last4 values for verification of ACH refunds
        if ($payment->getMethod() === ConfigProvider::CODE) {
            if ($payment->getData('echeck_account_type') !== 'businessChecking') {
                $this->setParameter('echeckType', 'PPD');
            } else {
                $this->setParameter('echeckType', 'CCD');
            }

            $this->setParameter('nameOnAccount', $payment->getData('echeck_account_name'));
            $this->setParameter('bankName', $payment->getData('echeck_bank_name'));
            $this->setParameter('accountType', $payment->getData('echeck_account_type'));
            $this->setParameter(
                'routingNumber',
                'XXXX' . substr($payment->getData('echeck_routing_number'), -4)
            );
            $this->setParameter(
                'accountNumber',
                'XXXX' . substr($payment->getData('cc_last_4'), -4)
            );
        }

        return parent::refund(
            $payment,
            $amount,
            $transactionId
        );
    }

    /**
     * Add refund payment info to a createTransaction API request's parameters.
     *
     * Split out to reduce that method's cyclomatic complexity.
     *
     * @param array $params
     * @return array
     */
    protected function createTransactionAddRefundInfo($params)
    {
        if ($this->hasParameter('accountNumber')) {
            $params['payment'] = [
                'bankAccount' => [
                    'accountType'   => $this->getParameter('accountType'),
                    'routingNumber' => $this->getParameter('routingNumber'),
                    'accountNumber' => $this->getParameter('accountNumber'),
                    'nameOnAccount' => $this->getParameter('nameOnAccount'),
                    'echeckType'    => $this->getParameter('echeckType'),
                    'bankName'      => $this->getParameter('bankName'),
                ],
            ];
        }

        return $params;
    }
}
