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

namespace ParadoxLabs\Authnetcim\Model\Service\Hosted;

class Context
{
    /**
     * @var \Magento\Framework\Url
     */
    private $urlBuilder;

    /**
     * @var \ParadoxLabs\TokenBase\Model\Method\Factory
     */
    private $methodFactory;

    /**
     * @var \ParadoxLabs\TokenBase\Api\Data\CardInterfaceFactory
     */
    private $cardFactory;

    /**
     * @var \ParadoxLabs\TokenBase\Api\CardRepositoryInterface
     */
    private $cardRepository;

    /**
     * @var \ParadoxLabs\Authnetcim\Helper\Data
     */
    private $helper;

    /**
     * AbstractRequestHandler constructor.
     *
     * @param \Magento\Framework\Url $urlBuilder
     * @param \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
     * @param \ParadoxLabs\TokenBase\Model\Card\Factory $cardFactory
     * @param \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository
     * @param \ParadoxLabs\Authnetcim\Helper\Data $helper
     */
    public function __construct(
        \Magento\Framework\Url $urlBuilder,
        \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory,
        \ParadoxLabs\TokenBase\Api\Data\CardInterfaceFactory $cardFactory,
        \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository,
        \ParadoxLabs\Authnetcim\Helper\Data $helper
    ) {
        $this->urlBuilder = $urlBuilder;
        $this->methodFactory = $methodFactory;
        $this->cardFactory = $cardFactory;
        $this->cardRepository = $cardRepository;
        $this->helper = $helper;
    }

    /**
     * Get urlBuilder
     *
     * @return \Magento\Framework\Url
     */
    public function getUrlBuilder()
    {
        return $this->urlBuilder;
    }

    /**
     * Get methodFactory
     *
     * @return \ParadoxLabs\TokenBase\Model\Method\Factory
     */
    public function getMethodFactory()
    {
        return $this->methodFactory;
    }

    /**
     * Get cardFactory
     *
     * @return \ParadoxLabs\TokenBase\Api\Data\CardInterfaceFactory
     */
    public function getCardFactory()
    {
        return $this->cardFactory;
    }

    /**
     * Get cardRepository
     *
     * @return \ParadoxLabs\TokenBase\Api\CardRepositoryInterface
     */
    public function getCardRepository()
    {
        return $this->cardRepository;
    }

    /**
     * Get helper
     *
     * @return \ParadoxLabs\Authnetcim\Helper\Data
     */
    public function getHelper()
    {
        return $this->helper;
    }
}
