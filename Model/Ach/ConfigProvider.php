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

namespace ParadoxLabs\Authnetcim\Model\Ach;

/**
 * ConfigProvider Class
 */
class ConfigProvider extends \ParadoxLabs\Authnetcim\Model\ConfigProvider
{
    const CODE = 'authnetcim_ach';

    /**
     * Get checkout config.
     *
     * @return array
     */
    public function getConfig()
    {
        if (!$this->methods[static::CODE]->isAvailable()) {
            return [];
        }

        $config             = parent::getConfig();
        $selected           = null;
        $storedCardOptions  = [];
        $cards              = $this->getStoredCards();

        /** @var \ParadoxLabs\TokenBase\Model\Card $card */
        foreach ($cards as $card) {
            $card = $card->getTypeInstance();

            $storedCardOptions[]    = [
                'id'       => $card->getHash(),
                'label'    => $card->getLabel(),
                'selected' => false,
                'new'      => $card->getLastUse() === null,
                'type'     => $card->getType(),
                'cc_bin'   => $card->getAdditional('cc_bin'),
                'cc_last4' => $card->getAdditional('cc_last4'),
            ];

            $selected               = $card->getHash();
        }

        $config['payment'][static::CODE] = [
            'canSaveCard'               => $this->canSaveCard(),
            'forceSaveCard'             => $this->forceSaveCard(),
            'defaultSaveCard'           => $this->defaultSaveCard(),
            'storedCards'               => $storedCardOptions,
            'selectedCard'              => $selected,
            'logoImage'                 => $this->getLogoImage(),
            'achImage'                  => $this->getAchImage(),
            'achAccountTypes'           => $this->getAchAccountTypes(),
            'requireCcv'                => false,
            'formType'                  => $this->methods[static::CODE]->getConfigData('form_type'),
            'paramUrl'                  => $this->urlBuilder->getUrl('authnetcim/hosted/getParams'),
            'newCardUrl'                => $this->urlBuilder->getUrl('authnetcim/hosted/getNewCard'),
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
    protected function getAchAccountTypes()
    {
        return $this->dataHelper->getAchAccountTypes();
    }
}
