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
 * PaymentMethodAssignDataObserver Class
 */
class PaymentMethodAssignDataObserver extends \ParadoxLabs\TokenBase\Observer\PaymentMethodAssignDataObserver
{
    /**
     * @var \ParadoxLabs\TokenBase\Model\Method\Factory
     */
    private $methodFactory;

    /**
     * PaymentMethodAssignAcceptjsDataObserver constructor.
     *
     * @param \ParadoxLabs\TokenBase\Helper\Data $helper
     * @param \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository
     * @param \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
     */
    public function __construct(
        \ParadoxLabs\TokenBase\Helper\Data $helper,
        \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository,
        \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
    ) {
        parent::__construct($helper, $cardRepository);

        $this->methodFactory = $methodFactory;
    }

    /**
     * Assign data to the payment instance for our methods.
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param \Magento\Framework\DataObject $data
     * @param \Magento\Payment\Model\MethodInterface $method
     * @return void
     */
    protected function assignTokenbaseData(
        \Magento\Payment\Model\InfoInterface $payment,
        \Magento\Framework\DataObject $data,
        \Magento\Payment\Model\MethodInterface $method
    ) {
        /** @var \Magento\Sales\Model\Order\Payment $payment */

        $tokenbaseMethod = $this->methodFactory->getMethodInstance($method->getCode());

        /**
         * Store Accept.js info if given and enabled.
         */
        if ($tokenbaseMethod->isAcceptJsEnabled() === true
            && $data->getData('acceptjs_key') != ''
            && $data->getData('acceptjs_value') != '') {
            $payment->setAdditionalInformation('acceptjs_key', $data->getData('acceptjs_key'))
                    ->setAdditionalInformation('acceptjs_value', $data->getData('acceptjs_value'))
                    ->setCcLast4($data->getData('cc_last4'));

            if ($method->getConfigData('can_store_bin') == 1) {
                $payment->setAdditionalInformation('cc_bin', $data->getData('cc_bin'));
            }
        }

        /**
         * Store transaction ID if given and not Accept.js
         */
        if ($tokenbaseMethod->isAcceptJsEnabled() === false) {
            $payment->setAdditionalInformation('transaction_id', $data->getData('transaction_id'));
        }

        parent::assignTokenbaseData($payment, $data, $method);
    }
}
