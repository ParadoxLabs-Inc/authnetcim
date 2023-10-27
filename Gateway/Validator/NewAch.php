<?php declare(strict_types=1);
/**
 * Paradox Labs, Inc.
 * http://www.paradoxlabs.com
 * 717-431-3330
 *
 * Need help? Open a ticket in our support system:
 *  http://support.paradoxlabs.com
 *
 * @author      Ryan Hoerr <info@paradoxlabs.com>
 * @license     http://store.paradoxlabs.com/license.html
 */

namespace ParadoxLabs\Authnetcim\Gateway\Validator;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider;

/**
 * Ach Class
 */
class NewAch extends \ParadoxLabs\TokenBase\Gateway\Validator\NewAch
{
    /**
     * @var array
     */
    protected $achFields = [
        'echeck_account_name',
        'echeck_bank_name',
        'echeck_routing_no',
        'echeck_account_no',
        'echeck_account_type',
    ];

    /**
     * @var \Magento\Payment\Gateway\ConfigInterface
     */
    protected $config;

    /**
     * @param \Magento\Payment\Gateway\Validator\ResultInterfaceFactory $resultFactory
     * @param \Magento\Payment\Gateway\ConfigInterface $config
     */
    public function __construct(
        \Magento\Payment\Gateway\Validator\ResultInterfaceFactory $resultFactory,
        \Magento\Payment\Gateway\ConfigInterface $config
    ) {
        parent::__construct($resultFactory);

        $this->config = $config;
    }

    /**
     * Performs domain-related validation for business object
     *
     * @param array $validationSubject
     * @return \Magento\Payment\Gateway\Validator\ResultInterface
     */
    public function validate(array $validationSubject)
    {
        /** @var \Magento\Payment\Model\Info $payment */
        $payment = $validationSubject['payment'];
        $storeId = (int)$validationSubject['storeId'];

        try {
            $this->validateHostedTransaction($payment, $storeId);
        } catch (\Exception $exception) {
            return $this->createResult(false, [$exception->getMessage()]);
        }

        return parent::validate($validationSubject);
    }

    /**
     * If Hosted form is enabled, fetch and validate the transaction info.
     *
     * @param \Magento\Payment\Model\InfoInterface|OrderPayment|QuotePayment $payment
     * @param int $storeId
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function validateHostedTransaction(\Magento\Payment\Model\InfoInterface $payment, int $storeId): void
    {
        if ($this->config->getValue('form_type') !== ConfigProvider::FORM_HOSTED
            || $payment instanceof OrderPayment === false
            || empty($payment->getAdditionalInformation('transaction_id'))) {
            return;
        }

        $transactionDetails = $payment->getAdditionalInformation();

        if (!in_array((int)$transactionDetails['response_code'], [1, 4], true)) {
            throw new LocalizedException(__('Transaction was declined.'));
        }

        $order = $payment->getOrder();

        if ($transactionDetails['customer_email'] !== $order->getCustomerEmail()
            || $transactionDetails['invoice_number'] !== $order->getIncrementId()
            || $transactionDetails['amount'] < $order->getBaseGrandTotal()) {
            throw new LocalizedException(__('Transaction failed, please try again.'));
        }

        $submitTime = strtotime($transactionDetails['submit_time_utc']);
        $window     = 15 * 60; // Disallow transaction completion after 15 minutes
        if ($submitTime < (time() - $window)) {
            throw new LocalizedException(__('Transaction expired, please try again.'));
        }
    }
}
