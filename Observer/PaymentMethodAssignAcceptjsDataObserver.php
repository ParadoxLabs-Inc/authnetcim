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
 * PaymentMethodAssignAcceptjsDataObserver Class
 */
class PaymentMethodAssignAcceptjsDataObserver implements \Magento\Framework\Event\ObserverInterface
{
    /**
     * @var \ParadoxLabs\TokenBase\Model\Method\Factory
     */
    private $methodFactory;

    /**
     * PaymentMethodAssignAcceptjsDataObserver constructor.
     *
     * @param \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
     */
    public function __construct(
        \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
    ) {
        $this->methodFactory = $methodFactory;
    }

    /**
     * Assign data to the payment instance for our methods.
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        /** @var \Magento\Payment\Model\MethodInterface $method */
        $method = $observer->getData('method');
        $tokenbaseMethod = $this->methodFactory->getMethodInstance($method->getCode());

        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = $observer->getData('payment_model');

        /** @var \Magento\Framework\DataObject $data */
        $data = $observer->getData('data');

        /**
         * Merge together data from additional_data array
         */
        if ($data->hasData('additional_data')) {
            foreach ($data->getData('additional_data') as $key => $value) {
                if ($data->getData($key) == false) {
                    $data->setData($key, $value);
                }
            }
        }

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
    }
}
