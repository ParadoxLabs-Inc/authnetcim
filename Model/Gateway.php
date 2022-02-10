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
 * Authorize.Net CIM API Gateway - custom built for perfection.
 */
class Gateway extends \ParadoxLabs\TokenBase\Model\AbstractGateway
{
    /**
     * Authorize.Net registered solution ID
     *
     * @var string
     */
    const SOLUTION_ID = 'A1000133';

    /**
     * Authorize.Net transaction duplicate window
     *
     * @var int
     */
    const DUPLICATE_WINDOW = 30;

    /**
     * Transaction status codes indicating denial on review
     *
     * @var string[]
     */
    const DENY_STATUSES = [
        'declined',
        'expired',
        'failedReview',
        'generalError',
        'returnedItem',
        'voided',
    ];

    /**
     * @var string
     */
    protected $code = 'authnetcim';

    /**
     * @var string
     */
    protected $endpointLive = 'https://api2.authorize.net/xml/v1/request.api';

    /**
     * @var string
     */
    protected $endpointTest = 'https://apitest.authorize.net/xml/v1/request.api';

    /**
     * $fields defines validation for each API parameter or input.
     *
     * key => [
     *    'maxLength' => int,
     *    'noSymbols' => true|false,
     *    'charMask'  => (allowed characters in regex form),
     *    'enum'      => [ values ]
     * ]
     *
     * @var array
     */
    protected $fields = [
        'accountNumber'             => ['maxLength' => 17, 'charMask' => 'X\d'],
        'accountType'               => ['enum' => ['checking', 'savings', 'businessChecking']],
        'allowPartialAuth'          => ['enum' => ['true', 'false']],
        'amount'                    => [],
        'approvalCode'              => ['maxLength'],
        'bankName'                  => ['maxLength' => 50],
        'billToAddress'             => ['maxLength' => 60, 'noSymbols' => true],
        'billToCity'                => ['maxLength' => 40, 'noSymbols' => true],
        'billToCompany'             => ['maxLength' => 50, 'noSymbols' => true],
        'billToCountry'             => ['maxLength' => 60, 'noSymbols' => true],
        'billToFaxNumber'           => ['maxLength' => 25, 'charMask' => '\d\(\)\-\.'],
        'billToFirstName'           => ['maxLength' => 50, 'noSymbols' => true],
        'billToLastName'            => ['maxLength' => 50, 'noSymbols' => true],
        'billToPhoneNumber'         => ['maxLength' => 25, 'charMask' => '\d\(\)\-\.'],
        'billToState'               => ['maxLength' => 40, 'noSymbols' => true],
        'billToZip'                 => ['maxLength' => 20, 'noSymbols' => true],
        'cardCode'                  => ['maxLength' => 4, 'charMask' => '\d'],
        'cardNumber'                => ['maxLength' => 16, 'charMask' => 'X\d'],
        'centinelAuthIndicator'     => ['maxLength' => 2, 'charMask' => '\d'],
        'centinelAuthValue'         => [],
        'customerIp'                => [],
        'customerPaymentProfileId'  => [],
        'customerProfileId'         => [],
        'customerShippingAddressId' => [],
        'customerType'              => ['enum' => ['individual', 'business']],
        'dataDescriptor'            => ['noSymbols' => true],
        'dataValue'                 => ['charMask' => 'a-zA-Z0-9+\/\\='],
        'description'               => ['maxLength' => 255],
        'duplicateWindow'           => ['charMask' => '\d'],
        'dutyAmount'                => [],
        'dutyDescription'           => ['maxLength' => 255],
        'dutyName'                  => ['maxLength' => 31],
        'echeckType'                => ['enum' => ['CCD', 'PPD', 'TEL', 'WEB', 'ARC', 'BOC']],
        'email'                     => ['maxLength' => 255],
        'emailCustomer'             => ['enum' => ['true', 'false']],
        'expirationDate'            => ['maxLength' => 7],
        'includeIssuerInfo'         => ['enum' => ['true', 'false']],
        'invoiceNumber'             => ['maxLength' => 20, 'noSymbols' => true],
        'isFirstRecurringPayment'   => ['enum' => ['true', 'false']],
        'isFirstSubsequentAuth'     => ['enum' => ['true', 'false']],
        'isStoredCredentials'       => ['enum' => ['true', 'false']],
        'isSubsequentAuth'          => ['enum' => ['true', 'false']],
        'itemName'                  => ['maxLength' => 31, 'noSymbols' => true],
        'loginId'                   => ['maxLength' => 20],
        'merchantCustomerId'        => ['maxLength' => 20],
        'nameOnAccount'             => ['maxLength' => 22],
        'purchaseOrderNumber'       => ['maxLength' => 25, 'noSymbols' => true],
        'recurringBilling'          => ['enum' => ['true', 'false']],
        'refId'                     => ['maxLength' => 20],
        'routingNumber'             => ['maxLength' => 9, 'charMask' => 'X\d'],
        'shipAmount'                => [],
        'shipDescription'           => ['maxLength' => 255],
        'shipName'                  => ['maxLength' => 31],
        'shipToAddress'             => ['maxLength' => 60, 'noSymbols' => true],
        'shipToCity'                => ['maxLength' => 40, 'noSymbols' => true],
        'shipToCompany'             => ['maxLength' => 50, 'noSymbols' => true],
        'shipToCountry'             => ['maxLength' => 60, 'noSymbols' => true],
        'shipToFaxNumber'           => ['maxLength' => 25, 'charMask' => '\d\(\)\-\.'],
        'shipToFirstName'           => ['maxLength' => 50, 'noSymbols' => true],
        'shipToLastName'            => ['maxLength' => 50, 'noSymbols' => true],
        'shipToPhoneNumber'         => ['maxLength' => 25, 'charMask' => '\d\(\)\-\.'],
        'shipToState'               => ['maxLength' => 40, 'noSymbols' => true],
        'shipToZip'                 => ['maxLength' => 20, 'noSymbols' => true],
        'splitTenderId'             => ['maxLength' => 6],
        'subsequentAuthReason'      => ['enum' => ['delayedCharge', 'noShow', 'resubmission', 'reauthorization']],
        'taxAmount'                 => [],
        'taxDescription'            => ['maxLength' => 255],
        'taxExempt'                 => ['enum' => ['true', 'false']],
        'taxName'                   => ['maxLength' => 31],
        'transactionKey'            => ['maxLength' => 16, 'noSymbols' => true],
        'transactionType'           => [
            'enum' => [
                // Old types
                'profileTransAuthCapture',
                'profileTransAuthOnly',
                'profileTransCaptureOnly',
                'profileTransPriorAuthCapture',
                'profileTransRefund',
                'profileTransVoid',
                // New types
                'authCaptureTransaction',
                'authOnlyTransaction',
                'captureOnlyTransaction',
                'priorAuthCaptureTransaction',
                'refundTransaction',
                'voidTransaction',
            ],
        ],
        'transId'                   => ['charMask' => '\d'],
        'unmaskExpirationDate'      => ['enum' => ['true', 'false']],
        'userFields'                => [],
        'validationMode'            => ['enum' => ['liveMode', 'testMode', 'none']],
    ];

    /**
     * @var array
     */
    protected $txnTypeMap = [
        'authCaptureTransaction'      => 'auth_capture',
        'authOnlyTransaction'         => 'auth_only',
        'captureOnlyTransaction'      => 'capture_only',
        'priorAuthCaptureTransaction' => 'prior_auth_capture',
        'refundTransaction'           => 'credit',
        'voidTransaction'             => 'void',
    ];

    /**
     * @var \Magento\Framework\Module\Dir
     */
    protected $moduleDir;

    /**
     * @var \Magento\Framework\Registry
     */
    protected $registry;

    /**
     * Gateway constructor.
     *
     * @param \ParadoxLabs\TokenBase\Helper\Data $helper
     * @param \ParadoxLabs\TokenBase\Model\Gateway\Xml $xml
     * @param \ParadoxLabs\TokenBase\Model\Gateway\ResponseFactory $responseFactory
     * @param \Magento\Framework\HTTP\ZendClientFactory $httpClientFactory
     * @param \Magento\Framework\Module\Dir $moduleDir
     * @param \Magento\Framework\Registry $registry
     * @param array $data
     */
    public function __construct(
        \ParadoxLabs\TokenBase\Helper\Data $helper,
        \ParadoxLabs\TokenBase\Model\Gateway\Xml $xml,
        \ParadoxLabs\TokenBase\Model\Gateway\ResponseFactory $responseFactory,
        \Magento\Framework\HTTP\ZendClientFactory $httpClientFactory,
        \Magento\Framework\Module\Dir $moduleDir,
        \Magento\Framework\Registry $registry,
        array $data = []
    ) {
        $this->moduleDir = $moduleDir;
        $this->registry = $registry;

        parent::__construct(
            $helper,
            $xml,
            $responseFactory,
            $httpClientFactory,
            $data
        );
    }

    /**
     * Set the API credentials so they go through validation.
     *
     * @return $this
     */
    public function clearParameters()
    {
        parent::clearParameters();

        if (isset($this->defaults['login'], $this->defaults['password'])) {
            $this->setParameter('loginId', $this->defaults['login']);
            $this->setParameter('transactionKey', $this->defaults['password']);
        }

        return $this;
    }

    /**
     * Send the given request to Authorize.Net and process the results.
     *
     * @param string $request
     * @param array $params
     * @return array|string
     * @throws CommandException
     * @throws CommandException
     */
    protected function runTransaction($request, $params)
    {
        $auth = [
            '@attributes'            => [
                'xmlns' => 'AnetApi/xml/v1/schema/AnetApiSchema.xsd',
            ],
            'merchantAuthentication' => [
                'name'           => $this->getParameter('loginId'),
                'transactionKey' => $this->getParameter('transactionKey'),
            ],
        ];

        $xml = $this->arrayToXml($request, $auth + $params);

        $this->lastRequest = $xml;

        /** @var \Magento\Framework\HTTP\ZendClient $httpClient */
        $httpClient = $this->httpClientFactory->create();

        $clientConfig = [
            'adapter'     => \Zend_Http_Client_Adapter_Curl::class,
            'timeout'     => 900,
            'curloptions' => [
                CURLOPT_CAINFO         => $this->moduleDir->getDir('ParadoxLabs_Authnetcim') . '/authorizenet-cert.pem',
                CURLOPT_SSL_VERIFYPEER => false,
            ],
            'verifypeer' => false,
            'verifyhost' => 0,
        ];

        // If we are running a money transaction, we don't want to cut it off even if it takes too long.
        // Override that 900 second timeout only if this is a non-critical transaction.
        if (!in_array($request, ['createTransactionRequest', 'createCustomerProfileTransactionRequest'])) {
            $clientConfig['timeout'] = 15;
        }

        if ($this->verifySsl === true) {
            $clientConfig['curloptions'][CURLOPT_SSL_VERIFYPEER] = true;
            $clientConfig['curloptions'][CURLOPT_SSL_VERIFYHOST] = 2;
            $clientConfig['verifypeer'] = true;
            $clientConfig['verifyhost'] = 2;
        }

        $httpClient->setUri($this->endpoint);
        $httpClient->setConfig($clientConfig);
        $httpClient->setRawData($xml, 'text/xml');

        try {
            $response = $httpClient->request(\Zend_Http_Client::POST);

            $this->lastResponse = $response->getBody();

            if ($response->isSuccessful() && !empty($this->lastResponse)) {
                $this->log .= 'REQUEST: ' . $this->sanitizeLog($xml) . "\n";
                $this->log .= 'RESPONSE: ' . $this->sanitizeLog($this->lastResponse) . "\n";

                $this->lastResponse = $this->xmlToArray($this->lastResponse);

                if ($this->testMode === true) {
                    $this->helper->log($this->code, $this->log, true);
                }

                /**
                 * Check for basic errors.
                 */
                $this->handleTransactionError();
            } else {
                $this->helper->log(
                    $this->code,
                    sprintf(
                        "CURL Connection error: %s (%s)\nREQUEST: %s",
                        $httpClient->getAdapter()->getError(),
                        $httpClient->getAdapter()->getErrno(),
                        $this->sanitizeLog($xml)
                    )
                );

                throw new CommandException(
                    __(sprintf(
                        'Authorize.Net CIM Gateway Connection error: %s (%s)',
                        $httpClient->getAdapter()->getError(),
                        $httpClient->getAdapter()->getErrno()
                    ))
                );
            }
        } catch (\Zend_Http_Exception $e) {
            $this->helper->log(
                $this->code,
                sprintf(
                    "CURL Connection error: %s. %s (%s)\nREQUEST: %s",
                    $e->getMessage(),
                    $httpClient->getAdapter()->getError(),
                    $httpClient->getAdapter()->getErrno(),
                    $this->sanitizeLog($xml)
                )
            );

            throw new CommandException(
                __(sprintf(
                    'Authorize.Net CIM Gateway Connection error: %s. %s (%s)',
                    $e->getMessage(),
                    $httpClient->getAdapter()->getError(),
                    $httpClient->getAdapter()->getErrno()
                ))
            );
        }

        return $this->lastResponse;
    }

    /**
     * Mask certain values in the XML for secure logging purposes.
     *
     * @param $string
     * @return mixed
     */
    protected function sanitizeLog($string)
    {
        $maskAll = ['cardCode'];
        $maskFour = ['cardNumber', 'name', 'transactionKey', 'routingNumber', 'accountNumber'];

        foreach ($maskAll as $val) {
            $string = preg_replace('#' . $val . '>(.+?)</' . $val . '#', $val . '>XXX</' . $val, $string);
        }

        foreach ($maskFour as $val) {
            $start = strpos($string, '<' . $val . '>');
            $end = strpos($string, '</' . $val . '>', $start);
            $tagLen = strlen($val) + 2;

            if ($start !== false && $end > ($start + $tagLen + 4)) {
                $string = substr_replace($string, 'XXXX', $start + $tagLen, $end - 4 - ($start + $tagLen));
            }
        }

        return $string;
    }

    /**
     * Convert XML string to array. See \ParadoxLabs\TokenBase\Model\Gateway\Xml
     *
     * @param string $xml
     * @return array
     */
    protected function xmlToArray($xml)
    {
        // Strip bad namespace out before we try to parse it. ...
        $xml = str_replace(' xmlns="AnetApi/xml/v1/schema/AnetApiSchema.xsd"', '', $xml);

        return parent::xmlToArray($xml);
    }

    /**
     * Turn transaction results and directResponse into a usable object.
     *
     * @param array $transactionResult
     * @return \ParadoxLabs\TokenBase\Model\Gateway\Response
     * @throws CommandException
     * @throws CommandException
     */
    protected function interpretTransaction($transactionResult)
    {
        /**
         * Check for not-found error first. If that error makes it here, that means they attempted to use a stored card
         * that could not be found (deleted, or account change, or such). Any way about it the card is no longer valid.
         */
        if ($transactionResult['messages']['resultCode'] !== 'Ok') {
            $errorCode = $transactionResult['messages']['message']['code'];
            $errorText = $transactionResult['messages']['message']['text'];

            if ($errorCode === 'E00040'
                && $errorText === 'Customer Profile ID or Customer Payment Profile ID not found.'
            ) {
                if ($this->hasData('card')) {
                    /**
                     * We know the card is not valid, so hide and get rid of it. Except we're in the middle
                     * of a transaction... so any change will just be rolled back. Save it for a little later.
                     * @see \ParadoxLabs\TokenBase\Observer\CardLoadProcessDeleteQueueObserver::execute()
                     */
                    $this->registry->unregister('queue_card_deletion');
                    $this->registry->register('queue_card_deletion', $this->getData('card'));
                }

                $this->helper->log(
                    $this->code,
                    sprintf("API error: %s: %s\n%s", $errorCode, $errorText, $this->log)
                );

                throw new CommandException(
                    __('Sorry, we were unable to find your payment record. '
                        . 'Please re-enter your payment info and try again.')
                );
            }

            if ($errorCode === 'E00040' && $errorText === 'Customer Shipping Address ID not found.') {
                /**
                 * Invalid shipping ID. We should retry, but that's hard to do with this architecture.
                 * In a transaction, no events, ...
                 */
                $this->helper->log(
                    $this->code,
                    sprintf("API error: %s: %s\n%s", $errorCode, $errorText, $this->log)
                );

                throw new CommandException(
                    __(sprintf('Authorize.Net CIM Gateway: %s Please contact support, or delete your '
                        . 'shipping address in My Account and try again.', $errorText))
                );
            }
        }

        /**
         * Turn response into a consistent data object, as best we can
         */
        if (isset($transactionResult['directResponse'])) {
            $data = $this->getDataFromDirectResponse($transactionResult['directResponse']);
        } elseif (isset($transactionResult['transactionResponse'])) {
            $data = $this->getDataFromTransactionResponse($transactionResult['transactionResponse']);
        } else {
            $this->helper->log(
                $this->code,
                sprintf("Authorize.Net CIM Gateway: Transaction failed; no response.\n%s", $this->log)
            );

            throw new CommandException(
                __('Authorize.Net CIM Gateway: Transaction failed; no response. '
                    . 'Please re-enter your payment info and try again.')
            );
        }

        /** @var \ParadoxLabs\TokenBase\Model\Gateway\Response $response */
        $response = $this->responseFactory->create();
        $response->setData($data);

        if ((int)$response->getResponseCode() === 4) {
            $response->setIsFraud(true);
        }

        /**
         * Response 54 is 'can't refund; txn has not settled.' 16 is 'cannot find txn' (expired).
         * Allow those through; they're handled elsewhere.
         */
        if (in_array((int)$response->getResponseReasonCode(), [16, 54], true)) {
            return $response;
        }

        /**
         * Fail if:
         * Error result
         * OR error/decline response code
         * OR no transID on a charge txn
         */
        if ($transactionResult['messages']['resultCode'] !== 'Ok'
            || (int)$response->getResponseCode() === 2
            || (int)$response->getResponseCode() === 3
            || (empty($response->getTransactionId()) && !in_array($response->getTransactionType(), ['credit', 'void']))
        ) {
            $response->setIsError(true);

            $this->helper->log(
                $this->code,
                sprintf(
                    "Transaction error: %s\n%s\n%s",
                    $response->getResponseReasonText(),
                    json_encode($response->getData()),
                    $this->log
                )
            );

            if ($response->getTransactionId() === '0' && $response->getAuthCode() === '000000') {
                throw new CommandException(
                    __('Transaction failed. Please disable test mode in Authorize.Net.')
                );
            }

            throw new CommandException(
                __('Authorize.Net CIM Gateway: Transaction failed. ' . $response->getResponseReasonText())
            );
        }

        return $response;
    }

    /**
     * These should be implemented by the child gateway.
     *
     * @param \ParadoxLabs\TokenBase\Api\Data\CardInterface $card
     * @return $this
     */
    public function setCard(\ParadoxLabs\TokenBase\Api\Data\CardInterface $card)
    {
        $this->setParameter('email', $card->getCustomerEmail());
        $this->setParameter('merchantCustomerId', $card->getCustomerId());
        $this->setParameter('customerProfileId', $card->getProfileId());
        $this->setParameter('customerPaymentProfileId', $card->getPaymentId());
        $this->setParameter('customerIp', $card->getCustomerIp());

        parent::setCard($card);

        return $this;
    }

    /**
     * Run an auth transaction for $amount with the given payment info
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return \ParadoxLabs\TokenBase\Model\Gateway\Response
     */
    public function authorize(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */

        $this->setParameter('transactionType', 'authOnlyTransaction');
        $this->setParameter('amount', $amount);
        $this->setParameter('invoiceNumber', $payment->getOrder()->getIncrementId());

        if ($this->getHaveAuthorized() !== true) {
            if ($payment->getOrder()->getBaseTaxAmount()) {
                $this->setParameter('taxAmount', $payment->getOrder()->getBaseTaxAmount());
            }

            if ($payment->getBaseShippingAmount()) {
                $this->setParameter('shipAmount', $payment->getBaseShippingAmount());
            }
        } else {
            $this->setParameter('subsequentAuthReason', 'reauthorization');
        }

        if ($payment->hasData('cc_cid') && !empty($payment->getData('cc_cid'))) {
            $this->setParameter('cardCode', $payment->getData('cc_cid'));
        }

        if ($this->getCard()->getLastUse() === null
            && $payment->getMethodInstance() !== null
            && $payment->getMethodInstance()->getConfigData('validation_mode') !== 'liveMode') {
            $this->setParameter('isFirstSubsequentAuth', 'true');
        }

        if ($this->helper->getIsFrontend()) {
            $this->setParameter('isStoredCredentials', 'true');
        } else {
            $this->setParameter('isSubsequentAuth', 'true');
        }

        if ((int)$payment->getAdditionalInformation('is_subscription_generated') === 1) {
            $this->setParameter('recurringBilling', 'true');
        }

        $result = $this->createTransaction();
        return $this->interpretTransaction($result);
    }

    /**
     * Run a capture transaction for $amount with the given payment info
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @param string $transactionId
     * @return \ParadoxLabs\TokenBase\Model\Gateway\Response
     */
    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount, $transactionId = null)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */

        if ($this->getHaveAuthorized()) {
            $this->setParameter('transactionType', 'priorAuthCaptureTransaction');

            if ($transactionId !== null) {
                $this->setParameter('transId', $transactionId);
            } else {
                $this->setParameter('transId', $payment->getData('transaction_id'));
            }
        }

        if ($this->getHaveAuthorized() === false || empty($this->getTransactionId())) {
            $this->setParameter('transactionType', 'authCaptureTransaction');

            if ($this->helper->getIsFrontend()) {
                $this->setParameter('isStoredCredentials', 'true');
            } else {
                $this->setParameter('isSubsequentAuth', 'true');
            }

            if ((int)$payment->getAdditionalInformation('is_subscription_generated') === 1) {
                $this->setParameter('recurringBilling', 'true');
            }
        }

        $this->setParameter('amount', $amount);
        $this->setParameter('invoiceNumber', $payment->getOrder()->getIncrementId());

        $this->captureGetAmountInfo($payment);

        if ($payment->hasData('cc_cid') && !empty($payment->getData('cc_cid'))) {
            $this->setParameter('cardCode', $payment->getData('cc_cid'));
        }

        $result = $this->createTransaction();
        $response = $this->interpretTransaction($result);

        /**
         * Check for and handle 'transaction not found' error (expired authorization).
         */
        if ((int)$response->getResponseReasonCode() === 16 && !empty($this->getParameter('transId'))) {
            $this->helper->log(
                $this->code,
                sprintf("Transaction not found. Attempting to recapture.\n%s", json_encode($response->getData()))
            );

            $this->setParameter('transId', null)
                 ->setHaveAuthorized(false)
                 ->setCard($this->getData('card'));

            $response = $this->capture($payment, $amount, '');
        }

        return $response;
    }

    /**
     * Run a refund transaction for $amount with the given payment info
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @param string $transactionId
     * @return \ParadoxLabs\TokenBase\Model\Gateway\Response
     * @throws CommandException
     */
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount, $transactionId = null)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */

        $this->setParameter('transactionType', 'refundTransaction');
        $this->setParameter('amount', $amount);
        $this->setParameter('invoiceNumber', $payment->getOrder()->getIncrementId());

        if ($payment->getCreditmemo() instanceof \Magento\Sales\Api\Data\CreditmemoInterface) {
            if ($payment->getCreditmemo()->getBaseTaxAmount()) {
                $this->setParameter('taxAmount', $payment->getCreditmemo()->getBaseTaxAmount());
            }

            if ($payment->getCreditmemo()->getBaseShippingAmount()) {
                $this->setParameter('shipAmount', $payment->getCreditmemo()->getBaseShippingAmount());
            }
        }

        if ($transactionId !== null) {
            $this->setParameter('transId', $transactionId);
        } elseif (!empty($payment->getTransactionId())) {
            $this->setParameter('transId', $payment->getTransactionId());
        }

        $result = $this->createTransaction();
        $response = $this->interpretTransaction($result);

        /**
         * Check for 'transaction unsettled' error.
         */
        if ((int)$response->getResponseReasonCode() === 54) {
            /**
             * Is this a full refund? If so, just void it. Nobody will see the difference.
             */
            if ($payment->getCreditmemo() instanceof \Magento\Sales\Api\Data\CreditmemoInterface
                && $amount == $payment->getCreditmemo()->getInvoice()->getBaseGrandTotal()) {
                $transactionId = $this->getParameter('transId');

                return $this->clearParameters()
                            ->setCard($this->getData('card'))
                            ->void($payment, $transactionId);
            }

            $response->setIsError(true);

            $this->helper->log(
                $this->code,
                sprintf(
                    "Transaction error: %s\n%s\n%s",
                    $response->getResponseReasonText(),
                    json_encode($response->getData()),
                    $this->log
                )
            );

            throw new CommandException(
                __('Authorize.Net CIM Gateway: Transaction failed. ' . $response->getResponseReasonText())
            );
        }

        return $response;
    }

    /**
     * Run a void transaction for the given payment info
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param string $transactionId
     * @return \ParadoxLabs\TokenBase\Model\Gateway\Response
     */
    public function void(\Magento\Payment\Model\InfoInterface $payment, $transactionId = null)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */

        $this->setParameter('transactionType', 'voidTransaction');

        if ($transactionId !== null) {
            $this->setParameter('transId', $transactionId);
        } elseif (!empty($payment->getTransactionId())) {
            $this->setParameter('transId', $payment->getTransactionId());
        }

        $result = $this->createTransaction();
        return $this->interpretTransaction($result);
    }

    /**
     * Fetch a transaction status update
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param string $transactionId
     * @return \ParadoxLabs\TokenBase\Model\Gateway\Response
     */
    public function fraudUpdate(\Magento\Payment\Model\InfoInterface $payment, $transactionId)
    {
        $this->setParameter('transId', $transactionId);

        $result = $this->getTransactionDetails();

        foreach ($result as $k => $v) {
            if (is_array($v)) {
                foreach ($v as $l => $u) {
                    if (is_array($u)) {
                        $u = json_encode($u);
                    }

                    $result[ $k . '_' . $l ] = $u;

                    unset($result[ $k ][ $l ]);
                }

                if (empty($result[ $k ])) {
                    unset($result[ $k ]);
                }
            }
        }

        /** @var \ParadoxLabs\TokenBase\Model\Gateway\Response $response */
        $response = $this->responseFactory->create();
        $response->setData($result + ['is_approved' => false, 'is_denied' => false]);

        $responseReasonCode = (int)$response->getData('response_reason_code');
        if (in_array($responseReasonCode, [2, 254], true)
            || (int)$response->getData('response_code') === 2
            || in_array($response->getData('transaction_status'), static::DENY_STATUSES, true)
        ) {
            // Transaction pending review -> denied
            $response->setData('is_denied', true);
        } elseif ((int)$response->getData('response_code') === 1) {
            $response->setData('is_approved', true);
        }

        return $response;
    }

    /**
     * Set authorization code for the next transaction
     *
     * @param string $authCode
     * @return $this
     */
    public function setAuthCode($authCode)
    {
        $this->setParameter('approvalCode', $authCode);

        return $this;
    }

    /**
     * Get transaction ID.
     *
     * @return string
     */
    public function getTransactionId()
    {
        return $this->getParameter('transId');
    }

    /**
     * Set prior transaction ID for next transaction.
     *
     * @param $transactionId
     * @return $this
     */
    public function setTransactionId($transactionId)
    {
        $this->setParameter('transId', $transactionId);

        return $this;
    }

########################################################################################################################
#### API methods: See the Authorize.Net CIM XML documentation.
#### http://developer.authorize.net/api/reference/
########################################################################################################################

    /**
     * Create a CIM customer profile.
     *
     * @return string CIM customer profile ID
     * @throws CommandException
     */
    public function createCustomerProfile()
    {
        $params = [
            'profile' => [
                'merchantCustomerId' => (int)$this->getParameter('merchantCustomerId'),
                'description'        => $this->getParameter('description'),
                'email'              => $this->getParameter('email'),
            ],
        ];

        $result = $this->runTransaction('createCustomerProfileRequest', $params);

        if (isset($result['customerProfileId'])) {
            return $result['customerProfileId'];
        }

        $text = (string)$this->helper->getArrayValue($result, 'messages/message/text');
        if (strpos($text, 'duplicate') !== false) {
            return preg_replace('/[^0-9]/', '', $text);
        }

        $this->logLogs();

        throw new CommandException(
            __(
                'Authorize.Net CIM Gateway: Unable to create customer profile. %1',
                $result['messages']['message']['text']
            )
        );
    }

    /**
     * Create a CIM payment profile.
     *
     * @return string CIM card payment ID
     * @throws CommandException
     * @throws CommandException
     */
    public function createCustomerPaymentProfile()
    {
        $params = [
            'customerProfileId' => $this->getParameter('customerProfileId'),
            'paymentProfile'    => [
                'billTo'  => [
                    'firstName'   => $this->getParameter('billToFirstName'),
                    'lastName'    => $this->getParameter('billToLastName'),
                    'company'     => $this->getParameter('billToCompany'),
                    'address'     => $this->getParameter('billToAddress'),
                    'city'        => $this->getParameter('billToCity'),
                    'state'       => $this->getParameter('billToState'),
                    'zip'         => $this->getParameter('billToZip'),
                    'country'     => $this->getParameter('billToCountry'),
                    'phoneNumber' => $this->getParameter('billToPhoneNumber'),
                    'faxNumber'   => $this->getParameter('billToFaxNumber'),
                ],
                'payment' => [],
            ],
            'validationMode'    => $this->getParameter('validationMode', 'testMode'),
        ];

        if ($this->hasParameter('customerType')) {
            $params['paymentProfile'] = [
                    'customerType' => $this->getParameter('customerType'),
                ] + $params['paymentProfile'];
        }

        $params = $this->createCustomerPaymentProfileAddPaymentInfo($params);

        $result = $this->runTransaction('createCustomerPaymentProfileRequest', $params);
        $paymentId = null;

        if (isset($result['customerPaymentProfileId'])) {
            $paymentId = $result['customerPaymentProfileId'];
        }

        $text = $this->helper->getArrayValue($result, 'messages/message/text');

        if (strpos($text, 'duplicate') !== false) {
            /**
             * Handle duplicate card errors. Painful process.
             */
            if (empty($paymentId)) {
                $paymentId = preg_replace('/[^0-9]/', '', $text);
            }

            /**
             * If we still have no payment ID, try to match the duplicate manually.
             * Authorize.Net does not return the ID in this duplicate error message, contrary to documentation.
             */
            if (empty($paymentId)) {
                $paymentId = $this->findDuplicateCard();
            }

            if (!empty($paymentId)) {
                // Update the card record to ensure expiry is up to date.
                $this->setParameter('customerPaymentProfileId', $paymentId);

                // Handle Accept.js nonce (which would now be expired). Card number won't have changed, but exp might.
                if ($this->hasParameter('dataValue')) {
                    $expDate = date('Y-m', strtotime((string)$this->getCard()->getExpires()));
                    $this->setParameter('cardNumber', 'XXXX' . $this->getCard()->getAdditional('cc_last4'));
                    $this->setParameter('expirationDate', $expDate);
                }

                if ($this->getParameter('cardNumber') !== 'XXXX'
                    && $this->getParameter('expirationDate') !== '1970-01') {
                    $this->updateCustomerPaymentProfile();
                }
            }
        }

        return $paymentId;
    }

    /**
     * Create a CIM shipping address record.
     *
     * @return string CIM address ID
     * @throws CommandException
     */
    public function createCustomerShippingAddress()
    {
        $params = [
            'customerProfileId' => $this->getParameter('customerProfileId'),
            'address'           => [
                'firstName'   => $this->getParameter('shipToFirstName'),
                'lastName'    => $this->getParameter('shipToLastName'),
                'company'     => $this->getParameter('shipToCompany'),
                'address'     => $this->getParameter('shipToAddress'),
                'city'        => $this->getParameter('shipToCity'),
                'state'       => $this->getParameter('shipToState'),
                'zip'         => $this->getParameter('shipToZip'),
                'country'     => $this->getParameter('shipToCountry'),
                'phoneNumber' => $this->getParameter('shipToPhoneNumber'),
                'faxNumber'   => $this->getParameter('shipToFaxNumber'),
            ],
        ];

        $result = $this->runTransaction('createCustomerShippingAddressRequest', $params);

        if (isset($result['customerAddressId'])) {
            return $result['customerAddressId'];
        }

        $text = (string)$this->helper->getArrayValue($result, 'messages/message/text');
        if (strpos($text, 'duplicate') !== false) {
            /**
             * Handle duplicate address errors. blah.
             */
            $profile = $this->getCustomerProfile();

            if (isset($profile['profile']['shipToList']) && !empty($profile['profile']['shipToList'])) {
                if (isset($profile['profile']['shipToList']['customerAddressId'])) {
                    return $profile['profile']['shipToList']['customerAddressId'];
                }

                foreach ($profile['profile']['shipToList'] as $address) {
                    if ($this->isAddressDuplicate($address, $params['address']) === true) {
                        return $address['customerAddressId'];
                    }
                }
            }
        }

        // If we got this far, that means we couldn't create or find any address.
        $this->logLogs();

        throw new CommandException(
            __('Authorize.Net CIM Gateway: Unable to create shipping address record.')
        );
    }

    /**
     * Find a duplicate CIM record matching the one we just tried to create.
     *
     * @return string|bool CIM payment id, or false if none
     */
    public function findDuplicateCard()
    {
        $profile = $this->getCustomerProfile();
        $lastFour = substr((string)$this->getParameter('cardNumber'), -4);

        if (isset($profile['profile']['paymentProfiles']) && !empty($profile['profile']['paymentProfiles'])) {
            // If there's only one, just stop. It has to be the match.
            if (isset($profile['profile']['paymentProfiles']['billTo'])) {
                $card = $profile['profile']['paymentProfiles'];
                return $card['customerPaymentProfileId'];
            }

            // Otherwise, compare end of the card number for each until one matches.
            foreach ($profile['profile']['paymentProfiles'] as $card) {
                if (isset($card['payment']['creditCard'])
                    && $lastFour === substr((string)$card['payment']['creditCard']['cardNumber'], -4)
                ) {
                    return $card['customerPaymentProfileId'];
                }
            }
        }

        return false;
    }

    /**
     * Run an actual transaction with Authorize.Net with stored data.
     *
     * @return array Raw transaction result (XML)
     * @deprecated since 2.2.4 - see createTransaction()
     */
    public function createCustomerProfileTransaction()
    {
        $type = $this->getParameter('transactionType');

        $params = [
            'transaction'  => [
                $type => [
                ],
            ],
            'extraOptions' => ['@cdata' => 'x_duplicate_window=' . static::DUPLICATE_WINDOW],
        ];

        if ($this->hasParameter('amount')) {
            $params['transaction'][$type]['amount'] = static::formatAmount($this->getParameter('amount'));
        }

        // Add customer IP?
        if ($this->hasParameter('customerIp')) {
            $params['extraOptions']['@cdata'] .= '&x_customer_ip=' . $this->getParameter('customerIp');
        }

        // Add tax amount?
        if ($this->hasParameter('taxAmount')) {
            $params['transaction'][$type]['tax'] = [
                'amount'      => static::formatAmount($this->getParameter('taxAmount')),
                'name'        => $this->getParameter('taxName'),
                'description' => $this->getParameter('taxDescription'),
            ];
        }

        // Add shipping amount?
        if ($this->hasParameter('shipAmount')) {
            $params['transaction'][$type]['shipping'] = [
                'amount'      => static::formatAmount($this->getParameter('shipAmount')),
                'name'        => $this->getParameter('shipName'),
                'description' => $this->getParameter('shipDescription'),
            ];
        }

        // Add duty amount?
        if ($this->hasParameter('dutyAmount')) {
            $params['transaction'][$type]['duty'] = [
                'amount'      => static::formatAmount($this->getParameter('dutyAmount')),
                'name'        => $this->getParameter('dutyName'),
                'description' => $this->getParameter('dutyDescription'),
            ];
        }

        // Add line items?
        $params = $this->createCustomerProfileTransactionAddItemInfo($params, $type);

        $params['transaction'][$type]['customerProfileId'] = $this->getParameter('customerProfileId');
        $params['transaction'][$type]['customerPaymentProfileId'] = $this->getParameter('customerPaymentProfileId');

        // Various other optional or conditional fields
        $params = $this->createCustomerProfileTransactionAddConditionalInfo($params, $type);

        return $this->runTransaction('createCustomerProfileTransactionRequest', $params);
    }

    /**
     * Run an actual transaction with Authorize.Net with stored data.
     *
     * Implements the new generic API method (createTransactionRequest), as opposed
     * to the CIM-specific implementation in createCustomerProfileTransaction().
     *
     * @return array Raw transaction result (XML)
     */
    public function createTransaction()
    {
        $type = $this->getParameter('transactionType');

        $isNewTxn  = $this->getIsNewTransaction($type);
        $isNewCard = $this->getIsNewCard();
        $isRefund  = $this->getIsRefundTransaction($type);

        /**
         * Initialize our params array.
         *
         * NOTE: All elements in the XML array are order-sensitive!
         */
        $params = [];

        /**
         * Define the transaction and basics: Amount, txn ID, auth code
         */
        $params = $this->createTransactionAddTransactionInfo($params, $type, $isNewTxn);

        // Most of the data does not matter for follow-ups (capture, void, refund).
        if ($isNewTxn === true || $isRefund === true) {
            /**
             * Add payment info.
             */
            $params = $this->createTransactionAddPaymentInfo($params, $type, $isNewCard);

            /**
             * Add order info.
             */
            $params = $this->createTransactionAddOrderInfo($params, $type, $isRefund);

            /**
             * Add line items.
             */
            $params = $this->createTransactionAddItemInfo($params);

            /**
             * Add amount info.
             */
            $params = $this->createTransactionAddAmounts($params);

            // Add PO number?
            if ($this->hasParameter('purchaseOrderNumber')) {
                $params['poNumber'] = $this->getParameter('purchaseOrderNumber');
            }

            /**
             * Add customer info.
             */
            $params = $this->createTransactionAddCustomerInfo($params, $isNewCard);

            // Add 3D Secure token?
            if ($this->hasParameter('centinelAuthIndicator') && $this->hasParameter('centinelAuthValue')) {
                $params['cardholderAuthentication'] = [
                    'authenticationIndicator'       => $this->getParameter('centinelAuthIndicator'),
                    'cardholderAuthenticationValue' => urlencode((string)$this->getParameter('centinelAuthValue')),
                ];
            }

            // Add misc settings.
            $params['transactionSettings'] = [
                'setting' => [],
            ];

            $params['transactionSettings']['setting'][] = [
                'settingName'  => 'allowPartialAuth',
                'settingValue' => $this->getParameter('allowPartialAuth', 'false'),
            ];

            $params['transactionSettings']['setting'][] = [
                'settingName'  => 'duplicateWindow',
                'settingValue' => $this->getParameter('duplicateWindow', static::DUPLICATE_WINDOW),
            ];

            $params['transactionSettings']['setting'][] = [
                'settingName'  => 'emailCustomer',
                'settingValue' => $this->getParameter('emailCustomer', 'false'),
            ];

            $params = $this->createTransactionAddCOFIndicators($params);

            // Add user fields.
            if ($this->hasParameter('userFields')) {
                $params['userFields'] = [
                    'userField' => [],
                ];

                foreach ($this->getParameter('userFields') as $key => $value) {
                    $params['userFields']['userField'][] = [
                        'name'  => $key,
                        'value' => $value,
                    ];
                }
            }
        }

        if (empty($params['payment'])) {
            unset($params['payment']);
        }

        if (empty($params['profile'])) {
            unset($params['profile']);
        }

        return $this->runTransaction('createTransactionRequest', ['transactionRequest' => $params]);
    }

    /**
     * Delete a CIM customer profile
     *
     * @return array Raw transaction result (XML)
     */
    public function deleteCustomerProfile()
    {
        $params = [
            'customerProfileId' => $this->getParameter('customerProfileId'),
        ];

        return $this->runTransaction('deleteCustomerProfileRequest', $params);
    }

    /**
     * Delete a CIM payment profile
     *
     * @return array Raw transaction result (XML)
     */
    public function deleteCustomerPaymentProfile()
    {
        $params = [
            'customerProfileId'        => $this->getParameter('customerProfileId'),
            'customerPaymentProfileId' => $this->getParameter('customerPaymentProfileId'),
        ];

        return $this->runTransaction('deleteCustomerPaymentProfileRequest', $params);
    }

    /**
     * Delete a CIM shipping address
     *
     * @return array Raw transaction result (XML)
     */
    public function deleteCustomerShippingAddress()
    {
        $params = [
            'customerProfileId'         => $this->getParameter('customerProfileId'),
            'customerShippingAddressId' => $this->getParameter('customerShippingAddressId'),
        ];

        return $this->runTransaction('deleteCustomerShippingAddressRequest', $params);
    }

    /**
     * Get all CIM customer profile IDs.
     *
     * @return array Raw transaction result (XML)
     */
    public function getCustomerProfileIds()
    {
        return $this->runTransaction('getCustomerProfileIdsRequest', []);
    }

    /**
     * Get all data for a CIM customer profile.
     *
     * @return array Raw transaction result (XML)
     */
    public function getCustomerProfile()
    {
        $params = [
            'customerProfileId'    => $this->getParameter('customerProfileId'),
            'unmaskExpirationDate' => $this->getParameter('unmaskExpirationDate', 'false'),
            'includeIssuerInfo'    => $this->getParameter('includeIssuerInfo', 'false'),
        ];

        return $this->runTransaction('getCustomerProfileRequest', $params);
    }

    /**
     * Get all data for a CIM payment profile.
     *
     * @return array Raw transaction result (XML)
     */
    public function getCustomerPaymentProfile()
    {
        $params = [
            'customerProfileId'        => $this->getParameter('customerProfileId'),
            'customerPaymentProfileId' => $this->getParameter('customerPaymentProfileId'),
            'unmaskExpirationDate'     => $this->getParameter('unmaskExpirationDate', 'false'),
            'includeIssuerInfo'        => $this->getParameter('includeIssuerInfo', 'false'),
        ];

        return $this->runTransaction('getCustomerPaymentProfileRequest', $params);
    }

    /**
     * Get all data for a CIM shipping address.
     *
     * @return array Raw transaction result (XML)
     */
    public function getCustomerShippingAddress()
    {
        $params = [
            'customerProfileId'         => $this->getParameter('customerProfileId'),
            'customerShippingAddressId' => $this->getParameter('customerShippingAddressId'),
        ];

        return $this->runTransaction('getCustomerShippingAddressRequest', $params);
    }

    /**
     * Get current details for a given transaction ID.
     *
     * @return array
     */
    public function getTransactionDetails()
    {
        $params = [
            'transId' => $this->getParameter('transId'),
        ];

        $details = $this->runTransaction('getTransactionDetailsRequest', $params);

        return $this->mapTransactionDetails($details);
    }

    /**
     * Update a CIM customer profile.
     *
     * @return array Raw transaction result (XML)
     */
    public function updateCustomerProfile()
    {
        $params = [
            'profile' => [
                'merchantCustomerId' => $this->getParameter('merchantCustomerId'),
                'description'        => $this->getParameter('description'),
                'email'              => $this->getParameter('email'),
                'customerProfileId'  => $this->getParameter('customerProfileId'),
            ],
        ];

        return $this->runTransaction('updateCustomerProfileRequest', $params);
    }

    /**
     * Update a CIM payment profile.
     *
     * @return array Raw transaction result (XML)
     */
    public function updateCustomerPaymentProfile()
    {
        $params = [
            'customerProfileId' => $this->getParameter('customerProfileId'),
            'paymentProfile'    => [
                'billTo'                   => [
                    'firstName'   => $this->getParameter('billToFirstName'),
                    'lastName'    => $this->getParameter('billToLastName'),
                    'company'     => $this->getParameter('billToCompany'),
                    'address'     => $this->getParameter('billToAddress'),
                    'city'        => $this->getParameter('billToCity'),
                    'state'       => $this->getParameter('billToState'),
                    'zip'         => $this->getParameter('billToZip'),
                    'country'     => $this->getParameter('billToCountry'),
                    'phoneNumber' => $this->getParameter('billToPhoneNumber'),
                    'faxNumber'   => $this->getParameter('billToFaxNumber'),
                ],
                'payment'                  => [],
                'customerPaymentProfileId' => $this->getParameter('customerPaymentProfileId'),
            ],
        ];

        $params = $this->createCustomerPaymentProfileAddPaymentInfo($params);

        if (empty($params['paymentProfile']['payment'])) {
            unset($params['paymentProfile']['payment']);
        }

        return $this->runTransaction('updateCustomerPaymentProfileRequest', $params);
    }

    /**
     * Update a CIM shipping address.
     *
     * @return array Raw transaction result (XML)
     */
    public function updateCustomerShippingAddress()
    {
        $params = [
            'customerProfileId' => $this->getParameter('customerProfileId'),
            'address'           => [
                'firstName'                 => $this->getParameter('shipToFirstName'),
                'lastName'                  => $this->getParameter('shipToLastName'),
                'company'                   => $this->getParameter('shipToCompany'),
                'address'                   => $this->getParameter('shipToAddress'),
                'city'                      => $this->getParameter('shipToCity'),
                'state'                     => $this->getParameter('shipToState'),
                'zip'                       => $this->getParameter('shipToZip'),
                'country'                   => $this->getParameter('shipToCountry'),
                'phoneNumber'               => $this->getParameter('shipToPhoneNumber'),
                'faxNumber'                 => $this->getParameter('shipToFaxNumber'),
                'customerShippingAddressId' => $this->getParameter('customerShippingAddressId'),
            ],
        ];

        return $this->runTransaction('updateCustomerShippingAddressRequest', $params);
    }

    /**
     * Run a validation transaction against the stored CIM profile info.
     *
     * @return array Raw transaction result (XML)
     */
    public function validateCustomerPaymentProfile()
    {
        $params = [
            'customerProfileId'         => $this->getParameter('customerProfileId'),
            'customerPaymentProfileId'  => $this->getParameter('customerPaymentProfileId'),
        ];

        if ($this->hasParameter('customerShippingAddressId')) {
            $params['customerShippingAddressId'] = $this->getParameter('customerShippingAddressId');
        }

        $params['validationMode'] = $this->getParameter('validationMode');

        return $this->runTransaction('validateCustomerPaymentProfileRequest', $params);
    }

    /**
     * Get Account Updater summary report
     *
     * @return array|string
     */
    public function getAccountUpdaterSummary()
    {
        $params = [
            'month' => date('Y-m', strtotime('-1 month')),
        ];

        return $this->runTransaction('getAUJobSummaryRequest', $params);
    }

    /**
     * Get Account Updater change details report
     *
     * @param int $page
     * @param int $size
     * @return array|string
     */
    public function getAccountUpdaterDetails($page = 1, $size = 1000)
    {
        $params = [
            'month' => date('Y-m', strtotime('-1 month')),
            'paging' => [
                'limit' => $size,
                'offset' => $page,
            ]
        ];

        return $this->runTransaction('getAUJobDetailsRequest', $params);
    }

    /**
     * Turn the direct response string into an array, as best we can.
     *
     * @param string $directResponse
     * @return array
     * @throws CommandException
     */
    protected function getDataFromDirectResponse($directResponse)
    {
        $directResponse = (string)$directResponse;
        if (strlen($directResponse) > 1) {
            // Strip out quotes, we don't want any.
            $directResponse = str_replace('"', '', $directResponse);

            // Use the second character as the delimiter. The first will always be the one-digit response code.
            $directResponse = explode(substr($directResponse, 1, 1), $directResponse);
        }

        if (empty($directResponse)) {
            $this->helper->log(
                $this->code,
                sprintf("Authorize.Net CIM Gateway: Transaction failed; no direct response.\n%s", $this->log)
            );

            throw new CommandException(
                __('Authorize.Net CIM Gateway: Transaction failed; no direct response. '
                    . 'Please re-enter your payment info and try again.')
            );
        }

        /**
         * Turn the array into a keyed object and infer some things.
         */
        $data = [
            'response_code'           => (int)$directResponse[0],
            'response_subcode'        => (int)$directResponse[1],
            'response_reason_code'    => (int)$directResponse[2],
            'response_reason_text'    => $directResponse[3],
            'approval_code'           => $directResponse[4],
            'auth_code'               => $directResponse[4],
            'avs_result_code'         => $directResponse[5],
            'transaction_id'          => $directResponse[6],
            'invoice_number'          => $directResponse[7],
            'description'             => $directResponse[8],
            'amount'                  => $directResponse[9],
            'method'                  => $directResponse[10],
            'transaction_type'        => $directResponse[11],
            'customer_id'             => $directResponse[12],
            'card_code_response_code' => $directResponse[38],
            'cavv_response_code'      => $directResponse[39],
            'acc_number'              => $directResponse[50],
            'card_type'               => $directResponse[51],
            'split_tender_id'         => $directResponse[52],
            'requested_amount'        => $directResponse[53],
            'balance_on_card'         => $directResponse[54],
            'profile_id'              => $this->getParameter('customerProfileId'),
            'payment_id'              => $this->getParameter('customerPaymentProfileId'),
            'is_fraud'                => false,
            'is_error'                => false,
        ];

        return $data;
    }

    /**
     * Turn the transaction response into an array, as best we can.
     *
     * @param array $response
     * @return array
     * @throws CommandException
     */
    protected function getDataFromTransactionResponse($response)
    {
        if (empty($response)) {
            $this->helper->log(
                $this->code,
                sprintf("Authorize.Net CIM Gateway: Transaction failed; no response.\n%s", $this->log)
            );

            throw new CommandException(
                __('Authorize.Net CIM Gateway: Transaction failed; no response. '
                    . 'Please re-enter your payment info and try again.')
            );
        }

        /**
         * Turn the array into a keyed object and infer some things.
         * We try to keep the values consistent with the directResponse data. Some translation required.
         */
        $data = [
            'response_code'            => (int)$this->helper->getArrayValue($response, 'responseCode'),
            'response_subcode'         => '',
            'response_reason_code'     => (int)$this->helper->getArrayValue($response, 'errors/error/errorCode')
                ?: (int)$this->helper->getArrayValue($response, 'messages/message/code'),
            'response_reason_text'     => $this->helper->getArrayValue($response, 'errors/error/errorText')
                ?: $this->helper->getArrayValue($response, 'messages/message/description'),
            'approval_code'            => $this->helper->getArrayValue($response, 'authCode'),
            'auth_code'                => $this->helper->getArrayValue($response, 'authCode'),
            'avs_result_code'          => $this->helper->getArrayValue($response, 'avsResultCode'),
            'transaction_id'           => $this->helper->getArrayValue($response, 'transId'),
            'reference_transaction_id' => $this->helper->getArrayValue($response, 'refTransId'),
            'invoice_number'           => $this->getParameter('invoiceNumber'),
            'description'              => $this->getParameter('description'),
            'amount'                   => $this->getParameter('amount'),
            'method'                   => $this->helper->getArrayValue($response, 'accountType') === 'eCheck'
                ? 'ECHECK'
                : 'CC',
            'transaction_type'         => $this->txnTypeMap[ $this->getParameter('transactionType') ],
            'customer_id'              => $this->getParameter('merchantCustomerId'),
            'card_code_response_code'  => $this->helper->getArrayValue($response, 'cvvResultCode'),
            'cavv_response_code'       => $this->helper->getArrayValue($response, 'cavvResultCode'),
            'acc_number'               => $this->helper->getArrayValue($response, 'accountNumber'),
            'card_type'                => $this->helper->getArrayValue($response, 'accountType'),
            'split_tender_id'          => '',
            'requested_amount'         => '',
            'balance_on_card'          => '',
            'profile_id'               => $this->getParameter('customerProfileId'),
            'payment_id'               => $this->getParameter('customerPaymentProfileId'),
            'is_fraud'                 => false,
            'is_error'                 => false,
        ];

        /**
         * Pull CIM profile data out of the response, if any.
         */
        if (isset($response['profileResponse'])) {
            $data['profile_results'] = $response['profileResponse']['messages'];

            if ($this->helper->getArrayValue($response, 'profileResponse/customerProfileId', false) !== false) {
                $data['profile_id'] = $this->helper->getArrayValue(
                    $response,
                    'profileResponse/customerProfileId'
                );
            }

            if ($this->helper->getArrayValue(
                $response,
                'profileResponse/customerPaymentProfileIdList/numericString',
                false
            ) !== false) {
                $data['payment_id'] = $this->helper->getArrayValue(
                    $response,
                    'profileResponse/customerPaymentProfileIdList/numericString'
                );
            }

            if ($this->helper->getArrayValue(
                $response,
                'profileResponse/customerShippingAddressIdList/numericString',
                false
            ) !== false) {
                $data['shipping_id'] = $this->helper->getArrayValue(
                    $response,
                    'profileResponse/customerShippingAddressIdList/numericString'
                );
            }

            /**
             * Handle error cases
             *
             * ...
             *
             * Not a priority right now, since the API's error handling is not robust enough
             * for us to actually use this behavior reliably.
             */
        }

        return $data;
    }

    /**
     * Force a consistent data interface for the transaction data store.
     * The data returned by API call getTransactionDetails does not match _getDataFromTransactionResponse.
     *
     * @param array $response Results of API call getTransactionDetails
     * @return array Data keyed to match normal transaction responses.
     * @throws CommandException
     */
    protected function mapTransactionDetails($response)
    {
        if (empty($response)) {
            $this->helper->log(
                $this->code,
                sprintf("Authorize.Net CIM Gateway: Transaction failed; no response.\n%s", $this->log)
            );

            throw new CommandException(
                __('Authorize.Net CIM Gateway: Transaction failed; no response. '
                    . 'Please re-enter your payment info and try again.')
            );
        }

        $txn     = $response['transaction'];
        $eCheck  = $this->helper->getArrayValue($txn, 'payment/bankAccount', false) !== false;

        // Map data.
        $data    = [
            'response_code'            => (int)$this->helper->getArrayValue($txn, 'responseCode'),
            'response_reason_code'     => (int)$this->helper->getArrayValue($txn, 'responseReasonCode'),
            'response_reason_text'     => $this->helper->getArrayValue($txn, 'responseReasonDescription'),
            'transaction_status'       => $this->helper->getArrayValue($txn, 'transactionStatus'),
            'approval_code'            => $this->helper->getArrayValue($txn, 'authCode'),
            'auth_code'                => $this->helper->getArrayValue($txn, 'authCode'),
            'avs_result_code'          => $this->helper->getArrayValue($txn, 'AVSResponse'),
            'transaction_id'           => $this->helper->getArrayValue($txn, 'transId'),
            'reference_transaction_id' => $this->helper->getArrayValue($txn, 'refTransId'),
            'invoice_number'           => $this->helper->getArrayValue($txn, 'order/invoiceNumber'),
            'description'              => $this->helper->getArrayValue($txn, 'order/description'),
            'amount'                   => $this->helper->getArrayValue($txn, 'authAmount'),
            'method'                   => $eCheck ? 'ECHECK' : 'CC',
            'transaction_type'         => $this->helper->getArrayValue(
                $this->txnTypeMap,
                $this->helper->getArrayValue($txn, 'transactionType')
            ),
            'customer_id'              => $this->helper->getArrayValue($txn, 'customer/id'),
            'card_code_response_code'  => $this->helper->getArrayValue($txn, 'cardCodeResponse'),
            'cavv_response_code'       => $this->helper->getArrayValue($txn, 'CAVVResponse'),
            'acc_number'               => $this->helper->getArrayValue(
                $txn,
                $eCheck ? 'payment/bankAccount/accountNumber' : 'payment/creditCard/cardNumber'
            ),
            'card_type'                => $this->helper->getArrayValue(
                $txn,
                $eCheck ? 'payment/bankAccount/echeckType' : 'payment/creditCard/accountType'
            ),
            'submit_time_utc'          => $this->helper->getArrayValue($txn, 'submitTimeUTC'),
            'amount_settled'           => $this->helper->getArrayValue($txn, 'settleAmount'),
            'amount_tax'               => $this->helper->getArrayValue($txn, 'tax/amount'),
            'amount_shipping'          => $this->helper->getArrayValue($txn, 'shipping/amount'),
            'amount_duty'              => $this->helper->getArrayValue($txn, 'duty/amount'),
            'line_items'               => $this->helper->getArrayValue(
                $txn,
                'lineItems/lineItem/itemId',
                false
            ) !== false
                ? [$this->helper->getArrayValue($txn, 'lineItems/lineItem')]
                : $this->helper->getArrayValue($txn, 'lineItems/lineItem'),
            'tax_exempt'               => $this->helper->getArrayValue($txn, 'taxExempt'),
            'expiration_date'          => $this->helper->getArrayValue($txn, 'payment/creditCard/expirationDate'),
            'customer_email'           => $this->helper->getArrayValue($txn, 'customer/email'),
            'customer_ip'              => $this->helper->getArrayValue($txn, 'customerIP'),
            'batch_id'                 => $this->helper->getArrayValue($txn, 'batch/batchId'),
            'settlement_time_utc'      => $this->helper->getArrayValue($txn, 'batch/settlementTimeUTC'),
            'settlement_state'         => $this->helper->getArrayValue($txn, 'batch/settlementState'),
            'fraud_filter_action'      => $this->helper->getArrayValue($txn, 'FDSFilterAction'),
            'fraud_filter'             => $this->helper->getArrayValue($txn, 'FDSFilters'),
        ];

        // Clean out empties.
        foreach ($data as $key => $value) {
            if ($value === '') {
                unset($data[ $key ]);
            }
        }

        return $data;
    }

    /**
     * Return whether the given transaction type constitutes a 'new' transaction.
     *
     * @param string $type
     * @return bool
     */
    protected function getIsNewTransaction($type)
    {
        if (in_array(
            $type,
            ['authOnlyTransaction', 'authCaptureTransaction', 'captureOnlyTransaction']
        )) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the current parameters are for a new or stored card.
     *
     * @return bool
     */
    protected function getIsNewCard()
    {
        if ($this->hasParameter('customerProfileId') && $this->hasParameter('customerPaymentProfileId')) {
            return false;
        }

        return true;
    }

    /**
     * Return whether the given transaction type is a refund.
     *
     * @param string $type
     * @return bool
     */
    protected function getIsRefundTransaction($type)
    {
        if ($type === 'refundTransaction') {
            return true;
        }

        return false;
    }

    /**
     * Add payment info to a createTransaction API request's parameters.
     *
     * Split out to reduce that method's cyclomatic complexity.
     *
     * @param array $params
     * @param string $type
     * @param bool $isNewTxn
     * @return array
     */
    protected function createTransactionAddTransactionInfo($params, $type, $isNewTxn)
    {
        $params['transactionType'] = $type;

        if ($this->hasParameter('amount')) {
            $params['amount'] = static::formatAmount($this->getParameter('amount'));
        }

        // payment must be above profile. Placeholder to enforce that.
        $params['payment'] = [];

        // profile must be above refTransId. Placeholder to enforce that.
        $params['profile'] = [];

        if ($isNewTxn === false && $this->hasParameter('transId')) {
            $params['refTransId'] = $this->getParameter('transId');
        }

        if ($isNewTxn === false && $this->hasParameter('splitTenderId')) {
            $params['splitTenderId'] = $this->getParameter('splitTenderId');
        }

        if ($type === 'captureOnlyTransaction'
            && $this->hasParameter('approvalCode')
            && strlen((string)$this->getParameter('approvalCode')) === 6
        ) {
            $params['authCode'] = $this->getParameter('approvalCode');
        }

        return $params;
    }

    /**
     * Add payment info to a createTransaction API request's parameters.
     *
     * Split out to reduce that method's cyclomatic complexity.
     *
     * @param array $params
     * @param string $type
     * @param bool $isNewCard
     * @return array
     */
    protected function createTransactionAddPaymentInfo($params, $type, $isNewCard)
    {
        if ($isNewCard === true) {
            /**
             * If we're storing a new card, send the payment data along and request a profile.
             */
            if ($this->hasParameter('cardNumber')) {
                $params['payment'] = [
                    'creditCard' => [
                        'cardNumber'     => $this->getParameter('cardNumber'),
                        'expirationDate' => $this->getParameter('expirationDate'),
                    ],
                ];

                if ($this->hasParameter('cardCode')) {
                    $params['payment']['creditCard']['cardCode'] = $this->getParameter('cardCode');
                }
            } elseif ($this->hasParameter('dataValue')) {
                $params['paymentProfile']['payment'] = [
                    'opaqueData' => [
                        'dataDescriptor' => $this->getParameter('dataDescriptor'),
                        'dataValue'      => $this->getParameter('dataValue'),
                    ],
                ];
            } elseif ($this->hasParameter('accountNumber')) {
                $params['payment'] = [
                    'bankAccount' => [
                        'accountType'   => $this->getParameter('accountType'),
                        'routingNumber' => $this->getParameter('routingNumber'),
                        'accountNumber' => $this->getParameter('accountNumber'),
                        'nameOnAccount' => $this->getParameter('nameOnAccount'),
                        'echeckType'    => $this->getParameter('echeckType'),
                        'bankName'      => $this->getParameter('bankName'),
                    ],
                ];
            }

            $params['profile']['createProfile'] = 'true';
        } elseif ($type !== 'captureOnlyTransaction') {
            /**
             * Otherwise, send the tokens we already have.
             */
            $params['profile']['customerProfileId'] = $this->getParameter('customerProfileId');
            $params['profile']['paymentProfile'] = [
                'paymentProfileId' => $this->getParameter('customerPaymentProfileId'),
            ];

            // Include CCV if available.
            if ($type !== 'priorAuthCaptureTransaction' && $this->hasParameter('cardCode')) {
                $params['profile']['paymentProfile']['cardCode'] = $this->getParameter('cardCode');
            }

            // Include shipping profile if available.
            if ($this->hasParameter('customerShippingAddressId')) {
                $params['profile']['shippingProfileId'] = $this->hasParameter('customerShippingAddressId');
            }
        }

        return $params;
    }

    /**
     * Add order info to a createTransaction API request's parameters.
     *
     * Split out to reduce that method's cyclomatic complexity.
     *
     * @param $params
     * @param $type
     * @param $isRefund
     * @return mixed
     */
    protected function createTransactionAddOrderInfo($params, $type, $isRefund)
    {
        if ($isRefund !== true) {
            $params['solution'] = [
                'id' => self::SOLUTION_ID,
            ];
        }

        if ($type !== 'priorAuthCaptureTransaction' && $this->hasParameter('invoiceNumber')) {
            if ($this->hasParameter('description') === false) {
                $store = $this->helper->getCurrentStore();
                $this->setParameter('description', __('%1 (%2)', $store->getName(), $store->getBaseUrl()));
            }

            $params['order'] = [
                'invoiceNumber' => $this->getParameter('invoiceNumber'),
                'description'   => $this->getParameter('description'),
            ];
        }

        return $params;
    }

    /**
     * Add item info to a createTransaction API request's parameters.
     *
     * Split out to reduce that method's cyclomatic complexity.
     *
     * @param array $params
     * @return array
     */
    protected function createTransactionAddItemInfo($params)
    {
        if ($this->lineItems !== null && !empty($this->lineItems)) {
            $params['lineItems'] = [
                'lineItem' => [],
            ];

            $count = 0;
            /** @var \Magento\Sales\Model\Order\Item $item */
            foreach ($this->lineItems as $item) {
                if (($item instanceof \Magento\Framework\DataObject) === false) {
                    continue;
                }

                $itemArray = $this->createTransactionAddItemInfoBuildItem($item);

                if ($itemArray !== false) {
                    $params['lineItems']['lineItem'][] = $itemArray;

                    if (++$count >= 30) {
                        break;
                    }
                }
            }

            if (empty($params['lineItems']['lineItem'])) {
                unset($params['lineItems']);
            }
        }

        return $params;
    }

    /**
     * Build param array for a single order/invoice/refund item.
     *
     * @param \Magento\Framework\DataObject $item
     * @return array|false
     */
    protected function createTransactionAddItemInfoBuildItem(\Magento\Framework\DataObject $item)
    {
        /** @var \Magento\Sales\Model\Order\Item $item */
        if ($item->getData('qty') > 0) {
            $qty = $item->getData('qty');
        } else {
            $qty = $item->getData('qty_ordered');
        }

        // We're sending SKU and name through parameters to filter characters and length.
        $sku = $this->setParameter('itemName', $item->getSku())->getParameter('itemName');
        $name = $this->setParameter('itemName', $item->getName())->getParameter('itemName');

        if ($qty < 1 || $item->getPrice() <= 0 || empty($sku)) {
            return false;
        }

        // Discount amount is per-line, not per-unit (???). Math it out.
        $unitPrice = max(0, $item->getPrice() - ($item->getDiscountAmount() / $qty));

        $itemData = [
            'itemId'    => $sku,
            'name'      => !empty($name) ? $name : $sku,
            'quantity'  => static::formatAmount($qty),
            'unitPrice' => static::formatAmount($unitPrice),
        ];
        
        return $itemData;
    }

    /**
     * Add amount info to a createTransaction API request's parameters.
     *
     * Split out to reduce that method's cyclomatic complexity.
     *
     * @param array $params
     * @return array
     */
    protected function createTransactionAddAmounts($params)
    {
        // Add tax amount?
        if ($this->hasParameter('taxAmount')) {
            $params['tax'] = [
                'amount'      => static::formatAmount($this->getParameter('taxAmount')),
                'name'        => $this->getParameter('taxName'),
                'description' => $this->getParameter('taxDescription'),
            ];
        }

        // Add duty amount?
        if ($this->hasParameter('dutyAmount')) {
            $params['duty'] = [
                'amount'      => static::formatAmount($this->getParameter('dutyAmount')),
                'name'        => $this->getParameter('dutyName'),
                'description' => $this->getParameter('dutyDescription'),
            ];
        }

        // Add shipping amount?
        if ($this->hasParameter('shipAmount')) {
            $params['shipping'] = [
                'amount'      => static::formatAmount($this->getParameter('shipAmount')),
                'name'        => $this->getParameter('shipName'),
                'description' => $this->getParameter('shipDescription'),
            ];
        }

        // Add tax exempt?
        if ($this->hasParameter('taxExempt')) {
            $params['taxExempt'] = $this->getParameter('taxExempt');
        }

        return $params;
    }

    /**
     * Add item info to a createTransaction API request's parameters.
     *
     * Split out to reduce that method's cyclomatic complexity.
     *
     * @param array $params
     * @param bool $isNewCard
     * @return array
     */
    protected function createTransactionAddCustomerInfo($params, $isNewCard)
    {
        $params['customer'] = [
            'id'    => $this->getParameter('merchantCustomerId'),
            'email' => $this->getParameter('email'),
        ];

        if ($this->hasParameter('customerType')) {
            $params['customer'] = [
                    'type' => $this->getParameter('customerType'),
                ] + $params['customer'];
        }

        // Add billing address?
        if ($isNewCard === true) {
            $params['billTo'] = [
                'firstName'   => $this->getParameter('billToFirstName'),
                'lastName'    => $this->getParameter('billToLastName'),
                'company'     => $this->getParameter('billToCompany'),
                'address'     => $this->getParameter('billToAddress'),
                'city'        => $this->getParameter('billToCity'),
                'state'       => $this->getParameter('billToState'),
                'zip'         => $this->getParameter('billToZip'),
                'country'     => $this->getParameter('billToCountry'),
                'phoneNumber' => $this->getParameter('billToPhoneNumber'),
                'faxNumber'   => $this->getParameter('billToFaxNumber'),
            ];
        }

        // Add shipping address?
        if (!$this->hasParameter('customerShippingAddressId') && $this->hasParameter('shipToAddress')) {
            $params['shipTo'] = [
                'firstName' => $this->getParameter('shipToFirstName'),
                'lastName'  => $this->getParameter('shipToLastName'),
                'company'   => $this->getParameter('shipToCompany'),
                'address'   => $this->getParameter('shipToAddress'),
                'city'      => $this->getParameter('shipToCity'),
                'state'     => $this->getParameter('shipToState'),
                'zip'       => $this->getParameter('shipToZip'),
                'country'   => $this->getParameter('shipToCountry'),
            ];
        }

        // Add customer IP?
        if ($this->hasParameter('customerIp')) {
            $params['customerIP'] = $this->getParameter('customerIp');
        }

        return $params;
    }

    /**
     * Add various conditional fields to a createCustomerProfileTransaction API request's parameters.
     *
     * Split out to reduce that method's cyclomatic complexity.
     *
     * @param array $params
     * @param string $type
     * @return array
     */
    protected function createCustomerProfileTransactionAddConditionalInfo($params, $type)
    {
        if ($this->hasParameter('customerShippingAddressId')) {
            $params['transaction'][$type]['customerShippingAddressId']
                = $this->getParameter('customerShippingAddressId');
        }

        if ($type !== 'profileTransPriorAuthCapture' && $this->hasParameter('invoiceNumber')) {
            $params['transaction'][$type]['order'] = [
                'invoiceNumber'       => $this->getParameter('invoiceNumber'),
                'description'         => $this->getParameter('description'),
                'purchaseOrderNumber' => $this->getParameter('purchaseOrderNumber'),
            ];
        }

        if ($type !== 'profileTransPriorAuthCapture' && $this->hasParameter('taxExempt')) {
            $params['transaction'][$type]['taxExempt'] = $this->getParameter('taxExempt');
        }

        if ($type !== 'profileTransPriorAuthCapture' && $this->hasParameter('cardCode')) {
            $params['transaction'][$type]['cardCode'] = $this->getParameter('cardCode');
        }

        if ($type !== 'profileTransAuthOnly' && $this->hasParameter('transId')) {
            $params['transaction'][$type]['transId'] = $this->getParameter('transId');
        }

        if ($this->hasParameter('splitTenderId')) {
            $params['transaction'][$type]['splitTenderId'] = $this->getParameter('splitTenderId');
        }

        if ($this->hasParameter('approvalCode')
            && strlen((string)$this->getParameter('approvalCode')) === 6
            && !in_array($type, ['profileTransRefund', 'profileTransPriorAuthCapture', 'profileTransAuthOnly'], true)
        ) {
            $params['transaction'][$type]['approvalCode'] = $this->getParameter('approvalCode');
        }

        return $params;
    }

    /**
     * Add item info to a createTransaction API request's parameters.
     *
     * Split out to reduce that method's cyclomatic complexity.
     *
     * @param array $params
     * @param string $type
     * @return array
     */
    protected function createCustomerProfileTransactionAddItemInfo($params, $type)
    {
        if ($this->lineItems !== null && !empty($this->lineItems)) {
            $params['transaction'][$type]['lineItems'] = [];

            $count = 0;
            /** @var \Magento\Sales\Model\Order\Item $item */
            foreach ($this->lineItems as $item) {
                if (($item instanceof \Magento\Framework\DataObject) === false) {
                    continue;
                }

                $itemArray = $this->createTransactionAddItemInfoBuildItem($item);

                if ($itemArray !== false) {
                    $params['lineItems']['lineItem'][] = $itemArray;

                    if (++$count >= 30) {
                        break;
                    }
                }
            }

            if (empty($params['transaction'][$type]['lineItems'])) {
                unset($params['transaction'][$type]['lineItems']);
            }
        }

        return $params;
    }

    /**
     * On capture, get amount info from the order or invoice if possible.
     *
     * Split out to reduce that method's cyclomatic complexity.
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @return $this
     */
    protected function captureGetAmountInfo(\Magento\Payment\Model\InfoInterface $payment)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */

        // Grab shipping and tax info from the invoice if possible. Should always be true.
        if ($payment->hasData('invoice')
            && $payment->getData('invoice') instanceof \Magento\Sales\Model\Order\Invoice
        ) {
            if ($payment->getData('invoice')->getBaseTaxAmount()) {
                $this->setParameter('taxAmount', $payment->getData('invoice')->getBaseTaxAmount());
            }

            if ($payment->getData('invoice')->getBaseShippingAmount()) {
                $this->setParameter('shipAmount', $payment->getData('invoice')->getBaseShippingAmount());
            }
        } elseif ($payment->getOrder()->getBaseTotalPaid() <= 0) {
            if ($payment->getOrder()->getBaseTaxAmount()) {
                $this->setParameter('taxAmount', $payment->getOrder()->getBaseTaxAmount());
            }

            if ($payment->getBaseShippingAmount()) {
                $this->setParameter('shipAmount', $payment->getBaseShippingAmount());
            }
        }

        return $this;
    }

    /**
     * Return whether the given address in $address2 is a duplicate of $address1, based on several fields.
     *
     * @param array $address1
     * @param array $address2
     * @return bool
     */
    protected function isAddressDuplicate($address1, $address2)
    {
        $isDuplicate = true;
        $fields = ['firstName', 'lastName', 'address', 'zip', 'phoneNumber'];

        foreach ($fields as $field) {
            if ($address1[$field] != $address2[$field]) {
                $isDuplicate = false;
                break;
            }
        }
        return $isDuplicate;
    }

    /**
     * After running a transaction, handle any generic errors in the response.
     *
     * Split out to reduce that method's cyclomatic complexity.
     *
     * @return void
     * @throws CommandException
     */
    protected function handleTransactionError()
    {
        if ($this->lastResponse['messages']['resultCode'] !== 'Ok') {
            $errorCode = $this->helper->getArrayValue($this->lastResponse, 'messages/message/code');
            $errorText = $this->helper->getArrayValue($this->lastResponse, 'messages/message/text');
            $errorText2 = $this->helper->getArrayValue(
                $this->lastResponse,
                'transactionResponse/errors/error/errorText'
            );

            if (!empty($errorText2)) {
                $errorText .= ' ' . $errorText2;
            }

            /**
             * Log and spit out generic error. Skip certain warnings we can handle.
             */
            $okayErrorCodes = ['E00039', 'E00040'];
            $okayErrorTexts = [
                'The referenced transaction does not meet the criteria for issuing a credit.',
                'The transaction cannot be found.',
            ];

            if (!empty($errorCode)
                && !in_array($errorCode, $okayErrorCodes, true)
                && !in_array($errorText, $okayErrorTexts, true)
                && !in_array($errorText2, $okayErrorTexts, true)
            ) {
                $this->helper->log(
                    $this->code,
                    sprintf("API error: %s: %s\n%s", $errorCode, $errorText, $this->log)
                );

                if ($errorText === 'Invalid OTS Token.') {
                    $errorText = 'Invalid token. Please re-enter your payment info.';
                }

                throw new CommandException(
                    __(sprintf('Authorize.Net CIM Gateway: %s (%s)', $errorText, $errorCode))
                );
            }
        }
    }

    /**
     * Add payment fields to a createCustomerPaymentProfile API request's parameters.
     *
     * Split out to reduce that method's cyclomatic complexity.
     *
     * @param array $params
     * @return array
     */
    protected function createCustomerPaymentProfileAddPaymentInfo($params)
    {
        if ($this->hasParameter('cardNumber')) {
            $params['paymentProfile']['payment'] = [
                'creditCard' => [
                    'cardNumber'     => $this->getParameter('cardNumber'),
                    'expirationDate' => $this->getParameter('expirationDate'),
                ],
            ];

            if ($this->hasParameter('cardCode')) {
                $params['paymentProfile']['payment']['creditCard']['cardCode'] = $this->getParameter('cardCode');
            }
        } elseif ($this->hasParameter('dataValue')) {
            $params['paymentProfile']['payment'] = [
                'opaqueData' => [
                    'dataDescriptor' => $this->getParameter('dataDescriptor'),
                    'dataValue'      => $this->getParameter('dataValue'),
                ],
            ];
        } elseif ($this->hasParameter('accountNumber')) {
            $params['paymentProfile']['payment'] = [
                'bankAccount' => [
                    'accountType'   => $this->getParameter('accountType'),
                    'routingNumber' => $this->getParameter('routingNumber'),
                    'accountNumber' => $this->getParameter('accountNumber'),
                    'nameOnAccount' => $this->getParameter('nameOnAccount'),
                    'echeckType'    => $this->getParameter('echeckType'),
                    'bankName'      => $this->getParameter('bankName'),
                ],
            ];
        }

        return $params;
    }

    /**
     * Add card-on-file flags to a createCustomerPaymentProfile API request's parameters.
     *
     * Split out to reduce that method's cyclomatic complexity.
     *
     * @param array $params
     * @return array
     */
    protected function createTransactionAddCOFIndicators(array $params)
    {
        /**
         * Card-On-File indicators convey the transaction context to the card processor. Mandated for stored card
         * transactions by certain processors.
         * @see https://developer.authorize.net/api/reference/features/card-on-file.html
         */

        if ($this->hasParameter('isStoredCredentials') && $this->hasParameter('isSubsequentAuth')) {
            // Never allow CIT flag and MIT flag to be set at the same time; these are mutually exclusive.
            $this->setParameter('isSubsequentAuth', null);
        }

        $processingOptions    = [];
        $processingOptionKeys = [
            'isFirstRecurringPayment',
            'isFirstSubsequentAuth',
            'isStoredCredentials',
            'isSubsequentAuth',
        ];
        foreach ($processingOptionKeys as $key) {
            if ($this->hasParameter($key)) {
                $processingOptions[$key] = $this->getParameter($key);
            }
        }
        if (!empty($processingOptions)) {
            $params['processingOptions'] = $processingOptions;
        }

        if ($this->hasParameter('subsequentAuthReason')
            && $this->getParameter('isSubsequentAuth') === 'true') {
            $params['subsequentAuthInformation']['reason'] = $this->getParameter('subsequentAuthReason');
        }

        if ($this->hasParameter('recurringBilling')) {
            $params['transactionSettings']['setting'][] = [
                'settingName'  => 'recurringBilling',
                'settingValue' => $this->getParameter('recurringBilling', 'false'),
            ];
        }

        return $params;
    }
}
