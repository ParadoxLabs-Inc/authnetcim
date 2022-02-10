<?php
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

namespace ParadoxLabs\Authnetcim\Model\Cron;

use ParadoxLabs\Authnetcim\Model\ConfigProvider;

/**
 * AccountUpdater Class
 */
class AccountUpdater
{
    const PER_PAGE = 1000;
    const MAX_PAGES = 1000;

    /**
     * @var \ParadoxLabs\TokenBase\Helper\Data
     */
    protected $tokenbaseHelper;

    /**
     * @var \ParadoxLabs\TokenBase\Model\ResourceModel\Card\CollectionFactory
     */
    protected $cardCollectionFactory;

    /**
     * @var \ParadoxLabs\TokenBase\Api\CardRepositoryInterface
     */
    protected $cardRepository;

    /**
     * @var \ParadoxLabs\TokenBase\Model\Method\Factory
     */
    protected $methodFactory;

    /**
     * @var \ParadoxLabs\TokenBase\Api\MethodInterface
     */
    protected $method;

    /**
     * @var int
     */
    protected $updatedCount = 0;

    /**
     * AccountUpdater constructor.
     *
     * @param \ParadoxLabs\TokenBase\Helper\Data $tokenbaseHelper
     * @param \ParadoxLabs\TokenBase\Model\ResourceModel\Card\CollectionFactory $cardCollectionFactory
     * @param \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository
     * @param \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
     */
    public function __construct(
        \ParadoxLabs\TokenBase\Helper\Data $tokenbaseHelper,
        \ParadoxLabs\TokenBase\Model\ResourceModel\Card\CollectionFactory $cardCollectionFactory,
        \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository,
        \ParadoxLabs\TokenBase\Model\Method\Factory $methodFactory
    ) {
        $this->tokenbaseHelper = $tokenbaseHelper;
        $this->cardCollectionFactory = $cardCollectionFactory;
        $this->cardRepository = $cardRepository;
        $this->methodFactory = $methodFactory;
    }

    /**
     * Import Account Updater card changes for the previous month, if any.
     *
     * @return void
     */
    public function execute()
    {
        if ((int)$this->getMethod()->getConfigData('can_sync_account_updater') === 0) {
            return;
        }

        $this->tokenbaseHelper->log(
            ConfigProvider::CODE,
            'Starting AccountUpdater sync'
        );

        $this->processBatches();

        $this->tokenbaseHelper->log(
            ConfigProvider::CODE,
            sprintf('Completed AccountUpdater sync: %s cards updated', $this->updatedCount)
        );
    }

    /**
     * Process Account Updater details report batches.
     *
     * @return void
     */
    protected function processBatches()
    {
        $gateway = $this->getGateway();

        // Loop until we hit a page that's not 1000 records, or 1000 pages. We don't want to go forever.
        // This means max 1 million account updates per month, but the extreme edge cases affectd could increase that.
        $lastPageResults = static::PER_PAGE;
        for ($page = 1; $lastPageResults === static::PER_PAGE && $page < static::MAX_PAGES; $page++) {
            $details = $gateway->getAccountUpdaterDetails($page);

            $lastPageResults = (int)$details['totalNumInResultSet'];

            if (is_array($details['auDetails'])) {
                foreach ($details['auDetails'] as $type => $changes) {
                    // Each auDetails may contain one immediate record or an array of records ... handle both.
                    if (isset($changes[0])) {
                        foreach ($changes as $change) {
                            $this->processChange($type, $change);
                        }
                    } else {
                        $this->processChange($type, $changes);
                    }
                }
            }
        }
    }

    /**
     * Process an Account Updater change.
     *
     * @param string $type
     * @param array $change
     * @return void
     */
    protected function processChange($type, $change)
    {
        if ($type === 'auUpdate') {
            $this->updatePaymentProfile($change);
        } elseif ($type === 'auDelete') {
            $this->deletePaymentProfile($change);
        }
    }

    /**
     * Update any stored cards for the given Account Updater change detail.
     *
     * @param array $change
     * @return void
     */
    protected function updatePaymentProfile($change)
    {
        if (empty($change['newCreditCard'])) {
            return;
        }

        $cards = $this->loadCards($change['customerProfileID'], $change['customerPaymentProfileID']);

        if (count($cards) > 0) {
            /** @var \ParadoxLabs\Authnetcim\Model\Card $card */
            foreach ($cards as $card) {
                $changed = false;

                $last4  = substr((string)$change['newCreditCard']['cardNumber'], -4);
                if ($last4 != $card->getAdditional('cc_last4')) {
                    $card->setAdditional('cc_last4', $last4);

                    $changed = true;
                }

                if ($change['newCreditCard']['expirationDate'] !== 'XXXX') {
                    $yr = substr((string)$change['newCreditCard']['expirationDate'], 0, 4);
                    $mo = substr((string)$change['newCreditCard']['expirationDate'], -2);

                    if ($yr != $card->getAdditional('cc_exp_year')
                        || $mo != $card->getAdditional('cc_exp_month')) {
                        $day = date('t', strtotime($yr . '-' . $mo));
                        $card->setExpires(sprintf('%s-%s-%s 23:59:59', $yr, $mo, $day));

                        $card->setAdditional('cc_exp_year', $yr);
                        $card->setAdditional('cc_exp_month', $mo);

                        $changed = true;
                    }
                }

                if ($changed === true) {
                    $this->cardRepository->save($card);
                    $this->updatedCount++;
                }
            }
        }
    }

    /**
     * Delete any stored cards for the given Account Updater change detail.
     *
     * @param array $change
     * @return void
     */
    protected function deletePaymentProfile($change)
    {
        $cards = $this->loadCards($change['customerProfileID'], $change['customerPaymentProfileID']);

        if (count($cards) > 0) {
            /** @var \ParadoxLabs\Authnetcim\Model\Card $card */
            foreach ($cards as $card) {
                // Clear data to prevent deletion queuing or syncing -- the token's already gone.
                $card->setPaymentId('');
                $card->setActive(0);

                $this->cardRepository->delete($card);
                $this->updatedCount++;
            }
        }
    }

    /**
     * Get the payment method instance.
     *
     * @return \ParadoxLabs\TokenBase\Api\MethodInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getMethod()
    {
        /** @var \ParadoxLabs\Authnetcim\Model\Method $method */
        $this->method = $this->methodFactory->getMethodInstance(ConfigProvider::CODE);

        return $this->method;
    }

    /**
     * Get the payment gateway instance.
     *
     * @return \ParadoxLabs\TokenBase\Api\GatewayInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getGateway()
    {
        /** @var \ParadoxLabs\Authnetcim\Model\Gateway $gateway */
        return $this->getMethod()->gateway();
    }

    /**
     * Load any cards matching the given profile and payment IDs.
     *
     * @param int $profileId
     * @param int $paymentId
     * @return \ParadoxLabs\TokenBase\Model\ResourceModel\Card\Collection
     */
    protected function loadCards($profileId, $paymentId)
    {
        $cards = $this->cardCollectionFactory->create();
        $cards->addFieldToFilter('method', ConfigProvider::CODE);
        $cards->addFieldToFilter('profile_id', $profileId);
        $cards->addFieldToFilter('payment_id', $paymentId);

        return $cards;
    }
}
