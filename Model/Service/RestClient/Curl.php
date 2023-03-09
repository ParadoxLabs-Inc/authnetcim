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

namespace ParadoxLabs\Authnetcim\Model\Service\RestClient;

class Curl extends \Magento\Framework\HTTP\Client\Curl
{
    /**
     * Make DELETE request
     *
     * @param string $uri
     * @return void
     */
    public function delete(string $uri): void
    {
        $this->makeRequest('DELETE', $uri);
    }

    /**
     * Make PUT request
     *
     * @param string $uri
     * @param array|string $params
     * @return void
     */
    public function put(string $uri, $params): void
    {
        $this->setOption(CURLOPT_POSTFIELDS, is_array($params) ? http_build_query($params) : $params);

        $this->makeRequest('PUT', $uri);
    }
}
