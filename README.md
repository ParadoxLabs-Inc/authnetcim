[![Latest Stable Version](https://poser.pugx.org/paradoxlabs/authnetcim/v/stable)](https://packagist.org/packages/paradoxlabs/authnetcim)
[![License](https://poser.pugx.org/paradoxlabs/authnetcim/license)](https://packagist.org/packages/paradoxlabs/authnetcim)
[![Total Downloads](https://poser.pugx.org/paradoxlabs/authnetcim/downloads)](https://packagist.org/packages/paradoxlabs/authnetcim)

<p align="center">
    <a href="https://www.paradoxlabs.com"><img alt="ParadoxLabs" src="https://paradoxlabs.com/wp-content/uploads/2020/02/pl-logo-canva-2.png" width="250"></a>
</p>

Authorize.net is one of the world's largest payment gateways, serving over 400,000 merchants. Their services allow you to accept payment from your customers, by credit card or eCheck, straight from your website. You must have an Authorize.net account to use this extension (account fees will vary).

This extension brings Authorize.net's [Customer Information Manager (CIM)](https://support.authorize.net/s/article/What-Is-the-Customer-Information-Manager-CIM) service to Magento 2. Authorize.net CIM takes payment processing to a whole new level, by allowing your customers to store their payment info on Authorize.net's secure servers. This gives you and your customers the convenience of stored credit cards, with all the security of Authorize.net. It also allows us to give you many advanced features that most payment methods aren't capable of.

For full product details, please [visit our website](https://store.paradoxlabs.com/magento2-authorize-net-cim-payment-module.html).

Don't have an Authorize.net account yet? [Sign up now](http://reseller.authorize.net/application/?resellerId=24716)

Requirements
============

* Magento 2.3 or 2.4 (or equivalent version of Adobe Commerce, Adobe Commerce Cloud, or Mage-OS)
* PHP 7.3, 7.4, 8.0, 8.1, or 8.2
* composer 1 or 2

Features
========

* Pay by credit card or ACH (eCheck)
* Save credit cards (tokens) for reuse
* Add, edit, and delete saved payment data
* Edit orders and reorder, without having to ask the customer for CC info again
* Authorize, Capture, or Save CC Info (without charging) at time of checkout
* Capture funds even after the authorization expires
* Partially invoice orders (including reauthorization on partial invoice)
* Partially refund (online credit memo)
* Send shipping address and line items to Authorize.net
* Require CCV code when adding a card, or with every purchase
* Validate billing address with Address Verification (AVS)
* Update stored cards automatically with Account Updater
* Protect against fraud with Advanced Fraud Detection Suite (AFDS) and hold-for-review
* Integrate your systems thanks to Magento API support
* Use a different Authorize.net account for each website (multi-store support)
* Supports ParadoxLabs [Adaptive Subscriptions](https://store.paradoxlabs.com/magento2-subscriptions-recurring-billing.html) extension

Installation and Usage
======================

In SSH at your Magento base directory, run:

    composer require paradoxlabs/authnetcim
    php bin/magento module:enable ParadoxLabs_Authnetcim ParadoxLabs_TokenBase
    php bin/magento setup:upgrade

**Before proceeding: Sign up for an [Authorize.net merchant account](https://ems.authorize.net/oap/home.aspx?SalesRepID=98&ResellerID=24716) if you have not done so, and ensure your account has Customer Information Manager (CIM) enabled.**

Open your Admin Panel and go to **Stores > Settings > Configuration > Sales > Payment Methods**. If the extension installed correctly, you will see a new setting section near the bottom titled **Authorize.net CIM**. Enter your **API Login ID** and **Transaction Key** as found in your Authorize.net account, and complete the rest of the settings. Once you're done, click **Save Config** to save the changes.

After saving, if the API connection is working properly, the 'API Test Results' setting will display "Authorize.net CIM connected successfully." in green.

## Applying Updates

In SSH at your Magento base directory, run:

    composer update paradoxlabs/authnetcim
    php bin/magento setup:upgrade

These commands will download and apply any available updates to the module.

If you have any integrations or custom functionality based on this extension, we strongly recommend testing to ensure they are not affected.

**If you have modified the template or JS files in any theme**, be sure to update them to match any changes in the extension. Failing to do this may result in errors during checkout or card management.

Changelog
=========

Please see [CHANGELOG.md](https://github.com/ParadoxLabs-Inc/authnetcim/blob/master/CHANGELOG.md).

Support
=======

This module is provided free and without support of any kind. You may report issues you've found in the module, and we will address them as we are able, but **no support will be provided here.**

**DO NOT include any API keys, credentials, or customer-identifying in issues, pull requests, or comments. Any personally identifying information will be deleted on sight.**

If you need personal support services, please [buy an extension support plan from ParadoxLabs](https://store.paradoxlabs.com/support-renewal.html), then open a ticket at [support.paradoxlabs.com](https://support.paradoxlabs.com).

Contributing
============

Please feel free to submit pull requests with any contributions. We welcome and appreciate your support, and will acknowledge contributors.

This module is maintained by ParadoxLabs, a Magento solutions provider. We make no guarantee of accepting contributions, especially any that introduce architectural changes.

License
=======

This module is licensed under [APACHE LICENSE, VERSION 2.0](https://github.com/ParadoxLabs-Inc/authnetcim/blob/master/LICENSE).
