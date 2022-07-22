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
     * @param \Magento\Backend\App\Action\Context $context
     * @param \Magento\Framework\Data\Form\FormKey\Validator $formKey
     * @param \ParadoxLabs\Authnetcim\Model\Service\Hosted\BackendRequest $hostedForm
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository
     */
    public function __construct(
        Action\Context $context,
        \Magento\Framework\Data\Form\FormKey\Validator $formKey,
        \ParadoxLabs\Authnetcim\Model\Service\Hosted\BackendRequest $hostedForm,
        \Magento\Framework\Registry $registry,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository
    ) {
        parent::__construct(
            $context,
            $formKey,
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
}
