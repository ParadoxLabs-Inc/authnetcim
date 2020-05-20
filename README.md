# Authorize.Net CIM for Magento 2
Payment method for Magento 2 by ParadoxLabs

## Description

Authorize.Net's Customer Information Manager (CIM) is a service that allows you to store customer payment information on Authorize.Net's secure servers. This gives you all the convenience of stored credit cards, with all the safety of Authorize.Net.

For full product details, please [visit our website](https://store.paradoxlabs.com/magento2-authorize-net-cim-payment-module.html).


## Terms of Use

Find the license agreement online at [store.paradoxlabs.com/license.html](http://store.paradoxlabs.com/license.html).


## To Install

### Step 1: Upload files

Upload all files from `upload` into the base folder of your Magento 2 installation. Files within the `app` folder should be added to the ones you already have.

### Step 2: Run Installation

In SSH, from your site root, run the command: `php bin/magento setup:upgrade`

That will flush the cache, and trigger the installation process to run.

### Step 3: Configure the Payment Module

**Before proceeding: Sign up for an [Authorize.Net merchant account](https://ems.authorize.net/oap/home.aspx?SalesRepID=98&ResellerID=24716) if you have not done so, and ensure your account has Customer Information Manager (CIM) enabled.**

Open your Admin Panel and go to **Stores > Settings > Configuration > Sales > Payment Methods**. If the extension was uploaded correctly, you will see a new setting section near the bottom titled **Authorize.Net CIM**. Enter your **API Login ID** and **Transaction Key** as found in your Authorize.Net account, and complete the rest of the settings. Once you're done, click **Save Config** to save the changes.

After saving, if the API connection is working properly, the 'API Test Results' setting will display "Authorize.Net CIM connected successfully." in green.

### Step 4: Profit

That's it! Test it out, and let us know if you have any problems.


## To Update

You will be notified of any updates in your Magento Admin Panel. [Log in to our store](https://store.paradoxlabs.com/downloadable/customer/products/) to download.

### Step 1: Upload files

Upload all files from `upload` into the base folder of your Magento 2 installation. Files within the `app` folder should overwrite the ones you already have.

### Step 2: Run Installation

In SSH, from your site root, run the command: `php bin/magento setup:upgrade`

That will flush the cache, and trigger the update process to run.

### Step 3: Verify Changes

If you have any integrations or custom functionality based on this extension, we strongly recommend testing to ensure they are not affected. If you would like details on changes beyond what is provided in the release notes, you can run a diff between versions or contact us for specifics.

**If you have modified our template files in any theme**, ensure that any changes to the base templates are reflected there. Failing to do this may result in errors during checkout or card management.


## Need Help?

**Support System: [support.paradoxlabs.com](http://support.paradoxlabs.com)**


© 2020 [ParadoxLabs](http://www.paradoxlabs.com)
