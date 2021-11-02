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

namespace ParadoxLabs\Authnetcim\Model;

use Magento\Payment\Model\CcConfig;
use Magento\Payment\Model\CcGenericConfigProvider;

/**
 * ConfigProvider Class
 */
class ConfigProvider extends CcGenericConfigProvider
{
    const CODE = 'authnetcim';

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;

    /**
     * @var \Magento\Customer\Model\Session
     */
    protected $customerSession;

    /**
     * @var \ParadoxLabs\Authnetcim\Helper\Data
     */
    protected $dataHelper;

    /**
     * @var \Magento\Payment\Helper\Data
     */
    protected $paymentHelper;

    /**
     * @var \Magento\Payment\Model\Config
     */
    protected $paymentConfig;

    /**
     * @param CcConfig $ccConfig
     * @param \Magento\Payment\Helper\Data $paymentHelper
     * @param \Magento\Checkout\Model\Session $checkoutSession *Proxy
     * @param \Magento\Customer\Model\Session $customerSession *Proxy
     * @param \Magento\Payment\Model\Config $paymentConfig
     * @param \ParadoxLabs\Authnetcim\Helper\Data $dataHelper
     * @param array $methodCodes
     */
    public function __construct(
        CcConfig $ccConfig,
        \Magento\Payment\Helper\Data $paymentHelper,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Payment\Model\Config $paymentConfig,
        \ParadoxLabs\Authnetcim\Helper\Data $dataHelper,
        array $methodCodes = []
    ) {
        $this->paymentHelper    = $paymentHelper;
        $this->checkoutSession  = $checkoutSession;
        $this->customerSession  = $customerSession;
        $this->dataHelper       = $dataHelper;
        $this->paymentConfig    = $paymentConfig;

        parent::__construct($ccConfig, $paymentHelper, [static::CODE]);
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
        if (!$this->methods[static::CODE]->isAvailable()) {
            return [];
        }

        $config             = parent::getConfig();
        $selected           = null;
        $storedCardOptions  = [];
        
        if ($this->canSaveCard()) {
            $cards              = $this->getStoredCards();

            /** @var \ParadoxLabs\TokenBase\Model\Card $card */
            foreach ($cards as $card) {
                $card = $card->getTypeInstance();

                $storedCardOptions[]    = [
                    'id'       => $card->getHash(),
                    'label'    => $card->getLabel(),
                    'selected' => false,
                    'type'     => $card->getType(),
                ];

                $selected               = $card->getHash();
            }
        }

        $config = array_merge_recursive($config, [
            'payment' => [
                static::CODE => [
                    'useVault'                => true,
                    'canSaveCard'             => $this->canSaveCard(),
                    'forceSaveCard'           => $this->forceSaveCard(),
                    'defaultSaveCard'         => $this->defaultSaveCard(),
                    'storedCards'             => $storedCardOptions,
                    'selectedCard'            => $selected,
                    'isCcDetectionEnabled'    => true,
                    'logoImage'               => $this->getLogoImage(),
                    'requireCcv'              => $this->requireCcv(),
                    'apiLoginId'              => $this->getApiLoginId(),
                    'clientKey'               => $this->getClientKey(),
                    'sandbox'                 => $this->getSandbox(),
                    'canStoreBin'             => $this->getCanStoreBin(),
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
        return $this->methods[static::CODE]->getConfigData('allow_unsaved') ? false : true;
    }

    /**
     * Whether to force customers to enter CCV when using a stored card.
     *
     * @return bool
     */
    public function requireCcv()
    {
        return $this->methods[static::CODE]->getConfigData('require_ccv') ? true : false;
    }

    /**
     * Whether to default the save card option to yes or no.
     *
     * @return bool
     */
    public function defaultSaveCard()
    {
        return $this->methods[static::CODE]->getConfigData('savecard_opt_out') ? true : false;
    }

    /**
     * Get payment method logo URL (if enabled)
     *
     * @return string|false
     */
    public function getLogoImage()
    {
        if ($this->methods[static::CODE]->getConfigData('show_branding')) {
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
        return $this->methods[static::CODE]->getConfigData('login');
    }

    /**
     * Get Client Key - ONLY if Accept.js is enabled
     *
     * @return string
     */
    public function getClientKey()
    {
        if ($this->methods[static::CODE]->getConfigData('acceptjs')) {
            return $this->methods[static::CODE]->getConfigData('client_key');
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
        return $this->methods[static::CODE]->getConfigData('signature_key');
    }

    /**
     * Get sandbox mode enabled flag
     *
     * @return bool
     */
    public function getSandbox()
    {
        return (bool)$this->methods[static::CODE]->getConfigData('test');
    }

    /**
     * Get 'can store BIN' flag
     *
     * @return bool
     */
    public function getCanStoreBin()
    {
        return (bool)$this->methods[static::CODE]->getConfigData('can_store_bin');
    }

    /**
     * Are webhooks active?
     *
     * @return bool
     */
    public function isWebhookEnabled(): bool
    {
        return (bool)$this->methods[static::CODE]->getConfigData('enable_webhooks');
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
}
