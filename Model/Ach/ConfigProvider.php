<?php
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

namespace ParadoxLabs\Authnetcim\Model\Ach;

/**
 * ConfigProvider Class
 */
class ConfigProvider extends \ParadoxLabs\Authnetcim\Model\ConfigProvider
{
    public const CODE = 'authnetcim_ach';

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
            'paramUrl'                  => $this->getParamUrl(),
            'newCardUrl'                => $this->getNewCardUrl(),
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
