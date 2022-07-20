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

namespace ParadoxLabs\Authnetcim\Controller\Adminhtml\Hosted;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;

class GetNewCard extends Action implements CsrfAwareActionInterface, HttpPostActionInterface
{
    /**
     * @var \Magento\Framework\Data\Form\FormKey\Validator
     */
    protected $formKey;

    /**
     * @var \ParadoxLabs\TokenBase\Model\Method\Factory
     */
    protected $methodFactory;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var \Magento\Backend\Model\Session\Quote
     */
    protected $checkoutSession;

    /**
     * @var \ParadoxLabs\TokenBase\Api\CardRepositoryInterface
     */
    protected $cardRepository;

    /**
     * @var \ParadoxLabs\TokenBase\Api\Data\CardInterfaceFactory
     */
    protected $cardFactory;

    /**
     * @var \ParadoxLabs\Authnetcim\Helper\Data
     */
    protected $helper;

    /**
     * @var \Magento\Quote\Model\ResourceModel\Quote\Payment
     */
    protected $paymentResource;

    /**
     * @var \ParadoxLabs\Authnetcim\Model\Service\Hosted\BackendRequest
     */
    protected $hostedForm;

    /**
     * GetNewCard constructor.
     *
     * @param \Magento\Backend\App\Action\Context $context
     * @param \Magento\Framework\Data\Form\FormKey\Validator $formKey
     * @param \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Backend\Model\Session\Quote $checkoutSession
     * @param \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository
     * @param \ParadoxLabs\TokenBase\Api\Data\CardInterfaceFactory $cardFactory
     * @param \ParadoxLabs\Authnetcim\Helper\Data $helper
     * @param \Magento\Quote\Model\ResourceModel\Quote\Payment $paymentResource
     */
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\Data\Form\FormKey\Validator $formKey,
        \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Backend\Model\Session\Quote $checkoutSession, // TODO: Abstract out
        \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository,
        \ParadoxLabs\TokenBase\Api\Data\CardInterfaceFactory $cardFactory,
        \ParadoxLabs\Authnetcim\Helper\Data $helper,
        \Magento\Quote\Model\ResourceModel\Quote\Payment $paymentResource,
        \ParadoxLabs\Authnetcim\Model\Service\Hosted\BackendRequest $hostedForm
    ) {
        parent::__construct($context);

        $this->formKey = $formKey;
        $this->methodFactory = $methodFactory;
        $this->storeManager = $storeManager;
        $this->checkoutSession = $checkoutSession;
        $this->cardRepository = $cardRepository;
        $this->cardFactory = $cardFactory;
        $this->helper = $helper;
        $this->paymentResource = $paymentResource;
        $this->hostedForm = $hostedForm;
    }

    /**
     * Execute action based on request and return result
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        /** @var \Magento\Framework\Controller\Result\Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        try {
            $card    = $this->getCard();
            $message = [
                'success' => true,
                'card' => [
                    'id' => $card->getHash(),
                    'label' => $card->getLabel(),
                    'selected' => false,
                    'new' => true,
                    'type' => $card->getType(),
                    'cc_bin' => $card->getAdditional('cc_bin'),
                    'cc_last4' => $card->getAdditional('cc_last4'),
                ],
            ];

            $result->setData($message);
        } catch (\Exception $exception) {
            $result->setHttpResponseCode(400);
            $result->setData([
                'message' => $exception->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Create exception in case CSRF validation failed.
     * Return null if default exception will suffice.
     *
     * @param \Magento\Framework\App\RequestInterface $request
     *
     * @return \Magento\Framework\App\Request\InvalidRequestException|null
     */
    public function createCsrfValidationException(
        \Magento\Framework\App\RequestInterface $request
    ): ?\Magento\Framework\App\Request\InvalidRequestException {
        $message = __('Invalid Form Key. Please refresh the page.');

        /** @var \Magento\Framework\Controller\Result\Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $result->setHttpResponseCode(403);
        $result->setData([
            'message' => $message,
        ]);

        return new \Magento\Framework\App\Request\InvalidRequestException(
            $result,
            [$message]
        );
    }

    /**
     * Perform custom request validation.
     * Return null if default validation is needed.
     *
     * @param \Magento\Framework\App\RequestInterface $request
     *
     * @return bool|null
     */
    public function validateForCsrf(\Magento\Framework\App\RequestInterface $request): ?bool
    {
        return $this->formKey->validate($request);
    }

    /**
     * Get hosted profile page request token
     *
     * @return \ParadoxLabs\TokenBase\Api\Data\CardInterface
     */
    public function getCard()
    {
        $methodCode = \ParadoxLabs\Authnetcim\Model\ConfigProvider::CODE;
        if ($this->getRequest()->getParam('method') === \ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider::CODE) {
            $methodCode = \ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider::CODE;
        }

        /** @var \ParadoxLabs\Authnetcim\Model\Method $method */
        $method = $this->methodFactory->getMethodInstance($methodCode);
        $method->setStore($this->storeManager->getStore()->getId());
        /** @var \ParadoxLabs\Authnetcim\Model\Gateway $gateway */
        $gateway = $method->gateway();

        // Get CIM profile ID
        $profileId = $this->hostedForm->getCustomerProfileId($gateway);

        // Get CIM profile cards
        $gateway->setParameter('customerProfileId', $profileId);
        $gateway->setParameter('unmaskExpirationDate', 'true');
        $gateway->setParameter('includeIssuerInfo', 'true');

        $response = $gateway->getCustomerProfile();

        if (!empty($response['messages']['message']['text'])
            && $response['messages']['message']['text'] !== 'Successful.') {
            throw new \Magento\Framework\Exception\LocalizedException(__($response['messages']['message']['text']));
        }

        if (isset($response['profile']['paymentProfiles']['customerPaymentProfileId'])) {
            $newestCard = $response['profile']['paymentProfiles'];
        } else {
            $paymentProfiles = [];
            foreach ($response['profile']['paymentProfiles'] as $paymentProfile) {
                $paymentProfiles[ $paymentProfile['customerPaymentProfileId'] ] = $paymentProfile;
            }

            ksort($paymentProfiles);
            $newestCard = end($paymentProfiles);
        }

        $card = $this->cardFactory->create();
        $card->setMethod($methodCode);
        $card->setCustomerId($this->hostedForm->getCustomerId());
        $card->setCustomerEmail($this->hostedForm->getEmail());
        $card->setActive(true);
        $card->setProfileId($profileId);
        $card->setPaymentId($newestCard['customerPaymentProfileId']);

        if (isset($newestCard['payment']['creditCard'])) {
            [$yr, $mo] = explode('-', (string)$newestCard['payment']['creditCard']['expirationDate'], 2);
            $day = date('t', strtotime($yr . '-' . $mo));
            $type = $this->helper->mapCcTypeToMagento($newestCard['payment']['creditCard']['cardType']);

            $paymentData = [
                'cc_type'      => $type,
                'cc_last4'     => substr((string)$newestCard['payment']['creditCard']['cardNumber'], -4),
                'cc_exp_year'  => $yr,
                'cc_exp_month' => $mo,
                'cc_bin'       => $newestCard['payment']['creditCard']['issuerNumber'],
            ];

            $card->setData('additional', json_encode($paymentData));
            $card->setData('expires', sprintf('%s-%s-%s 23:59:59', $yr, $mo, $day));
        }

        $this->cardRepository->save($card);

        if ($this->getRequest()->getParam('source') !== 'paymentinfo') {
            // Save card to quote
            $payment = $this->checkoutSession->getQuote()->getPayment();
            $method  = $payment->getMethodInstance();
            $method->assignData(new \Magento\Framework\DataObject(['card_id' => $card->getHash()]));

            $this->paymentResource->save($payment);
        }

        return $card;
    }
}
