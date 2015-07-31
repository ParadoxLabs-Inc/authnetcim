<?php
/**
 * Paradox Labs, Inc.
 * http://www.paradoxlabs.com
 * 717-431-3330
 *
 * Need help? Open a ticket in our support system:
 *  http://support.paradoxlabs.com
 *
 * @author      Ryan Hoerr <magento@paradoxlabs.com>
 * @license     http://store.paradoxlabs.com/license.html
 */

namespace ParadoxLabs\Authnetcim\Model\Observer;

/**
 * CardLoad Observer
 */
class CardLoad
{
    /**
     * @var \Magento\Framework\Registry
     */
    protected $registry;

    /**
     * @param \Magento\Framework\Registry $registry
     */
    public function __construct(\Magento\Framework\Registry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Check for any cards queued for deletion before we load the card list.
     * This will happen if there is a failure during order submit. We can't
     * actually save it there, so we register and do it here instead. Magic.
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return $this
     */
    public function checkQueuedForDeletion(\Magento\Framework\Event\Observer $observer)
    {
        /** @var \ParadoxLabs\TokenBase\Model\Card $card */
        $card = $this->registry->registry('queue_card_deletion');

        if ($card && $card->getActive() == 1 && $card->getId() > 0) {
            $card->queueDeletion()
                 ->setData('no_sync', true)
                 ->save();
        }

        return $this;
    }
}
