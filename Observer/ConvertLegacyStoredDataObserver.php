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

namespace ParadoxLabs\Authnetcim\Observer;

use ParadoxLabs\Authnetcim\Model\Gateway;
use Magento\Sales\Model\Order;
use ParadoxLabs\TokenBase\Model\Card;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Customer;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Api\AttributeInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use ParadoxLabs\Authnetcim\Helper\Data;
use ParadoxLabs\TokenBase\Api\CardRepositoryInterface;
use ParadoxLabs\TokenBase\Model\CardFactory;
use ParadoxLabs\TokenBase\Model\Method\Factory;

/**
 * Convert old CIM 1.x data to 2.x+ (on demand at runtime, for practicality).
 */
class ConvertLegacyStoredDataObserver implements ObserverInterface
{
    /**
     * @param Data $helper
     * @param Factory $methodFactory
     * @param \ParadoxLabs\TokenBase\Model\CardFactory $cardFactory
     * @param CollectionFactory $orderCollectionFactory
     * @param RegionFactory $regionFactory
     * @param CustomerRepositoryInterface $customerRepository
     * @param OrderPaymentRepositoryInterface $paymentRepository
     * @param CardRepositoryInterface $cardRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        protected readonly Data $helper,
        protected readonly Factory $methodFactory,
        protected readonly CardFactory $cardFactory,
        protected readonly CollectionFactory $orderCollectionFactory,
        protected readonly RegionFactory $regionFactory,
        protected readonly CustomerRepositoryInterface $customerRepository,
        protected readonly OrderPaymentRepositoryInterface $paymentRepository,
        protected readonly CardRepositoryInterface $cardRepository,
        protected readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * Check if the customer has been converted before returning stored cards.
     * If they have not, run the conversion process inline.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        /** @var string $method */
        $method = $observer->getEvent()->getData('method');

        /**
         * Short circuit if this isn't us.
         */
        if ($method === null || $method !== 'authnetcim') {
            return;
        }

        /**
         * Short circuit if no customer.
         */
        /** @var CustomerInterface $customer */
        $customer = $observer->getEvent()->getData('customer');

        if ($customer instanceof Customer) {
            $customer = $customer->getDataModel();
        }

        if (!($customer instanceof CustomerInterface) || $customer->getId() < 1) {
            return;
        }

        /**
         * Short circuit if no profile ID, or already converted.
         */
        $profileId = $customer->getCustomAttribute('authnetcim_profile_id');
        if ($profileId instanceof AttributeInterface) {
            $profileId = $profileId->getValue();
        }

        $profileVersion = $customer->getCustomAttribute('authnetcim_profile_version');
        if ($profileVersion instanceof AttributeInterface) {
            $profileVersion = $profileVersion->getValue();
        }

        if (empty($profileId) || $profileVersion >= 200) {
            return;
        }

        /**
         * Update customer data from 1.x trunk to 2.x.
         *
         * That means:
         * - Load all profile data from Authorize.Net
         * - Create card records for each
         * - Update any orders or profiles attached to those cards
         */
        /**
         * Fetch profile data from Authorize.Net
         */
        /** @var Gateway $gateway */
        $gateway = $this->methodFactory->getMethodInstance('authnetcim')->gateway();
        $gateway->setParameter('customerProfileId', $profileId);
        $gateway->setParameter('unmaskExpirationDate', 'true');

        $profile = $gateway->getCustomerProfile();

        $affectedCards = 0;
        $affectedRps   = 0;

        $cards = $this->getCardsFromProfile($profile);

        if (!empty($cards)) {
            /**
             * Create a card record for each
             */
            $this->convertCards($cards, $customer, $profileId, $affectedCards);

            /**
             * Update any attached orders
             */
            $affectedOrders = $this->updateOrders($cards);

            $this->helper->log(
                'authnetcim',
                sprintf(
                    'Updated records for customer %s %s (%d): %d cards, %d orders, %d profiles',
                    $customer->getFirstname(),
                    $customer->getLastname(),
                    $customer->getId(),
                    $affectedCards,
                    $affectedOrders,
                    $affectedRps
                )
            );
        }

        $customer->setCustomAttribute('authnetcim_profile_version', 200);
        $this->customerRepository->save($customer);
    }

    /**
     * Update orders attached to converted cards
     *
     * @param array $cards
     * @return mixed
     */
    protected function updateOrders($cards)
    {
        $affectedOrders = 0;

        if (empty($cards)) {
            return $affectedOrders;
        }

        $orders = $this->orderCollectionFactory->create();
        $orders->addFieldToFilter('ext_customer_id', ['in' => array_keys($cards)]);

        /** @var Order $order */
        foreach ($orders as $order) {
            $payment = $order->getPayment();
            $payment->setData('tokenbase_id', $cards[ $order->getExtCustomerId() ]['tokenbase_id']);

            $this->paymentRepository->save($payment);

            $affectedOrders++;
        }

        return $affectedOrders;
    }

    /**
     * Create a tokenbase card for each legacy record.
     *
     * @param array $cards
     * @param CustomerInterface $customer
     * @param string $profileId
     * @param int $affectedCards
     * @return void
     */
    protected function convertCards(
        &$cards,
        CustomerInterface $customer,
        $profileId,
        &$affectedCards
    ) {
        foreach ($cards as $k => $card) {
            if (!isset($card['payment']['creditCard'], $card['billTo']['country'])
                || $this->cardAlreadyExists($customer->getId(), $profileId, $card['customerPaymentProfileId'])) {
                unset($cards[ $k ]);
                continue;
            }

            /** @var Card $storedCard */
            $storedCard = $this->cardFactory->create();
            $storedCard->setMethod('authnetcim')
                       ->setCustomer($customer)
                       ->setProfileId($profileId)
                       ->setPaymentId($card['customerPaymentProfileId']);

            if (isset($card['last_use'])) {
                $storedCard->setLastUse(strtotime((string)$card['last_use']));
            }

            if (isset($card['active']) && $card['active'] == false) {
                $storedCard->setActive(0);
            }

            if (isset($card['billTo']['state'])) {
                $region = $this->regionFactory->create();
                $region->loadByName($card['billTo']['state'], $card['billTo']['country']);
            }

            $addressData = [
                'parent_id' => $customer->getId(),
                'customer_id' => $customer->getId(),
                'firstname' => $card['billTo']['firstName'] ?? '',
                'lastname' => $card['billTo']['lastName'] ?? '',
                'street' => $card['billTo']['address'] ?? '',
                'city' => $card['billTo']['city'] ?? '',
                'country_id' => $card['billTo']['country'] ?? '',
                'region' => $card['billTo']['state'] ?? '',
                'region_id' => isset($region) ? $region->getId() : '',
                'postcode' => $card['billTo']['zip'] ?? '',
                'telephone' => $card['billTo']['phoneNumber'] ?? '',
                'fax' => $card['billTo']['faxNumber'] ?? '',
            ];

            $storedCard->setData('address', json_encode($addressData));

            if (isset($card['payment']['creditCard'])) {
                [$yr, $mo] = explode('-', (string)$card['payment']['creditCard']['expirationDate'], 2);
                $day = date('t', strtotime($yr . '-' . $mo));

                $paymentData = [
                    'cc_type' => $this->helper->mapCcTypeToMagento($card['payment']['creditCard']['cardType']),
                    'cc_last4' => substr((string)$card['payment']['creditCard']['cardNumber'], -4),
                    'cc_exp_year' => $yr,
                    'cc_exp_month' => $mo,
                ];

                $storedCard->setData('additional', json_encode($paymentData));
                $storedCard->setData('expires', sprintf('%s-%s-%s 23:59:59', $yr, $mo, $day));
            }

            $storedCard = $this->cardRepository->save($storedCard);

            $cards[ $k ]['tokenbase_id'] = $storedCard->getId();

            $affectedCards++;
        }
    }

    /**
     * Pull payment profile info out of the given customer profile array.
     *
     * @param array $profile
     * @return array
     */
    protected function getCardsFromProfile(array $profile)
    {
        $cards = [];
        if (isset($profile['profile']['paymentProfiles']) && !empty($profile['profile']['paymentProfiles'])) {
            $profiles = $profile['profile']['paymentProfiles'];

            // Could have one value, or several. Handle both cases.
            if (isset($profiles['billTo'])) {
                $cards[ $profiles['customerPaymentProfileId'] ] = $profiles;
            } else {
                foreach ($profiles as $card) {
                    $cards[ $card['customerPaymentProfileId'] ] = $card;
                }
            }
        }

        return $cards;
    }

    /**
     * Check whether the given profile/payment ID pair already exist.
     *
     * @param string|int $customerId
     * @param string|int $profileId
     * @param string|int $paymentId
     * @return bool
     */
    protected function cardAlreadyExists($customerId, $profileId, $paymentId)
    {
        $cardCriteria = $this->searchCriteriaBuilder->addFilter('method', 'authnetcim')
                                                    ->addFilter('customer_id', $customerId)
                                                    ->addFilter('profile_id', $profileId)
                                                    ->addFilter('payment_id', $paymentId)
                                                    ->setPageSize(1)
                                                    ->create();

        if ($this->cardRepository->getList($cardCriteria)->getTotalCount() > 0) {
            return true;
        }

        return false;
    }
}
