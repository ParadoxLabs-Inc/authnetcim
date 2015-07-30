<?php
/**
 * Authorize.Net CIM payment method object
 *
 * Paradox Labs, Inc.
 * http://www.paradoxlabs.com
 * 717-431-3330
 *
 * Need help? Open a ticket in our support system:
 *  http://support.paradoxlabs.com
 *
 * @author        Ryan Hoerr <magento@paradoxlabs.com>
 * @license       http://store.paradoxlabs.com/license.html
 */

namespace ParadoxLabs\Authnetcim\Model;

/**
 * Authorize.Net CIM payment method
 */
class Method extends \ParadoxLabs\TokenBase\Model\AbstractMethod
{
    /**
     * @var string
     */
    protected $_code = 'authnetcim';

    /**
     * @var string
     */
    protected $_formBlockType = 'ParadoxLabs\Authnetcim\Block\Form\Cc';

    /**
     * @var string
     */
    protected $_infoBlockType = 'ParadoxLabs\Authnetcim\Block\Info\Cc';

    /**
     * Payment Method feature
     *
     * @var bool
     */
    protected $_canFetchTransactionInfo = true;

    /**
     * Try to convert legacy data inline.
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @return \ParadoxLabs\TokenBase\Model\Card
     */
    protected function loadOrCreateCard(\Magento\Payment\Model\InfoInterface $payment)
    {
        if (!is_null($this->card)) {
            $this->log(sprintf('_loadOrCreateCard(%s %s)', get_class($payment), $payment->getId()));

            $this->setCard($this->getCard());

            return $this->getCard();
        } elseif ($payment->hasTokenbaseId() !== true
            && $payment->getOrder()
            && $payment->getOrder()->getExtCustomerId() != '') {
            $this->log(sprintf('_loadOrCreateCard(%s %s)', get_class($payment), $payment->getId()));

            /** @var \ParadoxLabs\Authnetcim\Model\Card $card */
            $card = $this->cardFactory->create();
            $card->setMethod($this->_code)
                 ->setMethodInstance($this)
                 ->setCustomer($this->getCustomer(), $payment)
                 ->setAddress($payment->getOrder()->getBillingAddress())
                 ->importLegacyData($payment)
                 ->save();

            $this->setCard($card);

            return $card;
        }

        return parent::loadOrCreateCard($payment);
    }

    /**
     * Create shipping address record before running the transaction.
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @return $this
     */
    protected function createShippingAddress(\Magento\Payment\Model\InfoInterface $payment)
    {
        if ($this->getConfigData('send_shipping_address') && $payment->getOrder()->getIsVirtual() == false) {
            /** @var \Magento\Sales\Model\Order\Address $address */
            $address = $payment->getOrder()->getShippingAddress();

            // TODO: Is any of this needed anymore? Probably used to get authnetcim_shipping_id on the address
//            if ($address->getCustomerAddressId() != '') {
//                // TODO: fixme
//                $customerAddress = $this->addressFactory->create();
//                $customerAddress->load($address->getCustomerAddressId());
//
//                if ($customerAddress
//                    && $customerAddress->getId() == $address->getCustomerAddressId()
//                    && $customerAddress->getStreet(1) != '') {
//                    $address = $customerAddress;
//                }
//            }

            if ($address->getData('authnetcim_shipping_id') == '' && $address->getStreetLine(1) != '') {
                $this->log(sprintf('_createShippingAddress(%s %s)', get_class($address), $address->getId()));

                $this->gateway()->setParameter('customerProfileId', $this->getCard()->getProfileId());

                $this->gateway()->setParameter('shipToFirstName', $address->getFirstname());
                $this->gateway()->setParameter('shipToLastName', $address->getLastname());
                $this->gateway()->setParameter('shipToCompany', $address->getCompany());
                $this->gateway()->setParameter('shipToAddress', implode(' ', $address->getStreet()));
                $this->gateway()->setParameter('shipToCity', $address->getCity());
                $this->gateway()->setParameter('shipToState', $address->getRegion());
                $this->gateway()->setParameter('shipToZip', $address->getPostcode());
                $this->gateway()->setParameter('shipToCountry', $address->getCountryId());
                $this->gateway()->setParameter('shipToPhoneNumber', $address->getTelephone());
                $this->gateway()->setParameter('shipToFaxNumber', $address->getFax());

                $shippingId = $this->gateway()->createCustomerShippingAddress();

                $address->setData('authnetcim_shipping_id', $shippingId)->save();
            } else {
                $shippingId = $address->getData('authnetcim_shipping_id');
            }

            if (!empty($shippingId)) {
                $this->gateway()->setParameter('customerShippingAddressId', $shippingId);
            }
        }

        return $this;
    }

    /**
     * Catch execution before authorizing to include shipping address.
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param $amount
     * @return void
     */
    protected function beforeAuthorize(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $this->createShippingAddress($payment);

        parent::beforeAuthorize($payment, $amount);
    }

    /**
     * Catch execution after authorizing to look for card type.
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @param \ParadoxLabs\TokenBase\Model\Gateway\Response $response
     * @return void
     */
    protected function afterAuthorize(
        \Magento\Payment\Model\InfoInterface $payment,
        $amount,
        \ParadoxLabs\TokenBase\Model\Gateway\Response $response
    ) {
        $payment = $this->fixLegacyCcType($payment, $response);
        $payment = $this->storeTransactionStatuses($payment, $response);

        parent::afterAuthorize($payment, $amount, $response);
    }

    /**
     * Catch execution before capturing to include shipping address.
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return void
     */
    protected function beforeCapture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $this->createShippingAddress($payment);

        parent::beforeCapture($payment, $amount);
    }

    /**
     * Catch execution after capturing to reauthorize (if incomplete partial capture).
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @param \ParadoxLabs\TokenBase\Model\Gateway\Response $response
     * @return void
     */
    protected function afterCapture(
        \Magento\Payment\Model\InfoInterface $payment,
        $amount,
        \ParadoxLabs\TokenBase\Model\Gateway\Response $response
    ) {
        $outstanding = round($payment->getOrder()->getBaseTotalDue() - $amount, 4);

        /**
         * If this is a pre-auth capture for less than the total value of the order,
         * try to reauthorize any remaining balance. So we have it.
         */
        if ($this->gateway()->getHaveAuthorized()
            && $this->getConfigData('advanced/require_ccv') !== true
            && $outstanding > 0) {
            try {
                $this->log(sprintf('afterCapture(): Reauthorizing for %s', $outstanding));

                $shippingId        = $this->gateway()->getParameter('customerShippingAddressId');

                $this->gateway()->clearParameters();
                $this->gateway()->setCard($this->gateway()->getCard());
                $this->gateway()->setParameter('customerShippingAddressId', $shippingId);
                $this->gateway()->setHaveAuthorized(true);

                $authResponse    = $this->gateway()->authorize($payment, $outstanding);

                $payment->getOrder()->setExtOrderId(
                    sprintf('%s:%s', $authResponse->getTransactionId(), $authResponse->getAuthCode())
                );
            } catch (\Exception $e) {
                $payment->getOrder()->setExtOrderId(sprintf('%s:', $response->getTransactionId()));
            }
        } else {
            $payment->getOrder()->setExtOrderId(sprintf('%s:', $response->getTransactionId()));
        }

        $payment = $this->fixLegacyCcType($payment, $response);
        $payment = $this->storeTransactionStatuses($payment, $response);

        parent::afterCapture($payment, $amount, $response);
    }

    /**
     * Save type for legacy cards if we don't have it. Run after auth/capture transactions.
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param \ParadoxLabs\TokenBase\Model\Gateway\Response $response
     * @return \Magento\Payment\Model\InfoInterface
     */
    protected function fixLegacyCcType(
        \Magento\Payment\Model\InfoInterface $payment,
        \ParadoxLabs\TokenBase\Model\Gateway\Response $response
    ) {
        if ($this->getCard()->getAdditional('cc_type') == null && $response->getCardType() != '') {
            $ccType = $this->helper->mapCcTypeToMagento($response->getCardType());

            if (!is_null($ccType)) {
                $this->getCard()->setAdditional('cc_type', $ccType)
                                ->setData('no_sync', true)
                                ->save();

                $payment->getOrder()->getPayment()->setCcType($ccType);
            }
        }

        return $payment;
    }

    /**
     * Store response statuses persistently.
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param \ParadoxLabs\TokenBase\Model\Gateway\Response $response
     * @return \Magento\Payment\Model\InfoInterface
     */
    protected function storeTransactionStatuses(
        \Magento\Payment\Model\InfoInterface $payment,
        \ParadoxLabs\TokenBase\Model\Gateway\Response $response
    ) {
        if ($payment->getData('cc_avs_status') == '' && $response->getData('avs_result_code') != '') {
            $payment->setData('cc_avs_status', $response->getData('avs_result_code'));
        }

        if ($payment->getData('cc_cid_status') == '' && $response->getData('card_code_response_code') != '') {
            $payment->setData('cc_cid_status', $response->getData('card_code_response_code'));
        }

        if ($payment->getData('cc_status') == '' && $response->getData('cavv_response_code') != '') {
            $payment->setData('cc_status', $response->getData('cavv_response_code'));
        }

        return $payment;
    }
}
