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
