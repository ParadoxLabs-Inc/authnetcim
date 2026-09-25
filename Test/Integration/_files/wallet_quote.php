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

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Workaround\Override\Fixture\Resolver;

/**
 * A guest cart ready to place with Apple Pay / Google Pay (authnetcim_wallet): two units of a $10 simple product,
 * US billing/shipping addresses, and flat rate shipping. No payment is set; each test posts its own wallet payment
 * data, as the checkout client would. The order it becomes takes the reserved increment ID 'test_wallet_quote'.
 */

Resolver::getInstance()->requireDataFixture(
    'Magento/Catalog/_files/product_without_options_with_stock_data.php'
);

$objectManager = Bootstrap::getObjectManager();

/** @var ProductRepositoryInterface $productRepository */
$productRepository = $objectManager->create(ProductRepositoryInterface::class);
$product = $productRepository->get('simple');

$addressData = [
    'firstname' => 'Wallet',
    'lastname' => 'Tester',
    'street' => "123 Wallet Street\n",
    'city' => 'Los Angeles',
    'region' => 'California',
    'region_id' => '12',
    'postcode' => '90210',
    'country_id' => 'US',
    'telephone' => '3105551234',
    'email' => 'wallet-checkout@example.com',
];

/** @var Quote $quote */
$quote = $objectManager->create(Quote::class);
$quote->setStoreId($objectManager->get(StoreManagerInterface::class)->getStore()->getId())
    ->setIsActive(true)
    ->setIsMultiShipping(false)
    ->setCustomerIsGuest(true)
    ->setCustomerEmail($addressData['email'])
    ->setReservedOrderId('test_wallet_quote');

$quote->getBillingAddress()->addData($addressData);
$quote->getShippingAddress()->addData($addressData);

$quote->addProduct($product, 2);

$quote->getShippingAddress()
    ->setCollectShippingRates(true)
    ->collectShippingRates()
    ->setShippingMethod('flatrate_flatrate');

$quote->collectTotals();

/** @var CartRepositoryInterface $cartRepository */
$cartRepository = $objectManager->get(CartRepositoryInterface::class);
$cartRepository->save($quote);
