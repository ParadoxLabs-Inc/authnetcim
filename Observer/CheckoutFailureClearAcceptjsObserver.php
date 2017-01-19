<?php
/**
 * Paradox Labs, Inc.
 * http://www.paradoxlabs.com
 * 717-431-3330
 */

namespace ParadoxLabs\Authnetcim\Observer;

/**
 * CheckoutFailureClearAcceptjsObserver Class
 */
class CheckoutFailureClearAcceptjsObserver implements \Magento\Framework\Event\ObserverInterface
{
    /**
     * @var \Magento\Sales\Api\OrderPaymentRepositoryInterface
     */
    private $orderPaymentRepository;

    /**
     * @var \Magento\Quote\Api\CartRepositoryInterface
     */
    private $quoteRepository;

    /**
     * PaymentMethodAssignAcceptjsDataObserver constructor.
     *
     * @param \Magento\Sales\Api\OrderPaymentRepositoryInterface $orderPaymentRepository
     * @param \Magento\Quote\Api\CartRepositoryInterface $quoteRepository
     */
    public function __construct(
        \Magento\Sales\Api\OrderPaymentRepositoryInterface $orderPaymentRepository,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository
    ) {
        $this->orderPaymentRepository = $orderPaymentRepository;
        $this->quoteRepository = $quoteRepository;
    }

    /**
     * Assign data to the payment instance for our methods.
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            $this->clearAcceptJsTokens($observer->getEvent()->getData('order')->getPayment());
            $this->clearAcceptJsTokens($observer->getEvent()->getData('quote')->getPayment());
        } catch (\Exception $e) {
            // Ignore any errors; we don't want to throw them in this context.
        }
    }

    /**
     * Unset payment object values, to ensure they will not be reused.
     *
     * @param mixed $payment
     * @return $this
     */
    protected function clearAcceptJsTokens($payment)
    {
        if ($payment instanceof \Magento\Payment\Model\InfoInterface) {
            $acceptJsKey   = $payment->getAdditionalInformation('acceptjs_key');
            $acceptJsValue = $payment->getAdditionalInformation('acceptjs_value');

            if (!empty($acceptJsKey) || !empty($acceptJsValue)) {
                $payment->setAdditionalInformation('acceptjs_key', null);
                $payment->setAdditionalInformation('acceptjs_value', null);

                if ($payment->getId() > 0) {
                    if ($payment instanceof \Magento\Sales\Api\Data\OrderPaymentInterface) {
                        $this->orderPaymentRepository->save($payment);
                    } elseif ($payment instanceof \Magento\Quote\Api\Data\PaymentInterface
                        && $payment->getQuote() instanceof \Magento\Quote\Api\Data\CartInterface) {
                        $this->quoteRepository->save($payment->getQuote());
                    }
                }
            }
        }

        return $this;
    }
}
