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

namespace ParadoxLabs\Authnetcim\Model\Service;

use ParadoxLabs\Authnetcim\Model\ConfigProvider;

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
     * @var \ParadoxLabs\TokenBase\Helper\Operation
     */
    protected $helper;

    /**
     * @var \ParadoxLabs\Authnetcim\Model\ConfigProvider
     */
    protected $config;

    /**
     * @var \Magento\Framework\HTTP\ZendClientFactory
     */
    protected $httpClientFactory;

    /**
     * @var \Magento\Framework\Module\Dir
     */
    protected $moduleDir;

    /**
     * RestClient constructor.
     *
     * @param \ParadoxLabs\TokenBase\Helper\Operation $helper
     * @param \ParadoxLabs\Authnetcim\Model\ConfigProvider $config
     * @param \Magento\Framework\HTTP\ZendClientFactory $httpClientFactory
     * @param \Magento\Framework\Module\Dir $moduleDir
     */
    public function __construct(
        \ParadoxLabs\TokenBase\Helper\Operation $helper,
        ConfigProvider $config,
        \Magento\Framework\HTTP\ZendClientFactory $httpClientFactory,
        \Magento\Framework\Module\Dir $moduleDir
    ) {
        $this->helper = $helper;
        $this->config = $config;
        $this->httpClientFactory = $httpClientFactory;
        $this->moduleDir = $moduleDir;
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
        $this->apiLoginId = $apiLoginId;
        $this->transactionKey = $transactionKey;
        $this->signatureKey = $signatureKey;
        $this->sandbox = $sandbox;
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
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function delete(string $path): void
    {
        $this->helper->log(ConfigProvider::CODE, 'DELETE ' . $path, true);

        $client = $this->getHttpClient($path);
        $clientResult = $client->request('DELETE');

        if ($clientResult && strpos($clientResult->getBody(), 'NOT_FOUND') !== false) {
            return;
        }

        $this->checkErrors($clientResult);
    }

    /**
     * Send POST request to path with params
     *
     * @param string $path
     * @param array $params
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function post(string $path, array $params): array
    {
        $this->helper->log(ConfigProvider::CODE, 'POST ' . $path . ': ' . json_encode($params), true);

        $client = $this->getHttpClient($path);
        $client->setRawData(json_encode($params));
        $clientResult = $client->request('POST');

        $this->checkErrors($clientResult);

        return json_decode((string)$clientResult->getBody(), true) ?? [];
    }

    /**
     * Send PUT request to path with params
     *
     * @param string $path
     * @param array $params
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function put(string $path, array $params): array
    {
        $this->helper->log(ConfigProvider::CODE, 'PUT ' . $path . ': ' . http_build_query($params), true);

        $client = $this->getHttpClient($path);
        $client->setRawData(json_encode($params));
        $clientResult = $client->request('PUT');

        $this->checkErrors($clientResult);

        return json_decode((string)$clientResult->getBody(), true) ?? [];
    }

    /**
     * Send GET request to path with params
     *
     * @param string $path
     * @param array $params
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function get(string $path, array $params = []): array
    {
        $paramString = http_build_query($params);

        $this->helper->log(ConfigProvider::CODE, 'GET ' . $path . '?' . $paramString, true);

        $client = $this->getHttpClient($path . '?' . $paramString);
        $clientResult = $client->request('GET');

        $this->checkErrors($clientResult);

        return json_decode((string)$clientResult->getBody(), true) ?? [];
    }

    /**
     * Validate response, throw exception on invalid or error result
     *
     * @param \Zend_Http_Response $response
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function checkErrors($response): void
    {
        $responseCode = $response->getStatus();
        $responseBody = $response->getBody();

        // Throw exception on non-2xx response code
        if (substr((string)$responseCode, 0, 1) !== '2'
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

            throw new \Magento\Framework\Exception\LocalizedException(
                __($message),
                null,
                $responseCode
            );
        }
    }

    /**
     * Get an HTTP client for REST
     *
     * @param string $path
     * @return \Magento\Framework\HTTP\ZendClient
     */
    protected function getHttpClient($path): \Magento\Framework\HTTP\ZendClient
    {
        $headers = [
            'Content-Type: application/json',
            'Authorization: ' . $this->getAuthHeader(),
        ];

        $clientConfig = [
            'adapter'     => \Zend_Http_Client_Adapter_Curl::class,
            'timeout'     => 15,
            'verifypeer' => true,
            'verifyhost' => 2,
            'curloptions' => [
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CAINFO => $this->moduleDir->getDir('ParadoxLabs_Authnetcim') . '/authorizenet-cert.pem',
            ],
        ];

        /** @var \Magento\Framework\HTTP\ZendClient $httpClient */
        $httpClient = $this->httpClientFactory->create();
        $httpClient->setUri($this->getRestEndpoint() . $path);
        $httpClient->setConfig($clientConfig);
        $httpClient->setHeaders($headers);

        return $httpClient;
    }

    /**
     * Get REST endpoint URL
     *
     * @return string
     */
    public function getRestEndpoint(): string
    {
        return self::ENDPOINTS[$this->sandbox ? 'sandbox' : 'live'];
    }
}
