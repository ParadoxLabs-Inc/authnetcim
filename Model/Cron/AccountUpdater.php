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

namespace ParadoxLabs\Authnetcim\Model\Cron;

use ParadoxLabs\TokenBase\Api\MethodInterface;
use ParadoxLabs\Authnetcim\Model\Card;
use Magento\Framework\Exception\LocalizedException;
use ParadoxLabs\Authnetcim\Model\Method;
use ParadoxLabs\TokenBase\Api\GatewayInterface;
use ParadoxLabs\Authnetcim\Model\Gateway;
use ParadoxLabs\TokenBase\Model\ResourceModel\Card\Collection;
use ParadoxLabs\Authnetcim\Model\ConfigProvider;
use ParadoxLabs\TokenBase\Api\CardRepositoryInterface;
use ParadoxLabs\TokenBase\Helper\Data;
use ParadoxLabs\TokenBase\Model\Method\Factory;
use ParadoxLabs\TokenBase\Model\ResourceModel\Card\CollectionFactory;

class AccountUpdater
{
    const PER_PAGE = 1000;
    const MAX_PAGES = 1000;

    /**
     * @var MethodInterface
     */
    protected $method;

    /**
     * @var int
     */
    protected $updatedCount = 0;

    /**
     * AccountUpdater constructor.
     *
     * @param Data $tokenbaseHelper
     * @param \ParadoxLabs\TokenBase\Model\ResourceModel\Card\CollectionFactory $cardCollectionFactory
     * @param CardRepositoryInterface $cardRepository
     * @param Factory $methodFactory
     */
    public function __construct(
        protected readonly Data $tokenbaseHelper,
        protected readonly CollectionFactory $cardCollectionFactory,
        protected readonly CardRepositoryInterface $cardRepository,
        protected readonly Factory $methodFactory
    ) {
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
            /** @var Card $card */
            foreach ($cards as $card) {
                $changed = false;

                $last4 = substr((string)$change['newCreditCard']['cardNumber'], -4);
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
            /** @var Card $card */
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
     * @return MethodInterface
     * @throws LocalizedException
     */
    protected function getMethod()
    {
        /** @var Method $method */
        $this->method = $this->methodFactory->getMethodInstance(ConfigProvider::CODE);

        return $this->method;
    }

    /**
     * Get the payment gateway instance.
     *
     * @return GatewayInterface
     * @throws LocalizedException
     */
    protected function getGateway()
    {
        /** @var Gateway $gateway */
        return $this->getMethod()->gateway();
    }

    /**
     * Load any cards matching the given profile and payment IDs.
     *
     * @param int $profileId
     * @param int $paymentId
     * @return Collection
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
