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

namespace ParadoxLabs\Authnetcim\Model\Ach;

/**
 * ConfigProvider Class
 */
class ConfigProvider extends \ParadoxLabs\Authnetcim\Model\ConfigProvider
{
    /**
     * @var string
     */
    protected $code = 'authnetcim_ach';

    /**
     * @param \Magento\Payment\Model\CcConfig $ccConfig
     * @param \Magento\Payment\Helper\Data $paymentHelper
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Payment\Model\Config $paymentConfig
     * @param \ParadoxLabs\Authnetcim\Helper\Data $dataHelper
     * @param array $methodCodes
     */
    public function __construct(
        \Magento\Payment\Model\CcConfig $ccConfig,
        \Magento\Payment\Helper\Data $paymentHelper,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Payment\Model\Config $paymentConfig,
        \ParadoxLabs\Authnetcim\Helper\Data $dataHelper,
        array $methodCodes = []
    ) {
        parent::__construct(
            $ccConfig,
            $paymentHelper,
            $checkoutSession,
            $customerSession,
            $paymentConfig,
            $dataHelper,
            $methodCodes
        );
    }

    /**
     * @return array|void
     */
    public function getConfig()
    {
        if (!$this->methods[$this->code]->isAvailable()) {
            return [];
        }

        $config             = parent::getConfig();
        $selected           = null;
        $storedCardOptions  = [];
        $cards              = $this->getStoredCards();

        /** @var \ParadoxLabs\TokenBase\Model\Card $card */
        foreach ($cards as $card) {
            $storedCardOptions[]    = [
                'id'       => $card->getHash(),
                'label'    => $card->getLabel(),
                'selected' => false,
            ];

            $selected               = $card->getHash();
        }

        $config['payment'][$this->code] = [
            'canSaveCard'               => $this->canSaveCard(),
            'forceSaveCard'             => $this->forceSaveCard(),
            'defaultSaveCard'           => $this->defaultSaveCard(),
            'storedCards'               => $storedCardOptions,
            'selectedCard'              => $selected,
            'logoImage'                 => $this->getLogoImage(),
            'achImage'                  => $this->getAchImage(),
            'achAccountTypes'           => $this->getAchAccountTypes(),
        ];

        return $config;
    }

    /**
     * Get ACH helper image
     *
     * @return string
     */
    public function getAchImage()
    {
        return $this->ccConfig->getViewFileUrl('ParadoxLabs_TokenBase::images/ach.png');
    }

    /**
     * Get ACH account types for the payment form
     *
     * @return array
     */
    private function getAchAccountTypes()
    {
        return $this->dataHelper->getAchAccountTypes();
    }
}
