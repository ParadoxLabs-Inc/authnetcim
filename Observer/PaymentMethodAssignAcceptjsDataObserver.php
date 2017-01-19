<?php
/**
 * Paradox Labs, Inc.
 * http://www.paradoxlabs.com
 * 717-431-3330
 */

namespace ParadoxLabs\Authnetcim\Observer;

use Magento\Payment\Observer\AbstractDataAssignObserver;

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
        $method = $observer->getData(AbstractDataAssignObserver::METHOD_CODE);
        $tokenbaseMethod = $this->methodFactory->getMethodInstance($method->getCode());

        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = $observer->getData(AbstractDataAssignObserver::MODEL_CODE);

        /** @var \Magento\Framework\DataObject $data */
        $data = $observer->getData(AbstractDataAssignObserver::DATA_CODE);

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
        }
    }
}
