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

/**
 * UpdateCard Class
 */
class UpdateCard extends GetNewCard
{
    /**
     * @var \Magento\Framework\Registry
     */
    protected $registry;

    /**
     * @var \Magento\Customer\Api\CustomerRepositoryInterface
     */
    protected $customerRepository;

    /**
     * UpdateCard constructor.
     *
     * @param \ParadoxLabs\Authnetcim\Controller\Adminhtml\Hosted\Action\Context $context
     * @param \Magento\Framework\Data\Form\FormKey\Validator $formKey
     * @param \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Backend\Model\Session\Quote $checkoutSession
     * @param \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository
     * @param \ParadoxLabs\TokenBase\Api\Data\CardInterfaceFactory $cardFactory
     * @param \ParadoxLabs\Authnetcim\Helper\Data $helper
     * @param \Magento\Quote\Model\ResourceModel\Quote\Payment $paymentResource
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository
     */
    public function __construct(
        Action\Context $context,
        \Magento\Framework\Data\Form\FormKey\Validator $formKey,
        \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Backend\Model\Session\Quote $checkoutSession,
        \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository,
        \ParadoxLabs\TokenBase\Api\Data\CardInterfaceFactory $cardFactory,
        \ParadoxLabs\Authnetcim\Helper\Data $helper,
        \Magento\Quote\Model\ResourceModel\Quote\Payment $paymentResource,
        \ParadoxLabs\Authnetcim\Model\Service\Hosted\BackendRequest $hostedForm,
        \Magento\Framework\Registry $registry,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository
    ) {
        parent::__construct(
            $context,
            $formKey,
            $methodFactory,
            $storeManager,
            $checkoutSession,
            $cardRepository,
            $cardFactory,
            $helper,
            $paymentResource,
            $hostedForm
        );

        $this->registry = $registry;
        $this->customerRepository = $customerRepository;
    }


    /**
     * Execute action based on request and return result
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $this->getCustomer();

        // TODO: Consolidate duplicated code

        return parent::execute();
    }

    /**
     * Get current customer model.
     *
     * @return \Magento\Customer\Api\Data\CustomerInterface
     */
    protected function getCustomer()
    {
        if ($this->registry->registry('current_customer')) {
            return $this->registry->registry('current_customer');
        }

        $customerId = (int)$this->getRequest()->getParam('id');
        $customer   = $this->customerRepository->getById($customerId);

        $this->registry->register('current_customer', $customer);

        return $customer;
    }

    /**
     * Get hosted profile page request token
     *
     * @return \ParadoxLabs\TokenBase\Api\Data\CardInterface
     */
    public function getCard()
    {
        $cardId = $this->getRequest()->getParam('card_id');

        if (!empty($cardId)) {
            $card = $this->cardRepository->getByHash($cardId);

            if ($card->hasOwner((int)$this->helper->getCurrentCustomer()->getId()) === false) {
                throw new \Magento\Framework\Exception\LocalizedException(__('Could not load payment profile'));
            }

            $this->updateCard($card);
        } else {
            // If not an existing card, load it in as a newly added card
            $card = parent::getCard();
        }

        return $card;
    }

    /**
     * @param \ParadoxLabs\TokenBase\Api\Data\CardInterface $card
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Payment\Gateway\Command\CommandException
     */
    public function updateCard(\ParadoxLabs\TokenBase\Api\Data\CardInterface $card): void
    {
        $methodCode = \ParadoxLabs\Authnetcim\Model\ConfigProvider::CODE;
        if ($this->getRequest()->getParam('method') === \ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider::CODE) {
            $methodCode = \ParadoxLabs\Authnetcim\Model\Ach\ConfigProvider::CODE;
        }

        /** @var \ParadoxLabs\Authnetcim\Model\Method $method */
        $method = $this->methodFactory->getMethodInstance($methodCode);
        $method->setStore($this->helper->getCurrentStoreId());
        /** @var \ParadoxLabs\Authnetcim\Model\Gateway $gateway */
        $gateway = $method->gateway();

        // Get CIM profile cards
        $gateway->setParameter('customerProfileId', $card->getProfileId());
        $gateway->setParameter('customerPaymentProfileId', $card->getPaymentId());
        $gateway->setParameter('unmaskExpirationDate', 'true');
        $gateway->setParameter('includeIssuerInfo', 'true');

        $response = $gateway->getCustomerPaymentProfile();

        if (!empty($response['messages']['message']['text'])
            && $response['messages']['message']['text'] !== 'Successful.') {
            throw new \Magento\Framework\Exception\LocalizedException(__($response['messages']['message']['text']));
        }

        $paymentProfile = $response['paymentProfile'];

        if (isset($paymentProfile['payment']['creditCard'])) {
            [$yr, $mo] = explode('-', (string)$paymentProfile['payment']['creditCard']['expirationDate'], 2);
            $day = date('t', strtotime($yr . '-' . $mo));
            $type = $this->helper->mapCcTypeToMagento($paymentProfile['payment']['creditCard']['cardType']);

            $paymentData = $card->getAdditional();
            $paymentData = [
                    'cc_type'      => $type,
                    'cc_last4'     => substr((string)$paymentProfile['payment']['creditCard']['cardNumber'], -4),
                    'cc_exp_year'  => $yr,
                    'cc_exp_month' => $mo,
                    'cc_bin'       => $paymentProfile['payment']['creditCard']['issuerNumber'],
                ] + $paymentData;

            $card->setAdditional($paymentData);
            $card->setData('expires', sprintf('%s-%s-%s 23:59:59', $yr, $mo, $day));
        }

        $this->cardRepository->save($card);
    }
}
