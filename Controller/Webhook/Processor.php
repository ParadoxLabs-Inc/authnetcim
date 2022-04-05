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

namespace ParadoxLabs\Authnetcim\Controller\Webhook;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;

class Processor extends \Magento\Framework\App\Action\Action
{
    /**
     * @var \ParadoxLabs\Authnetcim\Model\Service\WebhookProcessor
     */
    protected $webhookProcessor;

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \ParadoxLabs\Authnetcim\Model\Service\WebhookProcessor $webhookProcessor
     * @param \Magento\Framework\Data\Form\FormKey $formKey
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \ParadoxLabs\Authnetcim\Model\Service\WebhookProcessor $webhookProcessor,
        \Magento\Framework\Data\Form\FormKey $formKey
    ) {
        parent::__construct($context);

        $this->webhookProcessor = $webhookProcessor;

        // CSRF/form key protection compatibility
        if (interface_exists(CsrfAwareActionInterface::class)) {
            $request = $this->getRequest();
            if ($request instanceof Http && $request->isPost() && empty($request->getParam('form_key'))) {
                $request->setParam('form_key', $formKey->getFormKey());
            }
        }
    }

    /**
     * Process webhook based on request and return result
     *
     * @return \Magento\Framework\Controller\ResultInterface|\Magento\Framework\App\ResponseInterface
     * @throws \Magento\Framework\Exception\NotFoundException
     */
    public function execute()
    {
        $jsonResponse = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_JSON);

        try {
            $this->webhookProcessor->process();
            $jsonResponse->setStatusHeader(200);
            $jsonResponse->setData([]);
        } catch (\Exception $exception) {
            $jsonResponse->setStatusHeader(400);
            $jsonResponse->setData(['error' => $exception->getMessage()]);
        }

        return $jsonResponse;
    }
}
