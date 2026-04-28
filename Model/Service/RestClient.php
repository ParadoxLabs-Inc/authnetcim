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

namespace ParadoxLabs\Authnetcim\Model\Service;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\ZendClientFactory;
use Magento\Framework\Module\Dir;
use ParadoxLabs\Authnetcim\Model\ConfigProvider;
use ParadoxLabs\Authnetcim\Model\Service\RestClient\Curl;
use ParadoxLabs\Authnetcim\Model\Service\RestClient\CurlFactory;
use ParadoxLabs\TokenBase\Helper\Operation;
use const CURLOPT_CAINFO;
use const CURLOPT_SSL_VERIFYHOST;
use const CURLOPT_SSL_VERIFYPEER;

class RestClient
{
    const ENDPOINTS = [
        'live' => 'https://api2.authorize.net/rest/v1/',
        'sandbox' => 'https://apitest.authorize.net/rest/v1/',
    ];

    protected $apiLoginId;
    protected $transactionKey;
    protected $signatureKey;
    protected $sandbox;

    /**
     * @var \Magento\Framework\HTTP\ZendClientFactory
     */
    protected $httpClientFactory;

    /**
     * @var \ParadoxLabs\Authnetcim\Model\Service\RestClient\CurlFactory
     */
    protected $curlClientFactory;

    /**
     * RestClient constructor.
     *
     * @param Operation $helper
     * @param ConfigProvider $config
     * @param \Magento\Framework\HTTP\ZendClientFactory $httpClientFactory
     * @param Dir $moduleDir
     * @param \ParadoxLabs\Authnetcim\Model\Service\RestClient\CurlFactory $curlClientFactory
     */
    public function __construct(
        protected readonly Operation $helper,
        protected readonly ConfigProvider $config,
        ZendClientFactory $httpClientFactory,
        protected readonly Dir $moduleDir,
        CurlFactory $curlClientFactory,
    ) {
        $this->httpClientFactory = $httpClientFactory;
        $this->curlClientFactory = $curlClientFactory;
    }

    /**
     * Set API keys and details for follow-on API calls.
     *
     * @param string $apiLoginId
     * @param string $transactionKey
     * @param string $signatureKey
     * @param bool $sandbox
     * @return void
     */
    public function setAuth(string $apiLoginId, string $transactionKey, string $signatureKey, bool $sandbox): void
    {
        $this->apiLoginId     = $apiLoginId;
        $this->transactionKey = $transactionKey;
        $this->signatureKey   = $signatureKey;
        $this->sandbox        = $sandbox;
    }

    /**
     * Get REST API authentication header (requires API keys stored via setAuth())
     *
     * @return string
     */
    protected function getAuthHeader(): string
    {
        return 'Basic ' . base64_encode($this->apiLoginId . ':' . $this->transactionKey);
    }

    /**
     * Send DELETE request to path
     *
     * @param string $path
     * @return void
     * @throws LocalizedException
     */
    public function delete(string $path): void
    {
        $this->helper->log(ConfigProvider::CODE, 'DELETE ' . $path, true);

        $client = $this->getHttpClient();
        $client->delete($this->getRestEndpoint() . $path);

        if (str_contains($client->getBody(), 'NOT_FOUND')) {
            return;
        }

        $this->checkErrors($client);
    }

    /**
     * Send POST request to path with params
     *
     * @param string $path
     * @param array $params
     * @return array
     * @throws LocalizedException
     */
    public function post(string $path, array $params): array
    {
        $this->helper->log(ConfigProvider::CODE, 'POST ' . $path . ': ' . json_encode($params), true);

        $client = $this->getHttpClient();
        $client->post($this->getRestEndpoint() . $path, json_encode($params));

        $this->checkErrors($client);

        return json_decode((string)$client->getBody(), true) ?? [];
    }

    /**
     * Send PUT request to path with params
     *
     * @param string $path
     * @param array $params
     * @return array
     * @throws LocalizedException
     */
    public function put(string $path, array $params): array
    {
        $this->helper->log(ConfigProvider::CODE, 'PUT ' . $path . ': ' . http_build_query($params), true);

        $client = $this->getHttpClient();
        $client->put($this->getRestEndpoint() . $path, json_encode($params));

        $this->checkErrors($client);

        return json_decode((string)$client->getBody(), true) ?? [];
    }

    /**
     * Send GET request to path with params
     *
     * @param string $path
     * @param array $params
     * @return array
     * @throws LocalizedException
     */
    public function get(string $path, array $params = []): array
    {
        $paramString = http_build_query($params);

        $this->helper->log(ConfigProvider::CODE, 'GET ' . $path . '?' . $paramString, true);

        $client = $this->getHttpClient();
        $client->get($this->getRestEndpoint() . $path . '?' . $paramString);

        $this->checkErrors($client);

        return json_decode((string)$client->getBody(), true) ?? [];
    }

    /**
     * Validate response, throw exception on invalid or error result
     *
     * @param Curl $response
     * @return void
     * @throws LocalizedException
     */
    protected function checkErrors($response): void
    {
        $responseCode = $response->getStatus();
        $responseBody = $response->getBody();

        // Throw exception on non-2xx response code
        if (!str_starts_with((string)$responseCode, '2')
            || $responseBody === false) {
            if ($responseBody !== false) {
                $responseJson = json_decode((string)$responseBody, true);
            }

            $message = isset($responseJson) && is_array($responseJson)
                ? sprintf(
                    '%s (%s)',
                    $responseJson['details'][0]['message'] ?? $responseJson['message'],
                    $responseJson['reason']
                )
                : (string)$responseBody;

            if (empty($message)) {
                $message = 'RestClient: Unable to reach Authorize.net; no response.';
            }

            $this->helper->log(ConfigProvider::CODE, $message);
            $this->helper->log(ConfigProvider::CODE, $responseJson, true);

            throw new LocalizedException(
                __($message),
                null,
                $responseCode
            );
        }
    }

    /**
     * Get an HTTP client for REST
     *
     * @return Curl
     */
    protected function getHttpClient(): Curl
    {
        /** @var Curl $communicator */
        $communicator = $this->curlClientFactory->create();

        $communicator->setTimeout(15);

        $certificatePath = $this->moduleDir->getDir('ParadoxLabs_Authnetcim') . '/authorizenet-cert.pem';
        $communicator->setOption(CURLOPT_SSL_VERIFYPEER, true);
        $communicator->setOption(CURLOPT_SSL_VERIFYHOST, 2);
        $communicator->setOption(CURLOPT_CAINFO, $certificatePath);

        $communicator->addHeader('Content-Type', 'application/json');
        $communicator->addHeader('Authorization', $this->getAuthHeader());

        return $communicator;
    }

    /**
     * Get REST endpoint URL
     *
     * @return string
     */
    public function getRestEndpoint(): string
    {
        return self::ENDPOINTS[ $this->sandbox ? 'sandbox' : 'live' ];
    }
}
