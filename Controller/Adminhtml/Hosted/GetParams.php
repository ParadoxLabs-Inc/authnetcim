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
use Magento\Framework\Controller\ResultFactory;

class GetParams extends Action implements CsrfAwareActionInterface, HttpPostActionInterface
{
    /**
     * @var \Magento\Framework\Data\Form\FormKey\Validator
     */
    protected $formKey;

    /**
     * @var \ParadoxLabs\Authnetcim\Model\Service\Hosted\BackendRequest
     */
    protected $hostedForm;

    /**
     * @var \Magento\Framework\Registry
     */
    protected $registry;

    /**
     * @var \Magento\Customer\Api\CustomerRepositoryInterface
     */
    protected $customerRepository;

    /**
     * GetParams constructor.
     *
     * @param \Magento\Backend\App\Action\Context $context
     * @param \Magento\Framework\Data\Form\FormKey\Validator $formKey
     * @param \ParadoxLabs\Authnetcim\Model\Service\Hosted\BackendRequest $hostedForm
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository
     */
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\Data\Form\FormKey\Validator $formKey,
        \ParadoxLabs\Authnetcim\Model\Service\Hosted\BackendRequest $hostedForm,
        \Magento\Framework\Registry $registry,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository
    ) {
        parent::__construct($context);

        $this->formKey = $formKey;
        $this->hostedForm = $hostedForm;
        $this->registry = $registry;
        $this->customerRepository = $customerRepository;
    }

    /**
     * Get hosted form parameters for a session
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $this->initCustomer();

        /** @var \Magento\Framework\Controller\Result\Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        try {
            $params = $this->hostedForm->getParams();

            $result->setData($params);
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
     * Get current customer model.
     *
     * @return void
     */
    protected function initCustomer()
    {
        if ($this->registry->registry('current_customer')) {
            return;
        }

        // Take from request if given (for account card management)
        $customerId = $this->getRequest()->getParam('id');

        if ($customerId === null) {
            // Otherwise, defer to TokenBase logic (for admin ordering)
            $customerId = $this->hostedForm->getCustomerId();
        }

        if ($customerId > 0) {
            try {
                $customer = $this->customerRepository->getById($customerId);

                $this->registry->register('current_customer', $customer);
            } catch (\Magento\Framework\Exception\NoSuchEntityException $exception) {
                // Ignore 'no such customer' errors, remainder code will handle accordingly.
            }
        }
    }
}
