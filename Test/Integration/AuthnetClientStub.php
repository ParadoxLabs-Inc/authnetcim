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

namespace ParadoxLabs\Authnetcim\Test\Integration;

use LogicException;
use Magento\Framework\HTTP\ClientInterface;

/**
 * Test double for the Authorize.Net XML API HTTP boundary.
 *
 * {@see \ParadoxLabs\Authnetcim\Model\Gateway::runTransaction()} posts an XML document via a client
 * created from {@see \Magento\Framework\HTTP\ClientInterfaceFactory} and reads the reply from
 * getBody(). This double records every posted request (keyed by its XML root element, e.g.
 * 'createTransactionRequest') and delegates response shaping to a caller-supplied responder
 * callable: fn(string $requestType, string $requestXml): string (an XML reply document).
 */
class AuthnetClientStub implements ClientInterface
{
    /**
     * Recorded calls: each entry is ['type' => (request root element), 'xml' => (request body)].
     *
     * @var array<int, array{type: string, xml: string}>
     */
    public array $requests = [];

    /**
     * @var callable|null
     */
    private $responder;

    /**
     * @var string
     */
    private string $body = '';

    /**
     * Configure the response shaper used for every posted request.
     *
     * @param callable $responder fn(string $requestType, string $requestXml): string
     * @return void
     */
    public function setResponder(callable $responder): void
    {
        $this->responder = $responder;
    }

    /**
     * Return the recorded request root elements in call order.
     *
     * @return array<int, string>
     */
    public function getRequestTypes(): array
    {
        return array_column($this->requests, 'type');
    }

    /**
     * Return the recorded request bodies for the given request root element.
     *
     * @param string $type
     * @return array<int, string>
     */
    public function getRequestsOfType(string $type): array
    {
        $bodies = [];

        foreach ($this->requests as $request) {
            if ($request['type'] === $type) {
                $bodies[] = $request['xml'];
            }
        }

        return $bodies;
    }

    /**
     * Record the posted XML request and shape the reply via the responder.
     *
     * @param string $uri
     * @param array|string $params
     * @return void
     */
    public function post($uri, $params)
    {
        $requestXml  = (string)$params;
        $requestType = 'unknown';

        if (preg_match('/<([A-Za-z]+Request)[\s>]/', $requestXml, $matches)) {
            $requestType = $matches[1];
        }

        $this->requests[] = [
            'type' => $requestType,
            'xml' => $requestXml,
        ];

        if ($this->responder === null) {
            throw new LogicException(
                sprintf('AuthnetClientStub has no responder configured for %s', $requestType)
            );
        }

        $this->body = (string)($this->responder)($requestType, $requestXml);
    }

    /**
     * Return the reply produced for the last posted request.
     *
     * @return string
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * @param string $uri
     * @return array
     */
    public function get($uri)
    {
        throw new LogicException('AuthnetClientStub does not support GET requests.');
    }

    /**
     * @param int $value
     * @return void
     */
    public function setTimeout($value)
    {
    }

    /**
     * @param array $headers
     * @return void
     */
    public function setHeaders($headers)
    {
    }

    /**
     * @param string $name
     * @param string $value
     * @return void
     */
    public function addHeader($name, $value)
    {
    }

    /**
     * @param string $name
     * @return void
     */
    public function removeHeader($name)
    {
    }

    /**
     * @param string $login
     * @param string $pass
     * @return void
     */
    public function setCredentials($login, $pass)
    {
    }

    /**
     * @param string $name
     * @param string $value
     * @return void
     */
    public function addCookie($name, $value)
    {
    }

    /**
     * @param string $name
     * @return void
     */
    public function removeCookie($name)
    {
    }

    /**
     * @param array $cookies
     * @return void
     */
    public function setCookies($cookies)
    {
    }

    /**
     * @return void
     */
    public function removeCookies()
    {
    }

    /**
     * @return array
     */
    public function getHeaders()
    {
        return [];
    }

    /**
     * @return int
     */
    public function getStatus()
    {
        return 200;
    }

    /**
     * @return array
     */
    public function getCookies()
    {
        return [];
    }

    /**
     * @param string $key
     * @param string $value
     * @return void
     */
    public function setOption($key, $value)
    {
    }

    /**
     * @param array $arr
     * @return void
     */
    public function setOptions($arr)
    {
    }
}
