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

namespace ParadoxLabs\Authnetcim\Helper;

/**
 * Authorize.Net CIM Helper -- response translation maps et al.
 */
class Data extends \ParadoxLabs\TokenBase\Helper\Data
{
    /**
     * @var array
     */
    protected $avsResponses = [
        'B' => 'No address submitted; could not perform AVS check.',
        'E' => 'AVS data invalid',
        'R' => 'AVS unavailable',
        'G' => 'AVS not supported',
        'U' => 'AVS unavailable',
        'S' => 'AVS not supported',
        'N' => 'Street and zipcode do not match.',
        'A' => 'Street matches; zipcode does not.',
        'Z' => '5-digit zip matches; street does not.',
        'W' => '9-digit zip matches; street does not.',
        'Y' => 'Perfect match',
        'X' => 'Perfect match',
        'P' => 'N/A',
    ];

    /**
     * @var array
     */
    protected $ccvResponses = [
        'M' => 'Passed',
        'N' => 'Failed',
        'P' => 'Not processed',
        'S' => 'Not received',
        'U' => 'N/A',
    ];

    /**
     * @var array
     */
    protected $cavvResponses = [
        '0' => 'Not validated; bad data',
        '1' => 'Failed',
        '2' => 'Passed',
        '3' => 'CAVV unavailable',
        '4' => 'CAVV unavailable',
        '7' => 'Failed',
        '8' => 'Passed',
        '9' => 'Failed (issuer unavailable)',
        'A' => 'Passed (issuer unavailable)',
        'B' => 'Passed (info only)',
    ];

    /**
     * @var array
     */
    protected $cimCardTypeMap = [
        'American Express' => 'AE',
        'AmericanExpress'  => 'AE',
        'Discover'         => 'DI',
        'Diners Club'      => 'DC',
        'JCB'              => 'JCB',
        'MasterCard'       => 'MC',
        'Visa'             => 'VI',
    ];

    /**
     * Translate AVS response codes shown on admin order pages.
     *
     * @param string $code
     * @return \Magento\Framework\Phrase|string
     */
    public function translateAvs($code)
    {
        if (isset($this->avsResponses[$code])) {
            return __(sprintf('%s (%s)', $code, $this->avsResponses[$code]));
        }

        return $code;
    }

    /**
     * Translate CCV response codes shown on admin order pages.
     *
     * @param string $code
     * @return \Magento\Framework\Phrase|string
     */
    public function translateCcv($code)
    {
        if (isset($this->ccvResponses[$code])) {
            return __(sprintf('%s (%s)', $code, $this->ccvResponses[$code]));
        }

        return $code;
    }

    /**
     * Translate CAVV response codes shown on admin order pages.
     *
     * @param string $code
     * @return \Magento\Framework\Phrase|string
     */
    public function translateCavv($code)
    {
        if (isset($this->cavvResponses[$code])) {
            return __(sprintf('%s (%s)', $code, $this->cavvResponses[$code]));
        }

        return $code;
    }

    /**
     * Map CC Type to Magento's.
     *
     * @param string $type
     * @return string|null
     */
    public function mapCcTypeToMagento($type)
    {
        if (!empty($type) && isset($this->cimCardTypeMap[$type])) {
            return $this->cimCardTypeMap[$type];
        }

        return null;
    }
}
