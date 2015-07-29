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

namespace ParadoxLabs\Authnetcim\Model;

use Magento\Framework\Exception\LocalizedException;

/**
 * Authorize.Net CIM card model
 */
class Card extends \ParadoxLabs\TokenBase\Model\Card
{
    /**
     * Try to create a card record from legacy data.
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @return $this
     * @throws LocalizedException
     */
    public function importLegacyData(\Magento\Payment\Model\InfoInterface $payment)
    {
        /** @var \Magento\Payment\Model\Info $payment */
        
        // Customer ID -- pull from customer or payment if possible, otherwise go to Authorize.Net.
        if (intval($this->getCustomer()->getData('authnetcim_profile_id')) > 0) {
            $this->setProfileId($this->getCustomer()->getData('authnetcim_profile_id'));
        } elseif (intval($payment->getAdditionalInformation('profile_id')) > 0) {
            $this->setProfileId(intval($payment->getAdditionalInformation('profile_id')));
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

            throw new LocalizedException(
                __('Authorize.Net CIM: Unable to covert legacy data for processing. Please seek support.')
            );
        }

        if ($payment->getData('cc_type') != '') {
            $this->setAdditional('cc_type', $payment->getData('cc_type'));
        }

        if ($payment->getData('cc_last4') != '') {
            $this->setAdditional('cc_last4', $payment->getData('cc_last4'));
        }

        if ($payment->getData('cc_exp_year') > date('Y')
            || ($payment->getData('cc_exp_year') == date('Y') && $payment->getData('cc_exp_month') >= date('n'))) {
            $yr  = $payment->getData('cc_exp_year');
            $mo  = $payment->getData('cc_exp_month');
            $day = date('t', strtotime($payment->getData('cc_exp_year') . '-' . $payment->getData('cc_exp_month')));

            $this->setAdditional('cc_exp_year', $payment->getData('cc_exp_year'))
                ->setAdditional('cc_exp_month', $payment->getData('cc_exp_month'))
                ->setData('expires', sprintf("%s-%s-%s 23:59:59", $yr, $mo, $day));
        }

        return $this;
    }

    /**
     * Finalize before saving.
     *
     * return $this
     */
    public function beforeSave()
    {
        // Sync only if we have an info instance for payment data, and haven't already.
        if ($this->hasData('info_instance') && $this->getData('no_sync') !== true) {
            $this->createCustomerPaymentProfile();
        }

        return parent::beforeSave();
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
            $gateway = $this->getMethodInstance()->gateway();

            $gateway->setCard($this);

            $gateway->setParameter('customerProfileId', $this->getProfileId());
            $gateway->setParameter('customerPaymentProfileId', $this->getPaymentId());

            // Suppress any gateway errors that might occur; we don't care here.
            try {
                $gateway->deleteCustomerPaymentProfile();
            } catch (\Exception $e) {
                $this->helper->log($this->getMethod(), $e->getMessage());
            }
        }

        return parent::beforeDelete();
    }

    /**
     * Attempt to create a CIM customer profile
     *
     * @return $this
     * @throws LocalizedException
     */
    protected function createCustomerProfile()
    {
        if ($this->getCustomerId() > 0 || $this->getCustomerEmail() != '') {
            $gateway = $this->getMethodInstance()->gateway();

            $gateway->setParameter('merchantCustomerId', $this->getCustomerId());
            $gateway->setParameter('email', $this->getCustomerEmail());

            $profileId = $gateway->createCustomerProfile();

            if (!empty($profileId)) {
                $this->setProfileId($profileId);
                $this->getCustomer()->setAuthnetcimProfileId($profileId)
                                    ->setAuthnetcimProfileVersion(200);

                if ($this->getCustomer()->getId() > 0) {
                    $this->getCustomer()->save();
                }
            } else {
                $this->helper->log(
                    $this->getMethod(),
                    'Authorize.Net CIM Gateway: Unable to create customer profile.'
                );

                throw new LocalizedException(__('Authorize.Net CIM Gateway: Unable to create customer profile.'));
            }
        } else {
            $this->helper->log(
                $this->getMethod(),
                'Authorize.Net CIM Gateway: Unable to create customer profile; email or user ID is required.'
            );

            throw new LocalizedException(
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
     * @throws LocalizedException
     */
    protected function createCustomerPaymentProfile($retry = true)
    {
        $this->helper->log(
            $this->getMethod(),
            sprintf(
                '_createCustomerPaymentProfile(%s) (profile_id %s, payment_id %s)',
                var_export($retry, 1),
                var_export($this->getProfileId(), 1),
                var_export($this->getPaymentId(), 1)
            )
        );

        $this->getMethodInstance()->setCard($this);

        $gateway = $this->getMethodInstance()->gateway();

        /**
         * Make sure we have a customer profile, first off.
         */
        if ($this->getProfileId() == '') {
            // Does the customer have a profile ID? Try to import it.
            if ($this->getCustomer()->getId() > 0 && $this->getCustomer()->getAuthnetcimProfileId() != '') {
                $this->setProfileId($this->getCustomer()->getAuthnetcimProfileId());
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

            $gateway->setParameter('customerProfileId', $this->getProfileId());

            $gateway->setParameter('billToFirstName', $address->getFirstname());
            $gateway->setParameter('billToLastName', $address->getLastname());
            $gateway->setParameter('billToCompany', $address->getData('company'));
            $gateway->setParameter('billToAddress', $address->getStreetFull());
            $gateway->setParameter('billToCity', $address->getCity());
            $gateway->setParameter('billToState', $address->getRegion());
            $gateway->setParameter('billToZip', $address->getPostcode());
            $gateway->setParameter('billToCountry', $address->getCountry());
            $gateway->setParameter('billToPhoneNumber', $address->getTelephone());
            $gateway->setParameter('billToFaxNumber', $address->getData('fax'));

            $gateway->setParameter('validationMode', $this->getMethodInstance()->getConfigData('validation_mode'));

            $this->setPaymentInfoOnCreate($gateway);

            $paymentId = $gateway->createCustomerPaymentProfile();
        } else {
            /**
             * If it does exist, update CIM.
             */
            $address = $this->getAddressObject();

            $gateway->setParameter('customerProfileId', $this->getProfileId());
            $gateway->setParameter('customerPaymentProfileId', $this->getPaymentId());

            $gateway->setParameter('billToFirstName', $address->getFirstname());
            $gateway->setParameter('billToLastName', $address->getLastname());
            $gateway->setParameter('billToCompany', $address->getData('company'));
            $gateway->setParameter('billToAddress', $address->getStreetFull());
            $gateway->setParameter('billToCity', $address->getCity());
            $gateway->setParameter('billToState', $address->getRegion());
            $gateway->setParameter('billToZip', $address->getPostcode());
            $gateway->setParameter('billToCountry', $address->getCountry());
            $gateway->setParameter('billToPhoneNumber', $address->getTelephone());
            $gateway->setParameter('billToFaxNumber', $address->getData('fax'));

            $this->setPaymentInfoOnUpdate($gateway);

            $gateway->updateCustomerPaymentProfile();

            $paymentId = $this->getPaymentId();
        }

        /**
         * Check for 'Record cannot be found' errors (changed Authorize.Net accounts).
         * If we find it, clear our data and try again (once, and only once!).
         */
        $response = $gateway->getLastResponse();
        if ($retry === true
            && isset($response['messages']['message']['code'])
            && $response['messages']['message']['code'] == 'E00040') {
            $this->setProfileId('');
            $this->setPaymentId('');

            if ($this->getCustomer()->getId() > 0 && $this->getCustomer()->getData('authnetcim_profile_id') != '') {
                $this->getCustomer()->setData('authnetcim_profile_id', '');
            }

            return $this->createCustomerPaymentProfile(false);
        }

        if (!empty($paymentId)) {
            /**
             * Prevent data from being updated multiple times in one request.
             */
            $this->setPaymentId($paymentId);
            $this->setData('no_sync', true);
        } else {
            $gateway->logLogs();

            throw new LocalizedException(__('Authorize.Net CIM Gateway: Unable to create payment record.'));
        }

        return $this;
    }

    /**
     * On card save, set payment data to the gateway. (Broken out for extensibility)
     *
     * @param \ParadoxLabs\TokenBase\Model\AbstractGateway $gateway
     * @return $this
     */
    protected function setPaymentInfoOnCreate(\ParadoxLabs\TokenBase\Model\AbstractGateway $gateway)
    {
        $gateway->setParameter('cardNumber', $this->getInfoInstance()->getCcNumber());
        $gateway->setParameter('cardCode', $this->getInfoInstance()->getCcCid());
        $gateway->setParameter(
            'expirationDate',
            sprintf("%04d-%02d", $this->getInfoInstance()->getCcExpYear(), $this->getInfoInstance()->getCcExpMonth())
        );

        return $this;
    }

    /**
     * On card update, set payment data to the gateway. (Broken out for extensibility)
     *
     * @param \ParadoxLabs\TokenBase\Model\AbstractGateway $gateway
     * @return $this
     * @throws LocalizedException
     */
    protected function setPaymentInfoOnUpdate(\ParadoxLabs\TokenBase\Model\AbstractGateway $gateway)
    {
        if (strlen($this->getInfoInstance()->getCcNumber()) >= 12) {
            $gateway->setParameter('cardNumber', $this->getInfoInstance()->getCcNumber());
        } else {
            // If we were not given a full CC number, grab the masked value from Authorize.Net.
            $profile = $gateway->getCustomerPaymentProfile();

            if (isset($profile['paymentProfile']) && isset($profile['paymentProfile']['payment']['creditCard'])) {
                $gateway->setParameter('cardNumber', $profile['paymentProfile']['payment']['creditCard']['cardNumber']);
            } else {
                throw new LocalizedException(__('Authorize.Net CIM Gateway: Could not load payment record.'));
            }
        }

        if ($this->getInfoInstance()->getCcExpYear() != '' && $this->getInfoInstance()->getCcExpMonth() != '') {
            $gateway->setParameter(
                'expirationDate',
                sprintf(
                    "%04d-%02d",
                    $this->getInfoInstance()->getCcExpYear(),
                    $this->getInfoInstance()->getCcExpMonth())
            );
        } else {
            $gateway->setParameter('expirationDate', 'XXXX');
        }

        $gateway->setParameter('cardCode', $this->getInfoInstance()->getCcCid());

        return $this;
    }
}
