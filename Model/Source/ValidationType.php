<?php
/**
 * Paradox Labs, Inc.
 * http://www.paradoxlabs.com
 * 717-431-3330
 *
 * Need help? Open a ticket in our support system:
 *  http://support.paradoxlabs.com
 *
 * @author      Ryan Hoerr <support@paradoxlabs.com>
 * @license     http://store.paradoxlabs.com/license.html
 */

namespace ParadoxLabs\Authnetcim\Model\Source;

use Magento\Framework\Option\ArrayInterface;

/**
 * Payment method settings: Validation types
 */
class ValidationType implements ArrayInterface
{
    /**
     * {@inheritdoc}
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'liveMode', 'label' => __('Live ($0.01 test transaction)')],
            ['value' => 'testMode', 'label' => __('Test (Card number validation only)')],
            ['value' => 'none',     'label' => __('None (No validation performed)')],
        ];
    }
}
