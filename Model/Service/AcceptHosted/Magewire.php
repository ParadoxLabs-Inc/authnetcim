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
 *
 * @link https://support.paradoxlabs.com
 */

namespace ParadoxLabs\Authnetcim\Model\Service\AcceptHosted;

use Hyva\Checkout\Model\Magewire\Component\EvaluationInterface;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\LayoutInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\ResourceModel\Quote\Payment;
use Magewirephp\Magewire\Component\Form;
use ParadoxLabs\Authnetcim\Block\Form\Cc;
use ParadoxLabs\Authnetcim\Model\Service\AcceptHosted\FrontendRequest as AcceptHostedService;
use ParadoxLabs\TokenBase\Api\CardRepositoryInterface;
use ParadoxLabs\TokenBase\Helper\Data;
use Rakit\Validation\Validator;

class Magewire extends Form implements EvaluationInterface
{
    protected const METHOD_CODE = 'authnetcim';

    /**
     * @var bool
     */
    protected $loader = true;

    /**
     * @var string[]
     */
    protected $listeners = [
        'billing_address_saved' => 'initHostedForm',
        'billing_address_submitted' => 'initHostedForm',
    ];

    /* Public component properties */
    public $selectedCard = '';
    public $paymentCcCid = '';
    public $transactionId = '';
    public $saveCard = false;

    /* Protected property validation rule map */
    protected $rules = [
        'selectedCard' => 'alpha_num',
        'paymentCcCid' => 'numeric|digits_between:3,4',
        'transactionId' => 'alpha_num',
        'saveCard' => 'boolean',
    ];

    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * @var AcceptHostedService
     */
    protected $acceptHostedService;

    /**
     * @var \ParadoxLabs\TokenBase\Api\CardRepositoryInterface
     */
    protected $cardRepository;

    /**
     * @var \ParadoxLabs\TokenBase\Helper\Data
     */
    protected $helper;

    /**
     * @var \Magento\Framework\View\LayoutInterface
     */
    protected $layout;

    /**
     * @var \ParadoxLabs\Authnetcim\Block\Form\Cc
     */
    protected $formBlock;

    /**
     * @var \Magento\Quote\Model\ResourceModel\Quote\Payment
     */
    protected $paymentResource;

    /**
     * @param CheckoutSession $checkoutSession
     * @param AcceptHostedService $acceptHostedService
     * @param \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository
     * @param \ParadoxLabs\TokenBase\Helper\Data $helper
     * @param \Magento\Framework\View\LayoutInterface $layout
     * @param \Magento\Quote\Model\ResourceModel\Quote\Payment $paymentResource
     */
    public function __construct(
        Validator $validator,
        CheckoutSession $checkoutSession,
        AcceptHostedService $acceptHostedService,
        CardRepositoryInterface $cardRepository,
        Data $helper,
        LayoutInterface $layout,
        Payment $paymentResource
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->acceptHostedService = $acceptHostedService;
        $this->cardRepository = $cardRepository;
        $this->helper = $helper;
        $this->layout = $layout;
        $this->paymentResource = $paymentResource;

        parent::__construct($validator);
    }

    /**
     * Initialize component data on update
     *
     * @return void
     */
    public function booted(): void
    {
        $this->loadSelectedCard();
    }

    /**
     * Update component selected card based on the quote's assigned stored card
     *
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function loadSelectedCard(): void
    {
        $payment = $this->getQuote()->getPayment();

        if ($payment->getData('tokenbase_id') !== null) {
            $card = $this->cardRepository->getById($payment->getData('tokenbase_id'));
            $this->selectedCard = $card->getHash();
        }
    }

    /**
     * Generate Accept Hosted form token
     *
     * @see \ParadoxLabs\Authnetcim\Model\Service\AcceptHosted\FrontendRequest
     * @return void
     */
    public function initHostedForm(): void
    {
        $params = $this->acceptHostedService->getParams();

        $this->dispatchBrowserEvent(
            self::METHOD_CODE . 'InitHostedForm',
            $params
        );
    }

    /**
     * Get the current user's active quote
     *
     * @return \Magento\Quote\Api\Data\CartInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function getQuote(): CartInterface
    {
        return $this->checkoutSession->getQuote();
    }

    /**
     * Update the selected card value
     *
     * @param string|null $value
     * @return mixed
     */
    public function updatingSelectedCard($value)
    {
        // TODO: Test with invalid and unauthorized card hash
        $this->validate();
        $this->setPaymentData([
            'card_id' => $value,
            'cc_cid' => $this->paymentCcCid,
        ]);

        return $value;
    }

    /**
     * Update the CC CID value
     *
     * @param string|null $value
     * @return mixed
     */
    public function updatingPaymentCcCid($value)
    {
        $this->validate();
        $this->setPaymentData([
            'card_id' => $this->selectedCard,
            'cc_cid' => $value,
        ]);

        return $value;
    }

    /**
     * Update the transaction ID value
     *
     * @param string|null $value
     * @return mixed
     */
    public function updatingSaveCard($value)
    {
        $this->validate();

        return $value;
    }

    /**
     * Update the transaction ID value
     *
     * @param string|null $value
     * @return mixed
     */
    public function updatingTransactionId($value)
    {
        $this->validate();

        return $value;
    }

    public function submitTransaction(): void
    {
        // TODO: Ensure this happens after deferred values are updated
        $this->setPaymentData([
            'transaction_id' => $this->transactionId,
            'save' => $this->saveCard,
        ]);
    }

    /**
     * Set payment data from the checkout form onto the payment model, validate, and save
     *
     * @param string|array $params
     * @return void
     */
    protected function setPaymentData($params): void
    {
        $params['method'] = self::METHOD_CODE;

        // Assign data to the quote payment object
        $payment = $this->getQuote()->getPayment();
        $payment->importData($params);

        // Save the quote payment
        if ($payment->hasDataChanges()) {
            $this->paymentResource->save($payment);
        }
    }

    /**
     * Get the active payment method instance
     *
     * @return \Magento\Payment\Model\MethodInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getMethod(): MethodInterface
    {
        // TODO: Make getMethod and getFormBlock private and move to a viewmodel
        return $this->helper->getMethodInstance(self::METHOD_CODE);
    }

    /**
     * Get the active payment method form block
     *
     * @return \ParadoxLabs\Authnetcim\Block\Form\Cc
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getFormBlock(): Cc
    {
        if (!isset($this->formBlock)) {
            $formBlock = $this->layout->createBlock(Cc::class);
            $formBlock->setMethod($this->getMethod());

            $this->formBlock = $formBlock;
        }

        return $this->formBlock;
    }

    /**
     * Determine whether checkout completion is allowed
     *
     * @param \Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory $factory
     * @return \Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface
     */
    public function evaluateCompletion(EvaluationResultFactory $factory): EvaluationResultInterface
    {
        // If this payment method is selected, only return a Success if all required data is present
        return $this->isRequiredDataPresent()
            ? $factory->createSuccess()
            : $factory->createBlocking();
    }

    /**
     * Determine whether all required fields are present
     *
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function isRequiredDataPresent(): bool
    {
        // Stored card payment
        if (!empty($this->selectedCard)) {
            // With CVV either present or not required
            if (!empty($this->paymentCcCid) || $this->getMethod()->getConfigData('require_ccv') === false) {
                return true;
            }
        }

        // New card payment
        if (!empty($this->transactionId)) {
            return true;
        }

        return false;
    }
}
