<?php declare(strict_types=1);
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

namespace ParadoxLabs\Authnetcim\Model\Service;

use Magento\Payment\Gateway\Command\CommandException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\LocalizedException;
use ParadoxLabs\Authnetcim\Helper\Data;
use ParadoxLabs\Authnetcim\Model\ConfigProvider;
use ParadoxLabs\Authnetcim\Model\Gateway;
use ParadoxLabs\TokenBase\Api\CardRepositoryInterface;
use ParadoxLabs\TokenBase\Api\Data\CardInterface;
use ParadoxLabs\TokenBase\Api\MethodInterface;
use ParadoxLabs\TokenBase\Model\Method\Factory;

class CustomerProfile
{
    /**
     * @var MethodInterface
     */
    protected $method;

    /**
     * CustomerProfile constructor.
     *
     * @param Data $helper
     * @param CardRepositoryInterface $cardRepository
     * @param Factory $methodFactory
     */
    public function __construct(
        protected readonly Data $helper,
        protected readonly CardRepositoryInterface $cardRepository,
        protected readonly Factory $methodFactory,
    ) {
    }

    /**
     * Set the active payment method instance
     *
     * @param MethodInterface $method
     * @return void
     */
    public function setMethod(MethodInterface $method): void
    {
        $this->method = $method;
    }

    /**
     * Get API gateway instance
     *
     * @return Gateway
     */
    protected function getGateway(): Gateway
    {
        if (!isset($this->method)) {
            $this->method = $this->methodFactory->getMethodInstance(ConfigProvider::CODE);
        }

        /** @var Gateway $gateway */
        $gateway = $this->method->gateway();

        return $gateway;
    }

    /**
     * Get the most recent added card from the current CIM profile.
     *
     * @param string $profileId
     * @return array
     * @throws LocalizedException
     * @throws CommandException
     */
    public function fetchAddedCard(string $profileId): array
    {
        $gateway = $this->getGateway();

        // Get CIM profile cards
        $gateway->setParameter('customerProfileId', $profileId);
        $gateway->setParameter('unmaskExpirationDate', 'true');
        $gateway->setParameter('includeIssuerInfo', 'true');

        $response = $gateway->getCustomerProfile();

        if (!empty($response['messages']['message']['text'])
            && $response['messages']['message']['text'] !== 'Successful.') {
            throw new LocalizedException(__($response['messages']['message']['text']));
        }

        if (!isset($response['profile']['paymentProfiles'])) {
            $this->helper->log(
                ConfigProvider::CODE,
                sprintf('Unable to load payment record for CIM profile "%s"', $profileId)
            );

            throw new LocalizedException(__('Unable to find payment record.'));
        }

        $newestCard = $response['profile']['paymentProfiles'] ?? [];
        if (!isset($response['profile']['paymentProfiles']['customerPaymentProfileId'])) {
            $paymentProfiles = [];
            foreach ($response['profile']['paymentProfiles'] as $paymentProfile) {
                $paymentProfiles[ $paymentProfile['customerPaymentProfileId'] ] = $paymentProfile;
            }

            ksort($paymentProfiles);
            $newestCard = end($paymentProfiles);
        }

        return $newestCard;
    }

    /**
     * Set data from a CIM payment profile onto the given TokenBase Card
     *
     * @param CardInterface $card
     * @param array $paymentProfile
     * @return CardInterface
     * @throws LocalizedException
     */
    public function importPaymentProfile(CardInterface $card, array $paymentProfile): CardInterface
    {
        // Note: customerProfileRequest will not return customerProfileId in the PaymentProfile data.
        $card->setProfileId($paymentProfile['customerProfileId'] ?? $card->getProfileId());
        $card->setPaymentId($paymentProfile['customerPaymentProfileId'] ?? $card->getPaymentId());

        $card = $card->getTypeInstance();
        $card->setData('no_sync', true);

        $this->setPaymentProfileDataOnCard($paymentProfile, $card);

        $this->cardRepository->save($card);

        $this->helper->log(
            ConfigProvider::CODE,
            sprintf(
                "Imported card %s (ID %s) from CIM (profile_id '%s', payment_id '%s')",
                $card->getLabel(),
                $card->getId(),
                $card->getProfileId(),
                $card->getPaymentId()
            )
        );

        return $card;
    }

    /**
     * Update the given TokenBase Card from its source data in Authorize.net CIM.
     *
     * @param CardInterface $card
     * @return CardInterface
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws CommandException
     */
    public function updateCardFromPaymentProfile(CardInterface $card): CardInterface
    {
        /** @var Gateway $gateway */
        $gateway = $this->getGateway();

        // Get CIM payment profile
        $gateway->setParameter('customerProfileId', $card->getProfileId());
        $gateway->setParameter('customerPaymentProfileId', $card->getPaymentId());
        $gateway->setParameter('unmaskExpirationDate', 'true');
        $gateway->setParameter('includeIssuerInfo', 'true');

        $response = $gateway->getCustomerPaymentProfile();

        if (!empty($response['messages']['message']['text'])
            && $response['messages']['message']['text'] !== 'Successful.') {
            throw new LocalizedException(__($response['messages']['message']['text']));
        }

        $paymentProfile = $response['paymentProfile'];

        $this->setPaymentProfileDataOnCard($paymentProfile, $card);
        $this->cardRepository->save($card);

        $this->helper->log(
            ConfigProvider::CODE,
            sprintf(
                "Updated card %s (ID %s) from CIM (profile_id '%s', payment_id '%s')",
                $card->getLabel(),
                $card->getId(),
                $paymentProfile['customerProfileId'],
                $paymentProfile['customerPaymentProfileId']
            )
        );

        return $card;
    }

    /**
     * Set credit card metadata from a payment profile onto a Card.
     *
     * @param array $paymentProfile
     * @param CardInterface $card
     * @return CardInterface
     */
    public function setPaymentProfileDataOnCard(array $paymentProfile, CardInterface $card): CardInterface
    {
        $paymentData = [];

        if (isset($paymentProfile['payment']['creditCard'])) {
            $creditCard = $paymentProfile['payment']['creditCard'];
            [$yr, $mo] = explode('-', (string)$creditCard['expirationDate'], 2);
            $day  = date('t', strtotime($yr . '-' . $mo));
            $type = $this->helper->mapCcTypeToMagento($creditCard['cardType']);

            $paymentData = [
                'cc_type' => $type,
                'cc_last4' => substr((string)$creditCard['cardNumber'], -4),
                'cc_exp_year' => $yr,
                'cc_exp_month' => $mo,
                'cc_bin' => ($creditCard['issuerNumber'] ?? ''),
            ];

            $card->setData('expires', sprintf('%s-%s-%s 23:59:59', $yr, $mo, $day));
        } elseif (isset($paymentProfile['payment']['bankAccount'])) {
            $bankAccount = $paymentProfile['payment']['bankAccount'];
            $paymentData = [
                'echeck_account_type' => $bankAccount['accountType'] ?? 'checking',
                'echeck_account_name' => $bankAccount['nameOnAccount'] ?? '',
                'echeck_bank_name' => $bankAccount['bankName'] ?? '',
                'echeck_routing_number_last4' => substr((string)($bankAccount['routingNumber'] ?? ''), -4),
                'echeck_account_number_last4' => substr((string)($bankAccount['accountNumber'] ?? ''), -4),
                'echeck_type' => $bankAccount['echeckType'] ?? 'WEB',
                'cc_last4' => substr((string)($bankAccount['accountNumber'] ?? ''), -4),
            ];
        }

        $paymentData += (array)$card->getAdditional();
        $card->setAdditional($paymentData);

        return $card;
    }
}
