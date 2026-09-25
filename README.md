[![Latest Stable Version](https://poser.pugx.org/paradoxlabs/authnetcim/v/stable)](https://packagist.org/packages/paradoxlabs/authnetcim)
[![License](https://poser.pugx.org/paradoxlabs/authnetcim/license)](https://packagist.org/packages/paradoxlabs/authnetcim)
[![Total Downloads](https://poser.pugx.org/paradoxlabs/authnetcim/downloads)](https://packagist.org/packages/paradoxlabs/authnetcim)

<p align="center">
    <a href="https://www.paradoxlabs.com"><img alt="ParadoxLabs" src="https://paradoxlabs.com/wp-content/uploads/2020/02/pl-logo-canva-2.png" width="250"></a>
</p>

Authorize.net is one of the world's largest payment gateways, serving over 400,000 merchants. Their services allow you
to accept payment from your customers, by credit card or eCheck, straight from your website. You must have an
Authorize.net account to use this extension (account fees will vary).

This extension brings
Authorize.net's [Customer Information Manager (CIM)](https://support.authorize.net/s/article/What-Is-the-Customer-Information-Manager-CIM)
service to Magento 2. Authorize.net CIM takes payment processing to a whole new level, by allowing your customers to
store their payment info on Authorize.net's secure servers. This gives you and your customers the convenience of stored
credit cards, with all the security of Authorize.net. It also allows us to give you many advanced features that most
payment methods aren't capable of.

For full product details,
please [visit our website](https://store.paradoxlabs.com/magento2-authorize-net-cim-payment-module.html).

Don't have an Authorize.net account yet? [Sign up now](http://reseller.authorize.net/application/?resellerId=24716)

Requirements
============

* Adobe Commerce / Magento Open Source 2.4.6 – 2.4.9 (or equivalent version of Adobe Commerce Cloud), or Mage-OS 2.0 – 3.0
* PHP 8.1, 8.2, 8.3, 8.4, or 8.5
* composer 2

Features
========

* Pay by credit card or ACH (eCheck)
* Pay with Apple Pay or Google Pay (Luma, Hyvä Checkout, and GraphQL)
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
* Supports
  ParadoxLabs [Adaptive Subscriptions](https://store.paradoxlabs.com/magento2-subscriptions-recurring-billing.html)
  extension

Installation and Usage
======================

In SSH at your Magento base directory, run:

    composer require paradoxlabs/authnetcim
    php bin/magento module:enable ParadoxLabs_Authnetcim ParadoxLabs_TokenBase
    php bin/magento setup:upgrade

**Before proceeding: Sign up for
an [Authorize.net merchant account](https://ems.authorize.net/oap/home.aspx?SalesRepID=98&ResellerID=24716) if you have
not done so, and ensure your account has Customer Information Manager (CIM) enabled.**

Open your Admin Panel and go to **Stores > Settings > Configuration > Sales > Payment Methods**. If the extension
installed correctly, you will see a new setting section near the bottom titled **Authorize.net CIM**. Enter your **API
Login ID** and **Transaction Key** as found in your Authorize.net account, and complete the rest of the settings. Once
you're done, click **Save Config** to save the changes.

After saving, if the API connection is working properly, the 'API Test Results' setting will display "Authorize.net CIM
connected successfully." in green.

## Apple Pay and Google Pay

Apple Pay and Google Pay are a separate payment method (code `authnetcim_wallet`) that runs through your Authorize.net
CIM account. It is installed with the extension and stays disabled until configured. It uses the API credentials,
sandbox setting, card types, and line item settings from the **Authorize.net CIM** section, so configure that first.

Settings are under **Stores > Settings > Configuration > Sales > Payment Methods > Authorize.net CIM - Apple Pay /
Google Pay**. Set **Enabled** to Yes, then enable and configure each wallet you want to offer. A wallet is only
offered once all of its required settings are filled in.

### Authorize.net prerequisites

* Enable each wallet in the Authorize.net Merchant Interface under **Account > Digital Payment Solutions**.
* Apple Pay is only available if your Authorize.net processor supports it. See Authorize.net's list of Apple Pay
  processors, or ask Authorize.net, before setting it up.

### Google Pay

1. Enable Google Pay in Authorize.net under **Account > Digital Payment Solutions > Google Pay**.
2. **Authorize.net Payment Gateway ID**: enter the numeric Payment Gateway ID shown on your Authorize.net Merchant
   Interface home page. **This is not your API Login ID.** The API Login ID will not work here.
3. **Google Merchant ID**: required for live payments. Register your business in the Google Pay & Wallet Console
   (formerly the Google Pay Business Console), complete Google's integration review, and enter the merchant ID it
   gives you. Google Pay is not offered in live mode until this is set.
4. The Google Pay environment follows the Authorize.net CIM sandbox setting: sandbox uses Google's TEST environment
   (no Google Merchant ID needed), live uses PRODUCTION.

**Allowed Card Authentication** controls whether Google Pay offers cards saved in the Google account (`PAN_ONLY`),
device tokens (`CRYPTOGRAM_3DS`), or both. Allowing both is recommended in the US.

### Apple Pay

1. In your Apple Developer account, create a **Merchant ID** (for example `merchant.com.example.store`), and enter it
   as **Apple Merchant ID**.
2. In Authorize.net under **Account > Digital Payment Solutions > Apple Pay**, enter the same Merchant ID. Upload the
   Payment Processing certificate signing request that Authorize.net generates to Apple. Authorize.net holds that
   certificate; you do not enter it in Magento.
3. In Apple Developer, create a **Merchant Identity certificate** for the Merchant ID. Paste the certificate, in PEM
   format (`-----BEGIN CERTIFICATE-----`), into **Merchant Identity Certificate (PEM)**, and its unencrypted private key
   (`-----BEGIN PRIVATE KEY-----`) into **Merchant Identity Private Key (PEM)**. The private key is stored encrypted.
   Apple issues the certificate as a `.cer` file; convert it with
   `openssl x509 -inform der -in merchant_id.cer -out merchant_id.pem`.
4. In Apple Developer, register each storefront domain for the Merchant ID. Paste the contents of the
   `apple-developer-merchantid-domain-association` file Apple gives you into **Domain Verification File Contents**,
   save, and then verify the domain with Apple. Magento serves the file at
   `/.well-known/apple-developer-merchantid-domain-association`. If a file already exists at that path in your web
   root, the web server serves that file instead, so update or remove it. If your web server does not pass that path
   to Magento, put the file at `pub/.well-known/apple-developer-merchantid-domain-association` instead.
5. **Headless Storefront Domains**: headless/PWA storefronts only. Enter the storefront domains (comma-separated) that
   are allowed to request Apple Pay merchant validation through GraphQL. Leave blank for standard Magento storefronts.

Apple Pay is only shown in browsers and devices that support it, such as Safari on iPhone, iPad, and Mac, with a card
in Apple Wallet.

### How wallet payments work

* Wallet payments are single-use tokens. No CIM profile or stored card is created, so a wallet payment cannot be
  reused for reorders, subscriptions, or order edits.
* **Payment Action** 'Authorize' allows one capture per authorization: partial invoicing is not supported, and an
  expired authorization cannot be reauthorized. 'Capture' charges the order immediately.
* Refunds are made online by credit memo. A full refund of a transaction that has not settled yet is voided instead.
  A partial refund must wait until the transaction settles.
* The wallet payment sheet shows the order total in the store's base currency, since that is the amount
  Authorize.net charges.
* If the customer's browser has no usable wallet, Luma checkout hides the payment method, and Hyvä Checkout shows a
  message asking the customer to choose another payment method.
* Wallet payments are not available when creating orders in the admin panel.
* Transactions held for review by Advanced Fraud Detection Suite can be accepted or denied from the order as usual.
  The card webhook endpoint also handles wallet transactions.
* The Apple Pay and Google Pay scripts are added to the Content Security Policy automatically when a wallet is enabled.

### Hyvä Checkout and GraphQL

* Hyvä Checkout: install ParadoxLabs_AuthnetcimHyvaCheckout (`paradoxlabs/authnetcim-hyva-checkout`) version 3.2 or
  later.
* GraphQL / headless:
  * Query `authnetcimWalletConfig(cart_id)` returns the wallet settings and base-currency total for a cart.
  * Mutation `authnetcimApplePayValidateMerchant` returns the Apple Pay merchant session for a storefront domain.
  * Place the order with payment method `authnetcim_wallet` and `PaymentMethodInput.authnetcim_wallet`
    (`wallet_type`, `wallet_token`, optional `card_display`). See the field descriptions in `etc/schema.graphqls`
    for the token encoding.

## Applying Updates

In SSH at your Magento base directory, run:

    composer update paradoxlabs/authnetcim
    php bin/magento setup:upgrade

These commands will download and apply any available updates to the module.

If you have any integrations or custom functionality based on this extension, we strongly recommend testing to ensure
they are not affected.

**If you have modified the template or JS files in any theme**, be sure to update them to match any changes in the
extension. Failing to do this may result in errors during checkout or card management.

Changelog
=========

Please see [CHANGELOG.md](https://github.com/ParadoxLabs-Inc/authnetcim/blob/master/CHANGELOG.md).

Support
=======

This module is provided free and without support of any kind. You may report issues you've found in the module, and we
will address them as we are able, but **no support will be provided here.**

**DO NOT include any API keys, credentials, or customer-identifying in issues, pull requests, or comments. Any
personally identifying information will be deleted on sight.**

If you need personal support services,
please [buy an extension support plan from ParadoxLabs](https://store.paradoxlabs.com/support-renewal.html), then open a
ticket at [support.paradoxlabs.com](https://support.paradoxlabs.com).

Contributing
============

Please feel free to submit pull requests with any contributions. We welcome and appreciate your support, and will
acknowledge contributors.

This module is maintained by ParadoxLabs, a Magento solutions provider. We make no guarantee of accepting contributions,
especially any that introduce architectural changes.

License
=======

This module is licensed
under [APACHE LICENSE, VERSION 2.0](https://github.com/ParadoxLabs-Inc/authnetcim/blob/master/LICENSE).
