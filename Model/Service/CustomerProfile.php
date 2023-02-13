<?php declare(strict_types=1);
/**
 * Paradox Labs, Inc.
 * http://www.paradoxlabs.com
 * 717-431-3330
 *
 * Need help? Open a ticket in our support system:
 *  http://support.paradoxlabs.com
 *
 * @author      Ryan Hoerr <info@paradoxlabs.com>
 * @license     http://store.paradoxlabs.com/license.html
 */

namespace ParadoxLabs\Authnetcim\Model\Service;

use ParadoxLabs\Authnetcim\Model\ConfigProvider;
use ParadoxLabs\TokenBase\Api\Data\CardInterface;

class CustomerProfile
{
    /**
     * @var \ParadoxLabs\Authnetcim\Helper\Data
     */
    protected $helper;

    /**
     * @var \ParadoxLabs\TokenBase\Api\CardRepositoryInterface
     */
    protected $cardRepository;

    /**
     * @var \ParadoxLabs\TokenBase\Model\Method\Factory
     */
    protected $methodFactory;

    /**
     * @var \ParadoxLabs\TokenBase\Api\MethodInterface
     */
    protected $method;

    /**
     * CustomerProfile constructor.
     *
     * @param \ParadoxLabs\Authnetcim\Helper\Data $helper
     * @param \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository
     * @param \ParadoxLabs\TokenBase\Model\Card\Factory $cardFactory
     * @param \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
     */
    public function __construct(
        \ParadoxLabs\Authnetcim\Helper\Data $helper,
        \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository,
        \ParadoxLabs\TokenBase\Model\Card\Factory $cardFactory,
        \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
    ) {
        $this->helper = $helper;
        $this->cardRepository = $cardRepository;
        $this->cardFactory = $cardFactory;
        $this->methodFactory = $methodFactory;
    }

    /**
     * Set the active payment method instance
     *
     * @param \ParadoxLabs\TokenBase\Api\MethodInterface $method
     * @return void
     */
    public function setMethod(\ParadoxLabs\TokenBase\Api\MethodInterface $method): void
    {
        $this->method = $method;
    }

    /**
     * Get API gateway instance
     *
     * @return \ParadoxLabs\Authnetcim\Model\Gateway
     */
    protected function getGateway(): \ParadoxLabs\Authnetcim\Model\Gateway
    {
        if (!isset($this->method)) {
            $this->method = $this->methodFactory->getMethodInstance(ConfigProvider::CODE);
        }

        /** @var \ParadoxLabs\Authnetcim\Model\Gateway $gateway */
        $gateway = $this->method->gateway();

        return $gateway;
    }

    /**
     * Get the most recent added card from the current CIM profile.
     *
     * @param string $profileId
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Payment\Gateway\Command\CommandException
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
            throw new \Magento\Framework\Exception\LocalizedException(__($response['messages']['message']['text']));
        }

        if (!isset($response['profile']['paymentProfiles'])) {
            $this->helper->log(
                ConfigProvider::CODE,
                sprintf('Unable to load payment record for CIM profile "%s"', $profileId)
            );

            throw new \Magento\Framework\Exception\LocalizedException(__('Unable to find payment record.'));
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
     * @param \ParadoxLabs\TokenBase\Api\Data\CardInterface $card
     * @param array $paymentProfile
     * @return CardInterface
     * @throws \Magento\Framework\Exception\LocalizedException
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
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Payment\Gateway\Command\CommandException
     */
    public function updateCardFromPaymentProfile(CardInterface $card): CardInterface
    {
        /** @var \ParadoxLabs\Authnetcim\Model\Gateway $gateway */
        $gateway = $this->getGateway();

        // Get CIM payment profile
        $gateway->setParameter('customerProfileId', $card->getProfileId());
        $gateway->setParameter('customerPaymentProfileId', $card->getPaymentId());
        $gateway->setParameter('unmaskExpirationDate', 'true');
        $gateway->setParameter('includeIssuerInfo', 'true');

        $response = $gateway->getCustomerPaymentProfile();

        if (!empty($response['messages']['message']['text'])
            && $response['messages']['message']['text'] !== 'Successful.') {
            throw new \Magento\Framework\Exception\LocalizedException(__($response['messages']['message']['text']));
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
            [$yr, $mo]  = explode('-', (string)$creditCard['expirationDate'], 2);
            $day        = date('t', strtotime($yr . '-' . $mo));
            $type       = $this->helper->mapCcTypeToMagento($creditCard['cardType']);

            $paymentData = [
                'cc_type' => $type,
                'cc_last4' => substr((string)$creditCard['cardNumber'], -4),
                'cc_exp_year' => $yr,
                'cc_exp_month' => $mo,
                'cc_bin' => $creditCard['issuerNumber'],
            ];

            $card->setData('expires', sprintf('%s-%s-%s 23:59:59', $yr, $mo, $day));
        } elseif (isset($paymentProfile['payment']['bankAccount'])) {
            $bankAccount = $paymentProfile['payment']['bankAccount'];
            $paymentData = [
                'echeck_account_type' => $bankAccount['accountType'],
                'echeck_account_name' => $bankAccount['nameOnAccount'],
                'echeck_bank_name' => $bankAccount['bankName'],
                'echeck_routing_number_last4' => substr((string)$bankAccount['routingNumber'], -4),
                'echeck_account_number_last4' => substr((string)$bankAccount['accountNumber'], -4),
                'cc_last4' => substr((string)$bankAccount['accountNumber'], -4),
            ];
        }

        $paymentData += (array)$card->getAdditional();
        $card->setData('additional', json_encode($paymentData));

        return $card;
    }
}
