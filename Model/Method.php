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

/**
 * Authorize.Net CIM payment method
 */
class Method extends \ParadoxLabs\TokenBase\Model\AbstractMethod
{
    /**
     * Determine whether Accept.js is configured and enabled.
     *
     * @return bool
     */
    public function isAcceptJsEnabled()
    {
        $clientKey = $this->getConfigData('client_key');

        if ($this->getConfigData('acceptjs') == 1 && !empty($clientKey)) {
            return true;
        }

        return false;
    }

    /**
     * Return boolean whether given payment object includes new card info.
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @return bool
     */
    protected function paymentContainsCard(\Magento\Payment\Model\InfoInterface $payment)
    {
        $acceptJsValue = $this->getInfoInstance()->getAdditionalInformation('acceptjs_value');

        if (!empty($acceptJsValue)) {
            return true;
        }

        return parent::paymentContainsCard($payment);
    }

    /**
     * Try to convert legacy data inline.
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @return \ParadoxLabs\TokenBase\Api\Data\CardInterface
     */
    protected function loadOrCreateCard(\Magento\Payment\Model\InfoInterface $payment)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */

        // Check for stale Accept.js token
        $acceptJsValue = $this->getInfoInstance()->getAdditionalInformation('acceptjs_value');
        $acceptCardId  = $this->registry->registry('authnetcim-acceptjs-' . $acceptJsValue);
        if (!empty($acceptJsValue) && $acceptCardId !== null) {
            // If we already stored the current token as a card and recorded it as such (via arbitrary registry key),
            // we can't reuse it -- swap the card ID in and use that instead.
            $payment->setData('tokenbase_id', $acceptCardId);
            $payment->unsAdditionalInformation('acceptjs_key');
            $payment->unsAdditionalInformation('acceptjs_value');
        }

        if ($this->card !== null) {
            $this->log(sprintf('loadOrCreateCard(%s %s)', get_class($payment), $payment->getId()));

            $this->setCard($this->getCard());

            return $this->getCard();
        } elseif ($payment->hasData('tokenbase_id') !== true
            && $payment->getOrder()
            && $payment->getOrder()->getExtCustomerId() != '') {
            $this->log(sprintf('loadOrCreateCard(%s %s)', get_class($payment), $payment->getId()));

            /** @var \ParadoxLabs\Authnetcim\Model\Card $card */
            $card = $this->cardFactory->create();
            $card->setMethod($this->methodCode)
                 ->setMethodInstance($this)
                 ->setCustomer($this->getCustomer(), $payment)
                 ->setAddress($payment->getOrder()->getBillingAddress())
                 ->importLegacyData($payment);

            $card = $this->cardRepository->save($card);

            $this->setCard($card);

            return $card;
        }

        return parent::loadOrCreateCard($payment);
    }

    /**
     * Set shipping address on the gateway before running the transaction.
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @return $this
     */
    protected function handleShippingAddress(\Magento\Payment\Model\InfoInterface $payment)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */

        if ($this->getConfigData('send_shipping_address') && $payment->getOrder()->getIsVirtual() == false) {
            /** @var \Magento\Sales\Model\Order\Address $address */
            $address = $payment->getOrder()->getShippingAddress();

            $region  = $address->getRegionCode() ?: $address->getRegion();

            $this->gateway()->setParameter('shipToFirstName', $address->getFirstname());
            $this->gateway()->setParameter('shipToLastName', $address->getLastname());
            $this->gateway()->setParameter('shipToCompany', $address->getCompany());
            $this->gateway()->setParameter('shipToAddress', implode(' ', $address->getStreet() ?: []));
            $this->gateway()->setParameter('shipToCity', $address->getCity());
            $this->gateway()->setParameter('shipToState', $region);
            $this->gateway()->setParameter('shipToZip', $address->getPostcode());
            $this->gateway()->setParameter('shipToCountry', $address->getCountryId());
            $this->gateway()->setParameter('shipToPhoneNumber', $address->getTelephone());
            $this->gateway()->setParameter('shipToFaxNumber', $address->getFax());
        }

        return $this;
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
        $this->fixLegacyCcType($payment, $response);

        parent::afterAuthorize($payment, $amount, $response);
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
        $this->fixLegacyCcType($payment, $response);

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
        $card = $this->getCard();

        /** @var \Magento\Sales\Model\Order\Payment $payment */
        if ($card->getType() == null && $response->getData('card_type') != '') {
            $ccType = $this->helper->mapCcTypeToMagento($response->getData('card_type'));

            if ($ccType !== null) {
                $card->setType($ccType)
                     ->setData('no_sync', true);

                $this->card = $this->cardRepository->save($card);

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
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        if ($payment->getData('cc_avs_status') == '' && $response->getData('avs_result_code') != '') {
            $payment->setData('cc_avs_status', $response->getData('avs_result_code'));
        }

        if ($payment->getData('cc_cid_status') == '' && $response->getData('card_code_response_code') != '') {
            $payment->setData('cc_cid_status', $response->getData('card_code_response_code'));
        }

        if ($payment->getData('cc_status') == '' && $response->getData('cavv_response_code') != '') {
            $payment->setData('cc_status', $response->getData('cavv_response_code'));
        }

        if ($response->getData('auth_code') != '') {
            $payment->setData('cc_approval', $response->getData('auth_code'));
        }

        return $payment;
    }
}
