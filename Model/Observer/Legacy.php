<?php
/**
 * Paradox Labs, Inc.
 * http://www.paradoxlabs.com
 * 717-431-3330
 *
 * Need help? Open a ticket in our support system:
 *  http://support.paradoxlabs.com
 *
 * @author      Ryan Hoerr <magento@paradoxlabs.com>
 * @license     http://store.paradoxlabs.com/license.html
 */

namespace ParadoxLabs\Authnetcim\Model\Observer;

/**
 * Convert old CIM 1.x data to 2.x+ (on demand at runtime, for practicality).
 */
class Legacy
{
    /**
     * @var \ParadoxLabs\Authnetcim\Helper\Data
     */
    protected $helper;

    /**
     * @var \ParadoxLabs\TokenBase\Model\CardFactory
     */
    protected $cardFactory;

    /**
     * @var \Magento\Sales\Model\Resource\Order\CollectionFactory
     */
    protected $orderCollectionFactory;

    /**
     * @var \Magento\Framework\App\Resource
     */
    protected $resource;

    /**
     * @var \Magento\Directory\Model\RegionFactory
     */
    protected $regionFactory;

    /**
     * @param \ParadoxLabs\Authnetcim\Helper\Data $helper
     * @param \ParadoxLabs\TokenBase\Model\CardFactory $cardFactory
     * @param \Magento\Sales\Model\Resource\Order\CollectionFactory $orderCollectionFactory
     * @param \Magento\Directory\Model\RegionFactory $regionFactory
     * @param \Magento\Framework\App\Resource $resource
     */
    public function __construct(
        \ParadoxLabs\Authnetcim\Helper\Data $helper,
        \ParadoxLabs\TokenBase\Model\CardFactory $cardFactory,
        \Magento\Sales\Model\Resource\Order\CollectionFactory $orderCollectionFactory,
        \Magento\Directory\Model\RegionFactory $regionFactory,
        \Magento\Framework\App\Resource $resource
    ) {
        $this->helper                   = $helper;
        $this->cardFactory              = $cardFactory;
        $this->orderCollectionFactory   = $orderCollectionFactory;
        $this->regionFactory            = $regionFactory;
        $this->resource                 = $resource;
    }

    /**
     * Check if the customer has been converted before returning stored cards.
     * If they have not, run the conversion process inline.
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return $this
     */
    public function convertStoredData(\Magento\Framework\Event\Observer $observer)
    {
        /** @var \Magento\Customer\Model\Customer $customer */
        $customer = $observer->getEvent()->getData('customer');

        /** @var string $method */
        $method = $observer->getEvent()->getData('method');

        /**
         * Short circuit if this isn't us.
         */
        if (is_null($method) || $method != 'authnetcim') {
            return $this;
        }

        $profileId = $customer->getData('authnetcim_profile_id');

        if ($customer
            && $customer->getId()
            && !empty($profileId)
            && $customer->getData('authnetcim_profile_version') < 200) {
            /**
             * Update customer data from 1.x trunk to 2.x.
             *
             * That means:
             * - Load all profile data from Authorize.Net
             * - Merge in data from authnetcim/cards table
             * - Create card records for each
             * - Update any orders or profiles attached to those cards
             */

            /**
             * Fetch profile data from Authorize.Net
             */

            /** @var \ParadoxLabs\Authnetcim\Model\Gateway $gateway */
            $gateway = $this->helper->getMethodInstance('authnetcim')->gateway();
            $gateway->setParameter('customerProfileId', $profileId);

            $profile = $gateway->getCustomerProfile();
            $cards = [];

            $affectedCards = 0;
            $affectedOrders = 0;
            $affectedRps = 0;

            if (isset($profile['profile']['paymentProfiles']) && count($profile['profile']['paymentProfiles']) > 0) {
                if (isset($profile['profile']['paymentProfiles']['billTo'])) {
                    $cards[$profile['profile']['paymentProfiles']['customerPaymentProfileId']]
                        = $profile['profile']['paymentProfiles'];
                } else {
                    foreach ($profile['profile']['paymentProfiles'] as $card) {
                        $cards[$card['customerPaymentProfileId']] = $card;
                    }
                }
            }

            if (count($cards) > 0) {
                /**
                 * Fetch and merge in data from authnetcim/cards (deleted/unsaved cards)
                 */
                $db             = $this->resource->getConnection('read');
                $cardTable      = $this->resource->getTableName('authnetcim_card_exclude');

                $sql            = $db->select()
                                     ->from($cardTable, ['profile_id', 'payment_id', 'added'])
                                     ->where('customer_id=' . $customer->getId());

                $excludedCards    = $db->fetchAll($sql);
                if (count($excludedCards) > 0) {
                    foreach ($excludedCards as $excluded) {
                        if (isset($cards[ $excluded['payment_id'] ]) && $excluded['profile_id'] == $profileId) {
                            $cards[ $excluded['payment_id'] ]['active']   = false;
                            $cards[ $excluded['payment_id'] ]['last_use'] = strtotime($excluded['added']);
                        }
                    }
                }

                /**
                 * Create a card record for each
                 */
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

                    $storedCard->save();

                    $cards[$k]['tokenbase_id'] = $storedCard->getId();

                    $affectedCards++;
                }

                /**
                 * Update any attached orders
                 */
                $orders = $this->orderCollectionFactory->create();
                $orders->addFieldToFilter('ext_customer_id', array('in' => array_keys($cards)));

                /** @var \Magento\Sales\Model\Order $order */
                foreach ($orders as $order) {
                    $order->getPayment()->setData('tokenbase_id', $cards[$order->getExtCustomerId()]['tokenbase_id'])
                                        ->save();

                    $affectedOrders++;
                }

                /**
                 * Update any attached recurring profiles
                 * ... JK, there are no recurring profiles.
                 */
//                $profiles = Mage::getModel('sales/recurring_profile')->getCollection()
//                                ->addFieldToFilter('customer_id', $customer->getId())
//                                ->addFieldToFilter('state', array('nin' => array('expired', 'canceled')));
//
//                foreach ($profiles as $profile) {
//                    $adtl = unserialize($profile->getAdditionalInfo());
//
//                    if (isset($adtl['payment_id']) && isset($cards[$adtl['payment_id']])) {
//                        $adtl['tokenbase_id'] = $cards[$adtl['payment_id']]['tokenbase_id'];
//                    }
//
//                    $profile->setAdditionalInfo(serialize($adtl))
//                            ->save();
//
//                    $affectedRps++;
//                }

                $this->helper->log(
                    'authnetcim',
                    sprintf(
                        "Updated records for customer %s (%d): %d cards, %d orders, %d profiles",
                        $customer->getName(),
                        $customer->getId(),
                        $affectedCards,
                        $affectedOrders,
                        $affectedRps
                    )
                );
            }

            $customer->setData('authnetcim_profile_version', 200)
                     ->save();
        }
    }
}
