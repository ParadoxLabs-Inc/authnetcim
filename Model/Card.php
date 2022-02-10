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

namespace ParadoxLabs\Authnetcim\Model;

use Magento\Payment\Gateway\Command\CommandException;

/**
 * Authorize.Net CIM card model
 */
class Card extends \ParadoxLabs\TokenBase\Model\Card
{
    /**
     * @var \Magento\Sales\Api\OrderPaymentRepositoryInterface
     */
    protected $paymentRepository;

    /**
     * Card constructor.
     *
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory
     * @param \ParadoxLabs\TokenBase\Model\Card\Context $cardContext
     * @param \Magento\Sales\Api\OrderPaymentRepositoryInterface $paymentRepository
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \ParadoxLabs\TokenBase\Model\Card\Context $cardContext,
        \Magento\Sales\Api\OrderPaymentRepositoryInterface $paymentRepository,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $cardContext,
            $resource,
            $resourceCollection,
            $data
        );

        $this->paymentRepository = $paymentRepository;
    }

    /**
     * Don't enable this. Really. Just don't.
     *
     * @var bool
     */
    const USE_NEW_CREATE = false;

    /**
     * Try to create a card record from legacy data.
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @return $this
     * @throws CommandException
     */
    public function importLegacyData(\Magento\Payment\Model\InfoInterface $payment)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */

        // Customer ID -- pull from customer or payment if possible, otherwise go to Authorize.Net.
        $profileId = $this->getCustomer()->getCustomAttribute('authnetcim_profile_id');
        if ($profileId instanceof \Magento\Framework\Api\AttributeInterface) {
            $profileId = $profileId->getValue();
        }

        if (!empty($profileId)) {
            $this->setProfileId($profileId);
        } elseif ((int)$payment->getAdditionalInformation('profile_id') > 0) {
            $this->setProfileId((int)$payment->getAdditionalInformation('profile_id'));
        } else {
            $this->createCustomerProfile();
        }

        // Payment ID -- pull from order if possible.
        $this->setPaymentId($payment->getOrder()->getExtCustomerId());

        if ($this->getProfileId() == '' || $this->getPaymentId() == '') {
            $this->helper->log(
                $this->getMethod(),
                'Authorize.Net CIM: Unable to covert legacy data for processing. Please seek support.'
            );

            throw new CommandException(
                __('Authorize.Net CIM: Unable to covert legacy data for processing. Please seek support.')
            );
        }

        if ($payment->getData('cc_type') != '') {
            $this->setAdditional('cc_type', $payment->getData('cc_type'));
        }

        if ($payment->getData('cc_last_4') != '') {
            $this->setAdditional('cc_last4', $payment->getData('cc_last_4'));
        } elseif ($payment->getData('cc_last4') != '') {
            $this->setAdditional('cc_last4', $payment->getData('cc_last4'));
        }

        if (!empty($payment->getAdditionalInformation('cc_bin'))
            && $this->getMethodInstance()->getConfigData('can_store_bin') == 1) {
            $this->setAdditional('cc_bin', $payment->getAdditionalInformation('cc_bin'));
        }

        if ($payment->getData('cc_exp_year') > date('Y')
            || ($payment->getData('cc_exp_year') == date('Y') && $payment->getData('cc_exp_month') >= date('n'))) {
            $yr  = $payment->getData('cc_exp_year');
            $mo  = $payment->getData('cc_exp_month');
            $day = date('t', strtotime($payment->getData('cc_exp_year') . '-' . $payment->getData('cc_exp_month')));

            $this->setAdditional('cc_exp_year', $payment->getData('cc_exp_year'))
                ->setAdditional('cc_exp_month', $payment->getData('cc_exp_month'))
                ->setData('expires', sprintf('%s-%s-%s 23:59:59', $yr, $mo, $day));
        }

        return $this;
    }

    /**
     * Finalize before saving.
     *
     * @return $this
     */
    public function beforeSave()
    {
        // Sync only if we have an info instance for payment data, and haven't already.
        if ($this->hasData('info_instance') && $this->getData('no_sync') !== true) {
            if (self::USE_NEW_CREATE === true
                && $this->getInfoInstance()->hasData('order')
                && $this->getPaymentId() == '') {
                $this->setPaymentInfoForCreation();
            } else {
                $this->syncCustomerPaymentProfile();
            }
        }

        parent::beforeSave();

        return $this;
    }

    /**
     * Finalize after saving.
     *
     * @return $this
     */
    public function afterSave()
    {
        // On card save, store the token/ID in the registry (if any) to avoid token reuse.
        if ($this->hasData('info_instance')) {
            $acceptJsValue = $this->getMethodInstance()->gateway()->getParameter('dataValue');

            if (!empty($acceptJsValue)) {
                $this->_registry->register(
                    'authnetcim-acceptjs-' . $acceptJsValue,
                    $this->getId(),
                    true
                );
            }
        }

        parent::afterSave();

        return $this;
    }

    /**
     * Finalize before deleting.
     *
     * @return $this
     */
    public function beforeDelete()
    {
        /**
         * Delete from Authorize.Net if we have a valid record.
         */
        if ($this->getProfileId() != '' && $this->getPaymentId() != '') {
            /** @var \ParadoxLabs\Authnetcim\Model\Gateway $gateway */
            $gateway = $this->getMethodInstance()->gateway();

            $gateway->setCard($this);

            $gateway->setParameter('customerProfileId', $this->getProfileId());
            $gateway->setParameter('customerPaymentProfileId', $this->getPaymentId());

            // Suppress any gateway errors that might occur; we don't care here.
            try {
                $gateway->deleteCustomerPaymentProfile();
            } catch (\Exception $e) {
                $this->helper->log(
                    $this->getMethod(),
                    sprintf(
                        'Error deleting card (%s, %s): %s',
                        $this->getProfileId(),
                        $this->getPaymentId(),
                        $e->getMessage()
                    )
                );
            }
        }

        parent::beforeDelete();

        return $this;
    }

    /**
     * Attempt to create a CIM customer profile
     *
     * @return $this
     * @throws CommandException
     */
    protected function createCustomerProfile()
    {
        if ($this->getCustomerId() > 0 || $this->getCustomerEmail() != '') {
            /** @var \ParadoxLabs\Authnetcim\Model\Gateway $gateway */
            $gateway = $this->getMethodInstance()->gateway();

            $gateway->setParameter('merchantCustomerId', $this->getCustomerId());
            $gateway->setParameter('email', $this->getCustomerEmail());

            $profileId = $gateway->createCustomerProfile();

            if (!empty($profileId)) {
                $this->setProfileId($profileId);
                $this->getCustomer()->setCustomAttribute('authnetcim_profile_id', $profileId)
                                    ->setCustomAttribute('authnetcim_profile_version', 200);

                if ($this->getCustomer()->getId() > 0) {
                    $this->customerRepository->save($this->getCustomer());
                }
            } else {
                $this->helper->log(
                    $this->getMethod(),
                    'Authorize.Net CIM Gateway: Unable to create customer profile.'
                );

                throw new CommandException(__('Authorize.Net CIM Gateway: Unable to create customer profile.'));
            }
        } else {
            $this->helper->log(
                $this->getMethod(),
                'Authorize.Net CIM Gateway: Unable to create customer profile; email or user ID is required.'
            );

            throw new CommandException(
                __('Authorize.Net CIM Gateway: Unable to create customer profile; email or user ID is required.')
            );
        }

        return $this;
    }

    /**
     * Attempt to create a CIM payment profile
     *
     * @param bool $retry
     * @return $this
     * @throws CommandException
     */
    protected function syncCustomerPaymentProfile($retry = true)
    {
        $this->helper->log(
            $this->getMethod(),
            sprintf(
                '_createCustomerPaymentProfile(%s) (profile_id %s, payment_id %s)',
                var_export($retry, true),
                var_export($this->getProfileId(), true),
                var_export($this->getPaymentId(), true)
            )
        );

        $this->getMethodInstance()->setCard($this);

        /** @var \ParadoxLabs\Authnetcim\Model\Gateway $gateway */
        $gateway = $this->getMethodInstance()->gateway();

        /**
         * Make sure we have a customer profile, first off.
         */
        if ($this->getProfileId() == '') {
            // Does the customer have a profile ID? Try to import it.
            $profileId = $this->getCustomer()->getCustomAttribute('authnetcim_profile_id');
            if ($profileId instanceof \Magento\Framework\Api\AttributeInterface) {
                $profileId = $profileId->getValue();
            }

            if (!empty($profileId)) {
                $this->setProfileId($profileId);
            } else {
                // No profile ID, so create one.
                $this->createCustomerProfile();
            }
        }

        /**
         * If the card does not exist, create it in CIM.
         */
        if ($this->getPaymentId() == '') {
            $address = $this->getAddressObject();

            $region  = $address->getRegion()->getRegionCode() ?: $address->getRegion()->getRegion();

            $gateway->setParameter('customerProfileId', $this->getProfileId());

            $gateway->setParameter('billToFirstName', $address->getFirstname());
            $gateway->setParameter('billToLastName', $address->getLastname());
            $gateway->setParameter('billToCompany', $address->getCompany());
            $gateway->setParameter('billToAddress', implode(', ', $address->getStreet() ?: []));
            $gateway->setParameter('billToCity', $address->getCity());
            $gateway->setParameter('billToState', $region);
            $gateway->setParameter('billToZip', $address->getPostcode());
            $gateway->setParameter('billToCountry', $address->getCountryId());
            $gateway->setParameter('billToPhoneNumber', $address->getTelephone());
            $gateway->setParameter('billToFaxNumber', $address->getFax());

            $gateway->setParameter('validationMode', $this->getMethodInstance()->getConfigData('validation_mode'));

            $this->setPaymentInfoOnCreate($gateway);

            $paymentId = $gateway->createCustomerPaymentProfile();
        } else {
            /**
             * If it does exist, update CIM.
             */
            $address = $this->getAddressObject();

            $region  = $address->getRegion()->getRegionCode() ?: $address->getRegion()->getRegion();

            $gateway->setParameter('customerProfileId', $this->getProfileId());
            $gateway->setParameter('customerPaymentProfileId', $this->getPaymentId());

            $gateway->setParameter('billToFirstName', $address->getFirstname());
            $gateway->setParameter('billToLastName', $address->getLastname());
            $gateway->setParameter('billToCompany', $address->getCompany());
            $gateway->setParameter('billToAddress', implode(', ', $address->getStreet() ?: []));
            $gateway->setParameter('billToCity', $address->getCity());
            $gateway->setParameter('billToState', $region);
            $gateway->setParameter('billToZip', $address->getPostcode());
            $gateway->setParameter('billToCountry', $address->getCountryId());
            $gateway->setParameter('billToPhoneNumber', $address->getTelephone());
            $gateway->setParameter('billToFaxNumber', $address->getFax());

            $gateway->setParameter(
                'validationMode',
                $this->helper->getIsAccount() ? $this->getMethodInstance()->getConfigData('validation_mode') : null
            );

            $this->setPaymentInfoOnUpdate($gateway);

            $gateway->updateCustomerPaymentProfile();

            $paymentId = $this->getPaymentId();
        }

        /**
         * Check for 'Record cannot be found' errors (changed Authorize.Net accounts).
         * If we find it, clear our data and try again (once, and only once!), if no Accept.js.
         */
        $response = $gateway->getLastResponse();
        if ($retry === true
            && isset($response['messages']['message']['code'])
            && $response['messages']['message']['code'] === 'E00040') {
            $this->setProfileId('');
            $this->setPaymentId('');

            $profileId = $this->getCustomer()->getCustomAttribute('authnetcim_profile_id');
            if ($profileId instanceof \Magento\Framework\Api\AttributeInterface) {
                $profileId = $profileId->getValue();
            }

            /**
             * We know the authnetcim_profile_id is invalid, so get rid of it. Except we're in the middle
             * of a transaction... so any change will just be rolled back. Save it for a little later.
             * @see \ParadoxLabs\Authnetcim\Observer\CheckoutFailureClearProfileIdObserver::execute()
             */
            if (!empty($profileId)) {
                $this->getCustomer()->setCustomAttribute('authnetcim_profile_id', '');

                $this->_registry->unregister('queue_profileid_deletion');
                $this->_registry->register('queue_profileid_deletion', $this->getCustomer());
            }

            /**
             * If this is an existing stored card that kicked out a no-such-entity error, get rid of it.
             * @see \ParadoxLabs\TokenBase\Observer\CardLoadProcessDeleteQueueObserver::execute()
             */
            if ($this->getId()) {
                $this->_registry->unregister('queue_card_deletion');
                $this->_registry->register('queue_card_deletion', $this);
            }

            if ($this->getMethodInstance()->isAcceptJsEnabled()) {
                // This is an unrecoverable error with Accept.js (we just consumed the nonce), so kick out a nice error.
                throw new \Magento\Payment\Gateway\Command\CommandException(
                    __('Sorry, we were unable to find your payment record. '
                        . 'Please re-enter your payment info and try again.')
                );
            }

            return $this->syncCustomerPaymentProfile(false);
        }

        if ($response['messages']['resultCode'] !== 'Ok'
            && ($response['messages']['message']['code'] !== 'E00039' || empty($paymentId))) {
            $errorCode = $response['messages']['message']['code'];
            $errorText = $response['messages']['message']['text'];

            $this->helper->log($this->getMethod(), sprintf('API error: %s: %s', $errorCode, $errorText));
            $gateway->logLogs();

            throw new \Magento\Payment\Gateway\Command\CommandException(
                __(sprintf('Authorize.Net CIM Gateway: %s', $errorText))
            );
        }

        if (!empty($paymentId)) {
            /**
             * Prevent data from being updated multiple times in one request.
             */
            $this->setPaymentId($paymentId);
            $this->setData('no_sync', true);
        } else {
            $gateway->logLogs();

            throw new \Magento\Payment\Gateway\Command\CommandException(
                __('Authorize.Net CIM Gateway: Unable to create payment record.')
            );
        }

        return $this;
    }

    /**
     * Set payment data and billing address on the gateway for profile creation during transaction.
     *
     * @return $this
     */
    protected function setPaymentInfoForCreation()
    {
        $this->helper->log(
            $this->getMethod(),
            'setPaymentInfoForCreation()'
        );

        $this->getMethodInstance()->setCard($this);

        $gateway = $this->getMethodInstance()->gateway();
        $address = $this->getAddressObject();

        $region  = $address->getRegion()->getRegionCode() ?: $address->getRegion()->getRegion();

        $gateway->setParameter('billToFirstName', $address->getFirstname());
        $gateway->setParameter('billToLastName', $address->getLastname());
        $gateway->setParameter('billToCompany', $address->getCompany());
        $gateway->setParameter('billToAddress', implode(', ', $address->getStreet() ?: []));
        $gateway->setParameter('billToCity', $address->getCity());
        $gateway->setParameter('billToState', $region);
        $gateway->setParameter('billToZip', $address->getPostcode());
        $gateway->setParameter('billToCountry', $address->getCountryId());
        $gateway->setParameter('billToPhoneNumber', $address->getTelephone());
        $gateway->setParameter('billToFaxNumber', $address->getFax());

        $this->setPaymentInfoOnCreate($gateway);

        return $this;
    }

    /**
     * On card save, set payment data to the gateway. (Broken out for extensibility)
     *
     * @param \ParadoxLabs\TokenBase\Api\GatewayInterface $gateway
     * @return $this
     */
    protected function setPaymentInfoOnCreate(\ParadoxLabs\TokenBase\Api\GatewayInterface $gateway)
    {
        /** @var \Magento\Sales\Model\Order\Payment $info */
        $info = $this->getInfoInstance();

        $acceptJsKey   = $info->getAdditionalInformation('acceptjs_key');
        $acceptJsValue = $info->getAdditionalInformation('acceptjs_value');

        /**
         * Send Accept.js nonce instead of payment info, if we have it.
         */
        if (!empty($acceptJsKey) && !empty($acceptJsValue)) {
            $gateway->setParameter('dataDescriptor', $acceptJsKey);
            $gateway->setParameter('dataValue', $acceptJsValue);

            // Unset payment object values, to ensure they will not be reused.
            $info->setAdditionalInformation('acceptjs_key', null);
            $info->setAdditionalInformation('acceptjs_value', null);

            if ($info instanceof \Magento\Payment\Model\InfoInterface && $info->getId() > 0) {
                $this->paymentRepository->save($info);
            }
        } else {
            $gateway->setParameter('cardNumber', $info->getData('cc_number'));
            $gateway->setParameter('cardCode', $info->getData('cc_cid'));
            $gateway->setParameter(
                'expirationDate',
                sprintf('%04d-%02d', $info->getData('cc_exp_year'), $info->getData('cc_exp_month'))
            );
        }

        return $this;
    }

    /**
     * On card update, set payment data to the gateway. (Broken out for extensibility)
     *
     * @param \ParadoxLabs\TokenBase\Api\GatewayInterface $gateway
     * @return $this
     * @throws CommandException
     */
    protected function setPaymentInfoOnUpdate(\ParadoxLabs\TokenBase\Api\GatewayInterface $gateway)
    {
        /** @var \ParadoxLabs\Authnetcim\Model\Gateway $gateway */

        /** @var \Magento\Sales\Model\Order\Payment $info */
        $info = $this->getInfoInstance();

        $acceptJsKey   = $info->getAdditionalInformation('acceptjs_key');
        $acceptJsValue = $info->getAdditionalInformation('acceptjs_value');

        /**
         * Send Accept.js nonce instead of payment info, if we have it.
         */
        if (!empty($acceptJsKey) && !empty($acceptJsValue) && $info->getBaseAmountAuthorized() <= 0) {
            $gateway->setParameter('dataDescriptor', $acceptJsKey);
            $gateway->setParameter('dataValue', $acceptJsValue);

            // Unset payment object values, to ensure they will not be reused.
            $info->setAdditionalInformation('acceptjs_key', null);
            $info->setAdditionalInformation('acceptjs_value', null);

            if ($info instanceof \Magento\Payment\Model\InfoInterface && $info->getId() > 0) {
                $this->paymentRepository->save($info);
            }
        } elseif (strlen((string)$info->getData('cc_number')) >= 12) {
            $gateway->setParameter('cardNumber', $info->getData('cc_number'));
        } else {
            // If we were not given a full CC number, grab the masked value from Authorize.Net.
            $profile = $gateway->getCustomerPaymentProfile();

            if (isset($profile['paymentProfile']['payment']['creditCard'])) {
                $gateway->setParameter('cardNumber', $profile['paymentProfile']['payment']['creditCard']['cardNumber']);
            } else {
                $this->helper->log(
                    $this->getMethod(),
                    'Authorize.Net CIM Gateway: Could not load payment record.'
                );

                $gateway->logLogs();

                throw new CommandException(__('Authorize.Net CIM Gateway: Could not load payment record.'));
            }
        }

        if ($info->getData('cc_exp_year') != '' && $info->getData('cc_exp_month') != '') {
            $gateway->setParameter(
                'expirationDate',
                sprintf(
                    '%04d-%02d',
                    $info->getData('cc_exp_year'),
                    $info->getData('cc_exp_month')
                )
            );
        } else {
            $gateway->setParameter('expirationDate', 'XXXX');
        }

        $gateway->setParameter('cardCode', $info->getData('cc_cid'));

        return $this;
    }

    /**
     * Get type
     *
     * @return string
     */
    public function getType()
    {
        $type = parent::getType();

        // Handle legacy edge cases where stored card may have full type rather than 2-letter type code.
        if (strlen((string)$type) > 2 && method_exists($this->helper, 'mapCcTypeToMagento')) {
            $properType = $this->helper->mapCcTypeToMagento($type) ?: $type;

            if ($properType !== $type) {
                $this->setType($properType);
            }
        }

        return parent::getType();
    }
}
