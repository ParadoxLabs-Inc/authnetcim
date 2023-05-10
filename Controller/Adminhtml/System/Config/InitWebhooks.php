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

namespace ParadoxLabs\Authnetcim\Controller\Adminhtml\System\Config;

use \Magento\Backend\App\Action\Context;
use ParadoxLabs\Authnetcim\Model\Service\WebhookProcessor;

class InitWebhooks extends \Magento\Backend\App\Action
{
    /**
     * @var \ParadoxLabs\Authnetcim\Model\Service\WebhookProcessor
     */
    protected $webhookProcessor;

    /**
     * @var \ParadoxLabs\Authnetcim\Model\Service\RestClient
     */
    protected $restClient;

    /**
     * @var \ParadoxLabs\Authnetcim\Block\Adminhtml\Config\ApiTest
     */
    protected $apiTester;

    /**
     * @var \Magento\Framework\Url
     */
    protected $frontendUrl;

    /**
     * @var \ParadoxLabs\Authnetcim\Block\Adminhtml\Config\AchApiTest
     */
    protected $achApiTest;

    /**
     * @param Context $context
     * @param \ParadoxLabs\Authnetcim\Model\Service\WebhookProcessor $webhookProcessor
     * @param \ParadoxLabs\Authnetcim\Model\Service\RestClient $restClient
     * @param \ParadoxLabs\Authnetcim\Block\Adminhtml\Config\ApiTest $apiTester
     * @param \ParadoxLabs\Authnetcim\Block\Adminhtml\Config\AchApiTest $achApiTester
     * @param \Magento\Framework\Url $frontendUrl
     */
    public function __construct(
        Context $context,
        \ParadoxLabs\Authnetcim\Model\Service\WebhookProcessor $webhookProcessor,
        \ParadoxLabs\Authnetcim\Model\Service\RestClient $restClient,
        \ParadoxLabs\Authnetcim\Block\Adminhtml\Config\ApiTest $apiTester,
        \ParadoxLabs\Authnetcim\Block\Adminhtml\Config\AchApiTest $achApiTester,
        \Magento\Framework\Url $frontendUrl
    ) {
        parent::__construct($context);

        $this->webhookProcessor = $webhookProcessor;
        $this->restClient = $restClient;
        $this->apiTester = $apiTester;
        $this->frontendUrl = $frontendUrl;

        if ($this->getRequest()->getParam('method') === 'authnetcim_ach') {
            $this->apiTester = $achApiTester;
        }
    }

    /**
     * Execute action based on request and return result
     *
     * @return \Magento\Framework\Controller\ResultInterface|\Magento\Framework\App\ResponseInterface
     * @throws \Magento\Framework\Exception\NotFoundException
     */
    public function execute()
    {
        /** @var \Magento\Framework\Controller\Result\Json $resultJson */
        $resultJson = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_JSON);

        try {
            $this->initRestClient();

            $webhookUrl = $this->getScopedWebhookUrl();
            $webhooks   = $this->restClient->get('webhooks', []);
            foreach ($webhooks as $webhook) {
                if ($webhook['url'] === $webhookUrl) {
                    $this->restClient->delete('webhooks/' . $webhook['webhookId']);
                    break;
                }
            }

            $curlResult = $this->restClient->post('webhooks', $this->getDefaultWebhook());
            $webhookId  = $curlResult['webhookId'];

            $this->restClient->post('webhooks/' . $webhookId . '/pings', []);
            $this->restClient->put('webhooks/' . $webhookId, ['status' => 'active']);

            $resultJson->setStatusHeader(200);
            $resultJson->setData([
                'message' => __(
                    'API Login ID and Transaction Key work. Webhooks connected for your store: %1',
                    $webhookUrl
                )
            ]);
        } catch (\Exception $exception) {
            $resultJson->setStatusHeader(400);
            $resultJson->setData([
                'message' => sprintf('%s (%s)', $exception->getMessage(), $exception->getCode()),
            ]);
        }

        return $resultJson;
    }

    /**
     * Get the default Authorize.net webhook configuration
     *
     * @see https://developer.authorize.net/api/reference/features/webhooks.html
     * @return array
     */
    protected function getDefaultWebhook(): array
    {
        $params = [
            'name' => 'ParadoxLabs AuthorizeNet CIM for Magento 2',
            'url' => $this->getScopedWebhookUrl(),
            'eventTypes' => WebhookProcessor::TRANSACTION_EVENTS,
            'status' => 'inactive',
        ];

        return $params;
    }

    /**
     * Get the site URL the webhook should connect to -- must be web accessible to Authorize.net.
     *
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getScopedWebhookUrl(): string
    {
        $params = [
            '_scope' => $this->getStore(),
            '_nosid' => true,
        ];

        if ($this->getRequest()->getParam('method') === 'authnetcim_ach') {
            $params['ach'] = 1;
        }

        return $this->frontendUrl->getUrl('authnetcim/webhook/processor', $params);
    }

    /**
     * Set up the REST client for webhook API setup calls
     *
     * (yes, this is highly temporally coupled, deal with it)
     *
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function initRestClient(): void
    {
        $apiLoginId     = $this->getRequest()->getParam('apiLoginId');
        $transactionKey = $this->getRequest()->getParam('transactionKey');
        $signatureKey   = $this->getRequest()->getParam('signatureKey');

        $this->restClient->setAuth(
            $apiLoginId !== '******' ? $apiLoginId : $this->getConfigData('login'),
            $transactionKey !== '******' ? $transactionKey : $this->getConfigData('trans_key'),
            $signatureKey !== '******' ? $signatureKey : $this->getConfigData('signature_key'),
            (bool)$this->getRequest()->getParam('sandbox')
        );
    }

    /**
     * Get the current config store scope
     *
     * @return int
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getStore(): int
    {
        return (int)$this->apiTester->getMethodInstance()->getData('store');
    }

    /**
     * Get a scoped config value by key
     *
     * @param string $key
     * @return mixed
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getConfigData(string $key)
    {
        return $this->apiTester->getMethodInstance()->getConfigData($key);
    }
}
