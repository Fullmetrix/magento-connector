# Fullmetrix for Magento 2

Official Fullmetrix module for Magento 2 and Adobe Commerce. It connects your store to [Fullmetrix](https://fullmetrix.com), the analytics, segmentation and marketing platform for ecommerce.

Compatible with Magento 2.4.4 or later and Adobe Commerce, PHP 8.1 or later. The Magento cron must be installed.

## Installation

```bash
composer require fullmetrix/magento-connector
bin/magento module:enable Fullmetrix_Connector
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy
bin/magento cache:flush
```

In production mode, `setup:upgrade` clears `pub/static`: always run `setup:static-content:deploy` (with your store locales, for example `fr_FR en_US`) before `cache:flush`, for an installation and for every update. Without it the store answers 500.

## Connection

In the admin, go to Marketing, then Fullmetrix, and enter the connection code shown in Fullmetrix. From the command line:

```bash
bin/magento fullmetrix:connect FMTX-XXXX-XXXX-XXXX
bin/magento fullmetrix:status
```

## Support

- support@fullmetrix.com
- https://fullmetrix.com
