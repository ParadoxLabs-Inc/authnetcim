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

$dir = __DIR__;
$ds  = DIRECTORY_SEPARATOR;
if (isset($file) && strpos((string)$file, $ds . 'vendor' . $ds . 'composer' . $ds . '..') === false) {
    $dir = dirname((string)$file);
}

\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'ParadoxLabs_Authnetcim',
    $dir
);
