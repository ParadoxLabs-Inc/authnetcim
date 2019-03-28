<?php
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

namespace ParadoxLabs\Authnetcim\Block\Adminhtml\Config;

/**
 * AcceptjsTest Class
 */
class AcceptjsTest extends \Magento\Framework\View\Element\Template
{
    /**
     * @var string
     */
    protected $code = 'authnetcim';

    /**
     * @var \ParadoxLabs\TokenBase\Model\Method\Factory
     */
    protected $methodFactory;

    /**
     * @var \ParadoxLabs\Authnetcim\Model\Method
     */
    protected $method;

    /**
     * Constructor
     *
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);

        $this->methodFactory = $methodFactory;
        $this->setTemplate('ParadoxLabs_Authnetcim::config/acceptjs-test.phtml');
    }

    /**
     * @return \ParadoxLabs\Authnetcim\Model\Method
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getMethodInstance()
    {
        /** @var \ParadoxLabs\Authnetcim\Model\Method $method */
        $this->method = $this->methodFactory->getMethodInstance($this->code);
        $this->method->setStore($this->getStoreId());

        return $this->method;
    }
}
