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

namespace ParadoxLabs\Authnetcim\Plugin\Magento\Framework\App\Response\HeaderProvider;

/**
 * XFrameOptions Class
 */
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
     * @return false
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
