<?php declare(strict_types=1);
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
 *
 * @link https://support.paradoxlabs.com
 */

namespace ParadoxLabs\Authnetcim\Model;

use ParadoxLabs\TokenBase\Model\Card;
use Magento\Customer\Model\Session;
use Magento\Framework\UrlInterface;
use Magento\Payment\Model\CcConfig;
use Magento\Payment\Model\CcGenericConfigProvider;
use Magento\Payment\Model\Config;
use ParadoxLabs\Authnetcim\Helper\Data;

class ConfigProvider extends CcGenericConfigProvider
{
    public const CODE = 'authnetcim';

    public const FORM_HOSTED = 'hosted';
    public const FORM_ACCEPTJS = 'acceptjs';
    public const FORM_INLINE = 'inline';

    /**
     * @param CcConfig $ccConfig
     * @param \Magento\Payment\Helper\Data $paymentHelper
     * @param \Magento\Checkout\Model\Session $checkoutSession *Proxy
     * @param Session $customerSession *Proxy
     * @param Config $paymentConfig
     * @param Data $dataHelper
     * @param UrlInterface $urlBuilder
     * @param array $methodCodes
     */
    public function __construct(
        CcConfig $ccConfig,
        protected readonly \Magento\Payment\Helper\Data $paymentHelper,
        protected readonly \Magento\Checkout\Model\Session $checkoutSession,
        protected readonly Session $customerSession,
        protected readonly Config $paymentConfig,
        protected readonly Data $dataHelper,
        protected readonly UrlInterface $urlBuilder,
        array $methodCodes = []
    ) {
        parent::__construct($ccConfig, $this->paymentHelper, [static::CODE]);
    }

    /**
     * Returns applicable stored cards
     *
     * @return array
     */
    public function getStoredCards()
    {
        return $this->dataHelper->getActiveCustomerCardsByMethod(static::CODE);
    }

    /**
     * If card can be saved for further use
     *
     * @return boolean
     */
    public function canSaveCard()
    {
        if ($this->customerSession->isLoggedIn()) {
            return true;
        }

        return false;
    }

    /**
     * Get checkout config.
     *
     * @return array
     */
    public function getConfig()
    {
        if (!$this->methods[ static::CODE ]->isAvailable()) {
            return [];
        }

        $config            = parent::getConfig();
        $selected          = null;
        $storedCardOptions = [];

        if ($this->canSaveCard()) {
            $cards = $this->getStoredCards();

            /** @var Card $card */
            foreach ($cards as $card) {
                $card = $card->getTypeInstance();

                $storedCardOptions[] = [
                    'id' => $card->getHash(),
                    'label' => $card->getLabel(),
                    'selected' => false,
                    'new' => $card->getLastUse() === null,
                    'type' => $card->getType(),
                    'cc_bin' => $card->getAdditional('cc_bin'),
                    'cc_last4' => $card->getAdditional('cc_last4'),
                ];

                $selected = $card->getHash();
            }
        }

        $config = array_merge_recursive($config, [
            'payment' => [
                static::CODE => [
                    'useVault' => true,
                    'canSaveCard' => $this->canSaveCard(),
                    'forceSaveCard' => $this->forceSaveCard(),
                    'defaultSaveCard' => $this->defaultSaveCard(),
                    'storedCards' => $storedCardOptions,
                    'selectedCard' => $selected,
                    'isCcDetectionEnabled' => true,
                    'logoImage' => $this->getLogoImage(),
                    'requireCcv' => $this->requireCcv(),
                    'apiLoginId' => $this->getApiLoginId(),
                    'clientKey' => $this->getClientKey(),
                    'sandbox' => $this->getSandbox(),
                    'canStoreBin' => $this->getCanStoreBin(),
                    'paramUrl' => $this->getParamUrl(),
                    'newCardUrl' => $this->getNewCardUrl(),
                ],
            ],
        ]);

        return $config;
    }

    /**
     * Whether to give customers the 'save this card' option, or just assume yes.
     *
     * @return bool
     */
    public function forceSaveCard()
    {
        return $this->methods[ static::CODE ]->getConfigData('allow_unsaved') ? false : true;
    }

    /**
     * Whether to force customers to enter CCV when using a stored card.
     *
     * @return bool
     */
    public function requireCcv()
    {
        return $this->methods[ static::CODE ]->getConfigData('require_ccv') ? true : false;
    }

    /**
     * Whether to default the save card option to yes or no.
     *
     * @return bool
     */
    public function defaultSaveCard()
    {
        return $this->methods[ static::CODE ]->getConfigData('savecard_opt_out') ? true : false;
    }

    /**
     * Get payment method logo URL (if enabled)
     *
     * @return string|false
     */
    public function getLogoImage()
    {
        if ($this->methods[ static::CODE ]->getConfigData('show_branding')) {
            return $this->ccConfig->getViewFileUrl('ParadoxLabs_Authnetcim::images/logo.png');
        }

        return false;
    }

    /**
     * Get API Login ID
     *
     * @return string
     */
    public function getApiLoginId()
    {
        return $this->methods[ static::CODE ]->getConfigData('login');
    }

    /**
     * Get Client Key - ONLY if Accept.js is enabled
     *
     * @return string
     */
    public function getClientKey()
    {
        if ($this->methods[ static::CODE ]->getConfigData('form_type') === self::FORM_ACCEPTJS) {
            return $this->methods[ static::CODE ]->getConfigData('client_key');
        }

        return '';
    }

    /**
     * Get Signature Key
     *
     * @return string
     */
    public function getSignatureKey()
    {
        return $this->methods[ static::CODE ]->getConfigData('signature_key');
    }

    /**
     * Get sandbox mode enabled flag
     *
     * @return bool
     */
    public function getSandbox()
    {
        return (bool)$this->methods[ static::CODE ]->getConfigData('test');
    }

    /**
     * Get 'can store BIN' flag
     *
     * @return bool
     */
    public function getCanStoreBin()
    {
        return (bool)$this->methods[ static::CODE ]->getConfigData('can_store_bin');
    }

    /**
     * Are webhooks active?
     *
     * @return bool
     */
    public function isWebhookEnabled(): bool
    {
        return (bool)$this->methods[ static::CODE ]->getConfigData('enable_webhooks');
    }

    /**
     * Get payment method code
     *
     * @return string
     */
    public function getCode(): string
    {
        return static::CODE;
    }

    /**
     * Get hosted form parameter URL
     *
     * @return string
     */
    public function getParamUrl(): string
    {
        if ($this->methods[ static::CODE ]->getConfigData('form_type') !== self::FORM_HOSTED) {
            return '';
        }

        if ($this->methods[ static::CODE ]->getConfigData('payment_action') === 'order') {
            return $this->urlBuilder->getUrl('authnetcim/hosted/getProfileParams', ['source' => 'checkout']);
        }

        return $this->urlBuilder->getUrl('authnetcim/hosted/getPaymentParams');
    }

    /**
     * Get Accept Customer hosted form new-card URL
     *
     * @return string
     */
    public function getNewCardUrl(): string
    {
        if ($this->methods[ static::CODE ]->getConfigData('form_type') !== self::FORM_HOSTED) {
            return '';
        }

        return $this->urlBuilder->getUrl('authnetcim/hosted/getNewCard');
    }
}
