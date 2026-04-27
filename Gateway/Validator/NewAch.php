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

use Magento\Payment\Gateway\Validator\ResultInterface;
use Magento\Payment\Model\Info;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\ConfigInterface;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use Magento\Payment\Model\InfoInterface;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider;
use Throwable;

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
     * @param ResultInterfaceFactory $resultFactory
     * @param ConfigInterface $config
     */
    public function __construct(
        ResultInterfaceFactory $resultFactory,
        protected readonly ConfigInterface $config
    ) {
        parent::__construct($resultFactory);
    }

    /**
     * Performs domain-related validation for business object
     *
     * @param array $validationSubject
     * @return ResultInterface
     */
    public function validate(array $validationSubject)
    {
        /** @var Info $payment */
        $payment = $validationSubject['payment'];
        $storeId = (int)$validationSubject['storeId'];

        try {
            $this->validateHostedTransaction($payment, $storeId);
        } catch (Throwable $exception) {
            return $this->createResult(false, [$exception->getMessage()]);
        }

        return parent::validate($validationSubject);
    }

    /**
     * If Hosted form is enabled, fetch and validate the transaction info.
     *
     * @param InfoInterface|OrderPayment|QuotePayment $payment
     * @param int $storeId
     * @return void
     * @throws LocalizedException
     */
    protected function validateHostedTransaction(InfoInterface $payment, int $storeId): void
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

        $order           = $payment->getOrder();
        $uncoveredAmount = (float)$order->getBaseGrandTotal() - (float)$transactionDetails['amount'];

        if ($transactionDetails['customer_email'] !== $order->getCustomerEmail()
            || $transactionDetails['invoice_number'] !== $order->getIncrementId()
            || $uncoveredAmount > 0.001) {
            throw new LocalizedException(__('Transaction failed, please try again.'));
        }

        $submitTime = strtotime((string) $transactionDetails['submit_time_utc']);
        $window     = 15 * 60; // Disallow transaction completion after 15 minutes
        if ($submitTime < (time() - $window)) {
            throw new LocalizedException(__('Transaction expired, please try again.'));
        }
    }
}
