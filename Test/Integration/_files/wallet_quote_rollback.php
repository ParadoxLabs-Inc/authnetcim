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
 *
 * @link https://support.paradoxlabs.com
 */

declare(strict_types=1);

use Magento\Framework\Registry;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ResourceModel\Quote\CollectionFactory as QuoteCollectionFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Workaround\Override\Fixture\Resolver;

$objectManager = Bootstrap::getObjectManager();

/** @var Registry $registry */
$registry = $objectManager->get(Registry::class);
$registry->unregister('isSecureArea');
$registry->register('isSecureArea', true);

// The cart is meant to be placed, so the order it becomes has to go too.
/** @var OrderCollectionFactory $orderCollectionFactory */
$orderCollectionFactory = $objectManager->get(OrderCollectionFactory::class);
$orders = $orderCollectionFactory->create()
    ->addFieldToFilter('increment_id', 'test_wallet_quote');

/** @var Order $order */
foreach ($orders as $order) {
    $order->delete();
}

/** @var QuoteCollectionFactory $quoteCollectionFactory */
$quoteCollectionFactory = $objectManager->get(QuoteCollectionFactory::class);
$quotes = $quoteCollectionFactory->create()
    ->addFieldToFilter('reserved_order_id', 'test_wallet_quote');

/** @var Quote $quote */
foreach ($quotes as $quote) {
    $quote->delete();
}

$registry->unregister('isSecureArea');

Resolver::getInstance()->requireDataFixture(
    'Magento/Catalog/_files/product_without_options_with_stock_data_rollback.php'
);
