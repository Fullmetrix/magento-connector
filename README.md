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
bin/magento cron:run --group=fullmetrix
bin/magento cache:flush
```

In production mode, `setup:upgrade` clears `pub/static`: always run `setup:static-content:deploy` (with your store locales, for example `fr_FR en_US`) before `cache:flush`, for an installation and for every update. Without it the store answers 500. The `cron:run --group=fullmetrix` pass downloads the measurement script right away; without it the pages load it from fullmetrix.com until the next cron pass.

## Connection

In the admin, go to Marketing, then Fullmetrix, and enter the connection code shown in Fullmetrix. From the command line:

```bash
bin/magento fullmetrix:connect FMTX-XXXX-XXXX-XXXX
bin/magento fullmetrix:status
```

## Measurement script, service and cookies

The module loads the Fullmetrix measurement script from the store (`pub/media/kvo/`) or, when that file is missing, from `https://fullmetrix.com/assets/js/s.js`. The script sends page views, cart and order events to `https://fullmetrix.com` and sets first-party cookies on the store domain: `_kvo_v` (anonymous visitor id, up to 390 days), `_kvo_s` (session, 30 minutes) and `_kvo_c` (contact identifiers after a login or an order). Stores connected before version 1.8.0 keep the older `fm_vid`, `fm_sid` and `fm_cid` cookies until they expire. The store server sets `_kvo_v` again on a same-origin `POST /kvo/k` so browsers keep it longer; no Magento session is started by that request. Tracking consent is not handled by the module: call `fm('consent', {...})` from your tag manager or consent tool. Privacy policy: https://fullmetrix.com/privacy

## Support

- support@fullmetrix.com
- https://fullmetrix.com
