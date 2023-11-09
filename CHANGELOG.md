# ParadoxLabs_Authnetcim Changelog

## 4.5.3 - Nov 9, 2023
- Changed CC BIN storage to enabled by default.
- Fixed payment info incorrectly persisting and preventing new card entry after a payment decline or admin reorder.
- Fixed PHP 8.2 compatibility.
- Fixed possible infinite spinner upon failure in virtual checkout.

## 4.5.2 - May 11, 2023
- Changed license from proprietary to Apache 2.0. Issues and contributions are welcome on GitHub.
- Fixed hyphenated transaction IDs possibly being sent to payment gateway on refund.
- Fixed possible Cloud deploy pipeline error from DI constants.

## 4.5.1 - March 10, 2023
- Added compatibility for Magento 2.4.6.
- Added UnionPay support.
- Changed GraphQL data assignment to allow order placement in a separate mutation. (Thanks Alfredo)
- Fixed disabled CC form fields on admin checkout.
- Fixed GraphQL tokenbase_id handling during order placement. (Thanks Damien, Tony)
- Fixed possible duplicate checkout submission by keyboard input.
- Fixed potential PHP 8.1 errors.
- Fixed potential setup errors.
- Fixed transaction being voided in error if 'quote failure' event runs despite the order saving successfully. (Thanks Michael)
- Fixed zero-total checkout handling.

## 4.5.0 - April 8, 2022
- **Removed compatibility for Magento 2.2 and below. For anyone updating from Magento 2.2 or below, update this extension to the previous version before updating Magento, then update Magento and the latest extension version.**
- Changed card pruning delay from 120 to 180 days to reflect new Authorize.net policy.
- Fixed ACH to send personal account types as PPD rather than WEB, to allow ACH refunds/reversals.
- Fixed GraphQL ordering with Accept.js not recording card last 4.
- Fixed handling of payment methods on free orders.

## 4.4.0 - February 16, 2022
- Added compatibility for Magento 2.4.4 + PHP 8.1.
- Added auto voiding of transactions at checkout when third party code throws an order processing exception.
- Added configuration to change the delay for inactive card pruning.
- Added payment_id index to stored card table to optimize duplicate card checks.
- Added security-related settings to admin checkout configuration.
- Added webhook support.
- Fixed ability to use TokenBase methods for free orders.
- Fixed Accept.js test response message escaping issue on some environments.
- Fixed ACH tooltip syntax error on My Payment Options.
- Fixed error parameter replacement on checkout for complex error messages. (Thanks Navarr)
- Fixed possible PHP notice in address input processing.

## 4.3.8 - August 23, 2021
- Fixed 'please enter CVV' validation error when capturing a card modified since order placement, with require CVV enabled.
- Fixed card info not displaying in My Payment Data on `Magento/blank` and derived themes.
- Fixed expired cards not showing any indicator.
- Fixed GraphQL card create/save not syncing to the payment gateway.
- Fixed Magento 2.4.3 compatibility by replacing all deprecated escapeQuote calls. (Magento 2.1 no longer compatible)
- Fixed post-checkout registration also catching normal customer registration, causing 'unable to load card' errors.
- Fixed transaction info not showing on admin order view on Magento 2.4.2+.

## 4.3.7 - May 17, 2021
- Fixed Visa card declines on capture for some MSPs due to incorrect COF flags in version 4.3.6.

## 4.3.6 - April 21, 2021
- Added Card-On-File API indicators to transactions for COF mandate.
- Fixed validation error after invoice.

## 4.3.5 - March 31, 2021
- Changed 'Payment Data'/'My Payment Data' to 'Payment Options'/'My Payment Options'.
- Fixed checkout validation errors on Magento 2.3.3-2.4 resulting from core bug #28161.
- Fixed errors on void/cancel if card no longer exists.
- Fixed payment failed emails.
- Fixed type validation for some legacy stored cards having full type code.
- Updated Authorize.Net logo.

## 4.3.4 - December 24, 2020
- Added selected-card data to GraphQL cart SelectedPaymentMethod.
- Fixed card association and authorization issues when changing the email on admin checkout.
- Fixed IE11 compatibility issue on checkout form.
- Fixed Magento 2.2 compatibility issue since 4.3.2 (GraphQL reference).
- Fixed payment failed emails by changing checkout exceptions from PaymentException to LocalizedException, to follow core behavior.
- Fixed server-side card type validation when using Accept.js.

## 4.3.3 - October 27, 2020
- Fixed "Credit card number does not match credit card type" on admin checkout.

## 4.3.2 - October 20, 2020
- Fixed compatibility issue with Magento 2.4.1 and Klarna 7.1.0 that broke cart and checkout.
- Fixed CSP policies for Accept.js on checkout.
- Fixed CVV type validation for stored cards.
- Fixed exceptions on void preventing order cancellation.
- Fixed GraphQL not being considered a frontend area, for client IP handling.
- Fixed stored cards syncing to gateway after refund.

## 4.3.1 - August 5, 2020
- Added CSP allowed hosts.
- Added Magento 2.4 compatibility.
- Fixed 'Invalid payment data' errors with new ACH info on multishipping checkout.
- Fixed ability to repeatedly submit checkout while the CC is being tokenized.
- Fixed admin checkout with total discounted to $0 not allowing submit until refresh.

## 4.3.0 - May 20, 2020
- Added Authorize.Net Account Updater support.
- Fixed "Email already exists" error after placing an admin order for a new customer and getting a payment failure.
- Fixed customer attributes appearing on admin edit on Magento 2.3.
- Fixed potential false positives in address change detection.
- Fixed unnecessary Authorizenet module dependency.

## 4.2.5 - January 30, 2020
- Fixed GraphQL ACH checkout.
- Fixed card association with register-after-checkout flow on recent Magento 2.2/2.3 versions.
- Fixed Magento 2.3.4 GraphQL compatibility.
- Fixed OSC compatibility issue with checkout button disabled style.
- Fixed possible JS error on card management from uninitialized validator.
- Fixed possible uncaught exception from invalid card billing address.
- Fixed potential admin card edit issues with AJAX requests failing to update the page.

## 4.2.4 - October 31, 2019
- Fixed a checkout error when Magento is configured with a database prefix.

## 4.2.3 - May 10, 2023
- Added GraphQL checkout support.
- Added store name and URL to transaction info.
- Changed duplicate transaction window from 15 to 30 seconds.
- Fixed admin card management issues.
- Fixed API card create/update with existing payment tokens.
- Fixed config Accept.js test not obeying the active config scope.
- Fixed displaying of Accept.js error responses.
- Fixed potential require.js race condition on card management.
- Fixed reserved order ID not persisting upon error for customer checkouts.

## 4.2.2 - August 29, 2019
- Fixed 'enter' submitting checkout despite disabled button.
- Fixed a PHP error on order view with Klarna enabled on Magento 2.3.
- Fixed checkout validation issues and related conflicts with some custom checkouts.
- Fixed CVV tooltip on Magento 2.3 checkout.
- Fixed fraud update for expired transactions.
- Fixed potential errors on legacy CIM card import when processing incomplete records.

## 4.2.1 - July 11, 2019
- Fixed admin order form validation issues.
- Fixed admin order submit buttons staying disabled when switching to the 'free' payment method.
- Fixed deprecated md5_hash references.
- Fixed error on settings page when changed_paths is missing on older M2 versions.
- Fixed form validation when CVV is disabled.
- Fixed fraud update processing of declined transactions.
- Fixed gateway syncing on REST card create/update.
- Fixed quality issues for latest Magento coding standards.
- Fixed unescaped output on configuration page.

## 4.2.0 - April 29, 2019
- Added Accept.js test to admin configuration.
- Added CC type detection to all payment forms.
- Added GraphQL API support for customer card management.
- Added protection to frontend checkout to help prevent abuse. (Will now block after numerous failures.)
- Added REST API support for guest and customer card management.
- Improved (completely overhauled) form processing and validation.
- Improved codebase by moving common code from gateways into the TokenBase library.
- Fixed ACH JS error on frontend card management.
- Fixed errors pulling the wrong message from API response data in certain cases.
- Fixed handling of duplicate cards within database records.
- Fixed partially-missing server-side payment validation on account payment save.
- Fixed possible errors on legacy card import for CIM stored cards with no country or state.
- Fixed possible unresolvable errors with invalid profile IDs after changing gateway accounts.
- Fixed server-side CC validator in the absence of Accept.js data.

## 4.1.4 - January 2, 2019
- Fixed missing billing address on expired transaction recaptures.
- Fixed template loading on composer installs.

## 4.1.3 - November 28, 2018
- Updated composer dependency versions for Magento 2.3.
- Fixed Magento 2.3 compatibility issue in upgrade script.

## 4.1.2 - October 5, 2018
- Added CC number input formatting.
- Fixed AFDS 'do not authorize, hold for review' response handling.
- Fixed API delete not reaching payment gateway.
- Fixed partial invoicing with reauthorization disabled.

## 4.1.1 - May 15, 2018
- Updated Authorize.Net certificate authorities for changed sandbox SSL.
- Fixed incorrect OrderCommand argument with 'save info' payment action.
- Fixed non-digit characters throwing off last4 numbers on checkout submit with Accept.js.
- Fixed possible API error with empty or extended-characters-only product names.
- Fixed possible VirtualType compilation errors.
- Fixed required indicator when phone number is set to not required.

## 4.1.0 - March 27, 2018
- Added support for $0 checkout.
- Improved currency handling.
- Improved handling of expiration date when loading cards from CIM.
- Improved performance of Manage Cards with many cards and orders (thanks Steve).
- Fixed 'Auto-select' setting on default checkout.
- Fixed 'Verify SSL' setting on Magento 2.1.9+.
- Fixed Accept.js nonce handling on payment step AJAX reload.
- Fixed field validation stripping dashes from addresses.
- Fixed logging issues in Magento 2.2.
- Fixed order status handling on 'save' payment action and some other edge cases.
- Fixed possible card update error with Accept.js in limited circumstances.
- Fixed possible unserialize address errors on 4.0 upgrade.
- Fixed possible validation JS errors on CC forms.
- Fixed shipping address not being sent on reauthorization transactions.
- Fixed stored card association on post-register checkout.
- Fixed stored card validation with no expiration date given.
  **BACKWARDS-INCOMPATIBLE CHANGES:**
- Changed param type of setMethodInstance() in ParadoxLabs\TokenBase\Api\Data\CardInterface.

## 4.0.0 - September 25, 2017
- Compatibility fixes for Magento 2.2.
- Improved API support, particularly for card create/update.
- Changed DI proxy argument handling for Magento 2.2 compatibility.
- Changed order status handling for Magento 2.2 compatibility.
- Changed payment command classnames for PHP 7.1 compatibility.
- Fixed admin card 'delete' button deleting rather than queuing deletion.
- Fixed checkout edge case with valid token not being cleared after an Accept.js validation error.
- Fixed ExtensionAttribute implementation on Card model.
- Fixed possible PHP error on admin order create in compiled multi-store environments.
- Fixed possible static content deploy issues with template comments.
- Fixed REST API permission handling.
- Fixed restricted order statuses being selectable as payment method 'New Order Status'.
  **BACKWARDS-INCOMPATIBLE CHANGES:**
- This release adds support for Magento 2.2. It is still compatible with Magento 2.0 and 2.1, but there are some notable code changes from earlier releases. If you have customizations around the extension, these may be significant:
-
- Added getAdditionalObject() to ParadoxLabs\TokenBase\Api\Data\CardInterface.
- Added saveExtended() to ParadoxLabs\TokenBase\Api\CardRepositoryInterface.
- Added CardAdditionalInterface support to ParadoxLabs\TokenBase\Model\Card::setAdditional().
- Changed argument type of ParadoxLabs\TokenBase\Api\Data\CardInterface::setExtensionAttributes().
- Changed paradoxlabs_stored_card 'address' and 'additional' fields from serialized to JSON.
- Changed Proxy constructor arguments throughout module to inject Proxy via DI configuration.
- Removed Unserialize constructor argument from ParadoxLabs\TokenBase\Model\Card\Context.

## 3.1.4 - August 7, 2017
- Added browser CC autofill attributes to form fields.
- Added protection to frontend My Payment Data page to help prevent abuse. (Will now require order history to use, and block after numerous failures.)
- Added settings check for corrupted API credentials.
- Added split database support.
- Fixed Accept.js error with CCV disabled.
- Fixed Accept.js load error with JS minify enabled.
- Fixed error on databaseless code generation.
- Fixed potential checkout error loop with Accept.js enabled and an invalid customerProfileId.
- Fixed potential error on reauthorization.
- Fixed validation error on admin checkout with new card.

## 3.1.3 - May 24, 2017
- Fixed a possible PHP error on card edit.
- Fixed Accept.js not rebinding properly, causing issues on some custom checkouts.
- Fixed admin fraud update button (workaround for a core bug).
- Fixed CCV validation for stored cards with 'Require CCV' enabled.
- Fixed compatibility with Magento Cloud Edition.
- Fixed config scope issue when checking active payment methods in admin.
- Fixed leading-zero issues on CCV input.
- Fixed multishipping checkout when adding a new card with Accept.js enabled.
- Fixed order status being overwritten after invoicing an order.
- Fixed our custom attributes being visible on customer edit form.
- Fixed payment models being shared when running multiple transactions in a single request.
- Fixed possible PHP error on checkout failure.
- Fixed possible PHP error when using specific countries setting.
- Fixed potential checkout JS errors if Accept.js is not configured/enabled.

## 3.1.2 - March 3, 2017
- Fixed errors caused by Accept.js nonce format change.

## 3.1.1 - March 2, 2017
- Fixed Magento 2.0 compatibility issues.

## 3.1.0 - February 22, 2017
- Improved code for Marketplace Level 2 validation. If you have any features built on our extension, be sure to check for compatibility issues.
- Added Accept.js support.
- Added 'save info' payment action to save payment info on checkout without authorizing or capturing funds.
- Changed profile_id, payment_id columns to varchar(32) for better gateway support.
- Fixed Card model not extending PaymentTokenInterface for Magento 2.1+.
- Fixed timezone handling in some areas.
- Fixed shipping address state not matching billing address state (full vs. abbreviation).
- Fixed a composer dependency for Magento 2.1.
- Fixed admin card management 'cancel' button, and the form not clearing after error.
- Fixed card addresses failing to resync/update on checkout.
- Fixed some forms not allowing resubmit after error.
- Fixed a card synchronization issue when a checkout error happens after updating billing address.

## 3.0.4 - October 4, 2016
- Fixed 2.1 checkout not displaying payment errors.
- Fixed CCV validation issue on multishipping checkout.
- Fixed transaction info being included on admin-triggered order emails.
- Fixed our customer attributes not saving values correctly.
- Added TokenBase card interface compatibility with Magento Vault (2.1+).

## 3.0.3 - July 22, 2016
- Compatibility fixes for Magento 2.1.
- Fixed a core bug with Magento failing to apply sort order to transactions, breaking ability to perform online partial captures.
- Fixed a potential API error.
- Fixed a card type error on multishipping checkout.
- Fixed ability to use stored ACH accounts on checkout.
- Fixed refund transactions being run as unlinked.

## 3.0.2 - May 18, 2016
- Fixed compilation errors in 2.0.6.
- Fixed AmEx cards potentially being misidentified.
- Fixed adding a new card on checkout that was previously stored failing to restore it as active.
- Fixed voiding a partially-invoiced order with reauthorization disabled potentially canceling a valid capture.
- Fixed missing error messages on checkout (workaround for apparent core issue).
- Fixed validation type setting not taking effect.
- Refactored code to ignore sales_order.ext_order_id field.

## 3.0.1 - January 26, 2016
- Added Admin Panel customer card management.
- Added basic Magento API support.
- Synced various fixes from Magento 1 to bring in line with CIM 2.2.4.
- Fixed various inspection issues.
- Fixed composer registration files.

## 3.0.0 - November 16, 2015
- Initial release for Magento 2.
