<?php declare(strict_types=1);
/**
 * Copyright © 2015-present ParadoxLabs, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * Need help? Try our knowledgebase and support system:
 * @link https://support.paradoxlabs.com
 */

namespace ParadoxLabs\Authnetcim\Model\Service\AcceptHosted;

use Magewirephp\Magewire\Component;
use Magento\Checkout\Model\Session as CheckoutSession;
use ParadoxLabs\TokenBase\Model\Method\Factory as MethodFactory;
use ParadoxLabs\Authnetcim\Observer\PaymentMethodAssignDataObserver;
use ParadoxLabs\Authnetcim\Model\Service\AcceptHosted\FrontendRequest as AcceptHostedService;

class Magewire extends Component
{
    protected $loader = true;
    protected $listeners = [
        'setPaymentData',
        'resetIframe',
        'customer_billing_address_saved' => 'resetIframe',
        'billing_address_saved' => 'resetIframe'
    ];

    /**
     * @var string
     */
    public $formToken = '';

    /**
     * @var string
     */
    public $formUrl = '';

    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * @var AcceptHostedService
     */
    protected $acceptHostedService;

    /**
     * @var MethodFactory
     */
    protected $methodFactory;

    /**
     * @var PaymentMethodAssignDataObserver
     */
    protected $paymentMethodAssignData;

    /**
     * @param CheckoutSession $checkoutSession
     * @param AcceptHostedService $acceptHostedService
     * @param MethodFactory $methodFactory
     * @param PaymentMethodAssignDataObserver $paymentMethodAssignData
     */
    public function __construct(
        CheckoutSession $checkoutSession,
        AcceptHostedService $acceptHostedService,
        MethodFactory $methodFactory,
        PaymentMethodAssignDataObserver $paymentMethodAssignData
    ){
        $this->checkoutSession = $checkoutSession;
        $this->acceptHostedService = $acceptHostedService;
        $this->methodFactory = $methodFactory;
        $this->paymentMethodAssignData = $paymentMethodAssignData;
    }

    /**
     * @return void
     */
    public function mount(): void
    {
        $this->setFormData();

        // todo: see app/code/Zero1/HyvaAuthorizeNet/view/frontend/templates/checkout/payment/method/authnetcim.phtml
        // have to manually call submitForm at the bottom of the script
        // $this->dispatchBrowserEvent('submitForm');
    }

    /**
     * Generate form URL + token using \ParadoxLabs\Authnetcim\Model\Service\AcceptHosted\FrontendRequest
     *
     * @return void
     */
    public function setFormData(): void
    {
        $params = $this->acceptHostedService->getParams();

        $this->formUrl = $params['iframeAction'];
        $this->formToken = $params['iframeParams']['token'];
    }

    /**
     * Send transaction ID to observer from ParadoxLabs extension, which sets data against quote payment.
     * Dispatch browser event to place order when complete.
     *
     * @param string|array $transId
     * @return void
     */
    public function setPaymentData($transId): void
    {
        if(is_array($transId) && isset($transId['value'])) {
            $transId = $transId['value'];
        }

        $payment = $this->checkoutSession->getQuote()->getPayment();

        $tokenbaseMethod = $this->methodFactory->getMethodInstance($payment->getMethodInstance()->getCode());
        $tokenbaseMethod->setStore((int)$payment->getMethodInstance()->getStore());

        $data = new \Magento\Framework\DataObject;
        $data->setData('transaction_id', $transId);

        $this->paymentMethodAssignData->processAcceptHosted($payment, $data, $tokenbaseMethod);

        // todo: fix deprecated save
        $this->checkoutSession->getQuote()->save();

        $this->dispatchBrowserEvent('paymentDataSet');
    }

    /**
     * Obtain a new form token, dispatch browser event to re-post iFrame form.
     *
     * @return void
     */
    public function resetIframe(): void
    {
        $this->reset();

        $this->setFormData();
        $this->dispatchBrowserEvent('submitForm');
    }
}
