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

namespace ParadoxLabs\Authnetcim\Plugin\Magento\Framework\App\Response\HeaderProvider;

class XFrameOptions
{
    /**
     * @var \Magento\Framework\App\Request\Http
     */
    protected $request;

    /**
     * XFrameOptions constructor.
     *
     * @param \Magento\Framework\App\RequestInterface $request
     */
    public function __construct(
        \Magento\Framework\App\RequestInterface $request
    ) {
        $this->request = $request;
    }

    /**
     * Apply the X-Frame-Options header to the current request?
     *
     * @param \Magento\Framework\App\Response\HeaderProvider\XFrameOptions $subject
     * @param bool $result
     * @return bool
     */
    public function afterCanApply(
        \Magento\Framework\App\Response\HeaderProvider\XFrameOptions $subject,
        $result
    ) {
        /**
         * Suppress X-Frame-Options for the Authorize.net hosted form communicator. It will necessarily be loaded within
         * an iframe from accept.authorize.net, for legitimate reasons. CSP will prevent use by unauthorized domains.
         *
         * Note: Have to analyze path manually, because routed request data is not available at time of execution.
         */
        $path = $this->request->getPathInfo();
        $pos  = strpos($path, '/authnetcim/hosted/communicator/');
        if ($pos !== false
            && substr_count($path, '/', 0, $pos) <= 2
            && substr_count($path, '?', 0, $pos) == 0) {
            return false;
        }

        return $result;
    }
}
