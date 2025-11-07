# flagship-for-prestashop

PrestaShop module that allows you to ship with FlagShip. We strongly recommend you to use the latest version of PrestaShop.


NOTE: FlagShip for Prestashop requires you to increase PHP upload_max_filesize to at least 3M. This can be done in your php.ini. Based on your server, the location of this file may vary. For apache, the file can be found at /etc/php/apache2/php.ini. For nginx, it is at /etc/php/7.4/fpm/php.ini

# Requirements

We recommend using the latest version of PHP. The minimum requirement is PHP 7.4

# Compatibility and support

Compatible with PrestaShop 1.7.x and Prestashop 8. In case of any issues, please send an email to developers@flagshipcompany.com

# Installation

## Module Upload

Login to PrestaShop Admin

Modules > Module Manager > Upload a module (top right)

Upload flagship-for-prestashop.zip provided above

## Composer

````
cd <PATH_TO_PRESTASHOP_INSTALLATION_DIR>/modules
composer create-project flagshipcompany/flagship-for-prestashop flagshipshipping
````
## Manual
Download the module from github, unzip the archive and move it to @Prestashop/modules/.

````
unzip flagship-for-prestashop.zip
mv flagship-for-prestashop flagshipshipping
cp -r flagshipshipping <PATH_TO_PRESTASHOP_INSTALLATION_DIR>/modules/
cd <PATH_TO_PRESTASHOP_INSTALLATION_DIR>/modules/flagshipshipping
composer install
````

Login to PrestaShop Admin.

Navigate to Modules > Module Catalog

Search for Flagship and click on install.

# Usage

Make sure store address is set. To set this,

Login to PrestaShop Admin > Configure > Shop Parameters > Contact > Select Stores Tab > Scroll down to Contact details and save address.

![Image of Contact Details](https://github.com/flagshipcompany/flagship-for-prestashop/blob/master/views/img/contact.png)

Configure the module. Set API token, markup percentage and handling fee. Add dimensions for shipping boxes here.

![Image of Configuration](https://github.com/flagshipcompany/flagship-for-prestashop/blob/master/views/img/configuration.png)

Customer can select the shipping method.

![Image of Rates](https://github.com/flagshipcompany/flagship-for-prestashop/blob/master/views/img/rates.png)

Admin gets an option to Send orders to FlagShip.


To change the transit time for a carrier
![Image of Edit Carrier](https://github.com/flagshipcompany/flagship-for-prestashop/blob/master/views/img/editCarrier.jpg)

![Image of Transit Time](https://github.com/flagshipcompany/flagship-for-prestashop/blob/master/views/img/editCarrierTransitTime.jpg)

![Image of Transit Time Changed](https://github.com/flagshipcompany/flagship-for-prestashop/blob/master/views/img/editCarrierTransitTimeChanged.jpg)

## Diagnosing missing carriers

1. From your PrestaShop root, run `php modules/flagshipshipping/tools/flagship_diag/rate_check.php --cart-id=<ID>` (add `--address-id=<ID>` to override the delivery address). The script bootstraps PrestaShop, reuses the module’s checkout payload builder, and POSTs the exact JSON that checkout uses to `/ship/rates`.
2. Review the printed HTTP status. `200` or `206` indicates a successful response; any other status means SmartShip rejected the payload and you should correct the reported error before retrying.
3. Inspect the pretty-printed payload to confirm `from.state`/`to.state` are 2-letter ISO codes, `suite` fields match the second address line, and every package item shows decimal length/width/height/weight with `units` forced to `imperial`.
4. Check the “Rates” section for a courier/service ⇒ total summary. If it’s empty, look at the “Errors” section (or your PrestaShop logs when HTTP `206`) for courier-specific reasons that can hide carriers at checkout.
5. After making adjustments (address data, package dimensions, configuration flags), rerun the diagnostic to confirm carriers return as expected before retesting on the storefront.
