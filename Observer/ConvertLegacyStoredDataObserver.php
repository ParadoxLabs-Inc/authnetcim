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

namespace ParadoxLabs\Authnetcim\Observer;

/**
 * Convert old CIM 1.x data to 2.x+ (on demand at runtime, for practicality).
 */
class ConvertLegacyStoredDataObserver implements \Magento\Framework\Event\ObserverInterface
{
    /**
     * @var \ParadoxLabs\Authnetcim\Helper\Data
     */
    protected $helper;

    /**
     * @var \ParadoxLabs\TokenBase\Model\Method\Factory
     */
    protected $methodFactory;

    /**
     * @var \ParadoxLabs\TokenBase\Model\CardFactory
     */
    protected $cardFactory;

    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\CollectionFactory
     */
    protected $orderCollectionFactory;

    /**
     * @var \Magento\Directory\Model\RegionFactory
     */
    protected $regionFactory;

    /**
     * @var \Magento\Customer\Api\CustomerRepositoryInterface
     */
    protected $customerRepository;

    /**
     * @var \Magento\Sales\Api\OrderPaymentRepositoryInterface
     */
    protected $paymentRepository;

    /**
     * @var \ParadoxLabs\TokenBase\Api\CardRepositoryInterface
     */
    protected $cardRepository;

    /**
     * @param \ParadoxLabs\Authnetcim\Helper\Data $helper
     * @param \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
     * @param \ParadoxLabs\TokenBase\Model\CardFactory $cardFactory
     * @param \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory
     * @param \Magento\Directory\Model\RegionFactory $regionFactory
     * @param \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository
     * @param \Magento\Sales\Api\OrderPaymentRepositoryInterface $paymentRepository
     * @param \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository
     */
    public function __construct(
        \ParadoxLabs\Authnetcim\Helper\Data $helper,
        \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory,
        \ParadoxLabs\TokenBase\Model\CardFactory $cardFactory,
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory,
        \Magento\Directory\Model\RegionFactory $regionFactory,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository,
        \Magento\Sales\Api\OrderPaymentRepositoryInterface $paymentRepository,
        \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository
    ) {
        $this->helper                   = $helper;
        $this->methodFactory            = $methodFactory;
        $this->cardFactory              = $cardFactory;
        $this->orderCollectionFactory   = $orderCollectionFactory;
        $this->regionFactory            = $regionFactory;
        $this->customerRepository       = $customerRepository;
        $this->paymentRepository        = $paymentRepository;
        $this->cardRepository           = $cardRepository;
    }

    /**
     * Check if the customer has been converted before returning stored cards.
     * If they have not, run the conversion process inline.
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
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
        /** @var \Magento\Customer\Api\Data\CustomerInterface $customer */
        $customer = $observer->getEvent()->getData('customer');

        if ($customer instanceof \Magento\Customer\Model\Customer) {
            $customer = $customer->getDataModel();
        }

        if (!($customer instanceof \Magento\Customer\Api\Data\CustomerInterface) || $customer->getId() < 1) {
            return;
        }

        /**
         * Short circuit if no profile ID, or already converted.
         */
        $profileId = $customer->getCustomAttribute('authnetcim_profile_id');
        if ($profileId instanceof \Magento\Framework\Api\AttributeInterface) {
            $profileId = $profileId->getValue();
        }

        $profileVersion = $customer->getCustomAttribute('authnetcim_profile_version');
        if ($profileVersion instanceof \Magento\Framework\Api\AttributeInterface) {
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

        /** @var \ParadoxLabs\Authnetcim\Model\Gateway $gateway */
        $gateway = $this->methodFactory->getMethodInstance('authnetcim')->gateway();
        $gateway->setParameter('customerProfileId', $profileId);

        $profile = $gateway->getCustomerProfile();

        $affectedCards = 0;
        $affectedRps = 0;

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

        $orders = $this->orderCollectionFactory->create();
        $orders->addFieldToFilter('ext_customer_id', ['in' => array_keys($cards)]);

        /** @var \Magento\Sales\Model\Order $order */
        foreach ($orders as $order) {
            $payment = $order->getPayment();
            $payment->setData('tokenbase_id', $cards[$order->getExtCustomerId()]['tokenbase_id']);

            $this->paymentRepository->save($payment);

            $affectedOrders++;
        }

        return $affectedOrders;
    }

    /**
     * Create a tokenbase card for each legacy record.
     *
     * @param array $cards
     * @param \Magento\Customer\Api\Data\CustomerInterface $customer
     * @param string $profileId
     * @param int $affectedCards
     * @return void
     */
    protected function convertCards(
        &$cards,
        \Magento\Customer\Api\Data\CustomerInterface $customer,
        $profileId,
        &$affectedCards
    ) {
        foreach ($cards as $k => $card) {
            if (!isset($card['payment']['creditCard'])) {
                continue;
            }

            /** @var \ParadoxLabs\TokenBase\Model\Card $storedCard */
            $storedCard = $this->cardFactory->create();
            $storedCard->setMethod('authnetcim')
                       ->setCustomer($customer)
                       ->setProfileId($profileId)
                       ->setPaymentId($card['customerPaymentProfileId']);

            if (isset($card['last_use'])) {
                $storedCard->setLastUse(strtotime($card['last_use']));
            }

            if (isset($card['active']) && $card['active'] == false) {
                $storedCard->setActive(0);
            }

            $region = $this->regionFactory->create();
            $region->loadByName($card['billTo']['state'], $card['billTo']['country']);

            $addressData = [
                'parent_id'   => $customer->getId(),
                'customer_id' => $customer->getId(),
                'firstname'   => $card['billTo']['firstName'],
                'lastname'    => $card['billTo']['lastName'],
                'street'      => $card['billTo']['address'],
                'city'        => $card['billTo']['city'],
                'country_id'  => $card['billTo']['country'],
                'region'      => $card['billTo']['state'],
                'region_id'   => $region->getId(),
                'postcode'    => $card['billTo']['zip'],
                'telephone'   => isset($card['billTo']['phoneNumber']) ? $card['billTo']['phoneNumber'] : '',
                'fax'         => isset($card['billTo']['faxNumber']) ? $card['billTo']['faxNumber'] : '',
            ];

            $storedCard->setData('address', serialize($addressData));

            if (isset($card['payment']['creditCard'])) {
                $paymentData = [
                    'cc_type'      => '',
                    'cc_last4'     => substr($card['payment']['creditCard']['cardNumber'], -4),
                    'cc_exp_year'  => '',
                    'cc_exp_month' => '',
                ];

                $storedCard->setData('additional', serialize($paymentData));
            }

            $storedCard = $this->cardRepository->save($storedCard);

            $cards[$k]['tokenbase_id'] = $storedCard->getId();

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
                $cards[$profiles['customerPaymentProfileId']] = $profiles;
            } else {
                foreach ($profiles as $card) {
                    $cards[$card['customerPaymentProfileId']] = $card;
                }
            }
        }

        return $cards;
    }
}
