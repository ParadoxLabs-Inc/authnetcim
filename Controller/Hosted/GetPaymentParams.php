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

namespace ParadoxLabs\Authnetcim\Controller\Hosted;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Controller\ResultFactory;

class GetPaymentParams extends Action implements CsrfAwareActionInterface, HttpPostActionInterface
{
    /**
     * @var \Magento\Framework\Data\Form\FormKey\Validator
     */
    protected $formKey;

    /**
     * @var \ParadoxLabs\Authnetcim\Model\Service\AcceptHosted\FrontendRequest
     */
    protected $hostedForm;

    /**
     * GetParams constructor.
     *
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Framework\Data\Form\FormKey\Validator $formKey
     * @param \ParadoxLabs\Authnetcim\Model\Service\AcceptHosted\FrontendRequest $hostedForm
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\Data\Form\FormKey\Validator $formKey,
        \ParadoxLabs\Authnetcim\Model\Service\AcceptHosted\FrontendRequest $hostedForm
    ) {
        parent::__construct($context);

        $this->formKey = $formKey;
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
}
