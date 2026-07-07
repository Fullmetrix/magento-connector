# Fullmetrix Magento 2 Connector

[![Latest Version](https://img.shields.io/packagist/v/fullmetrix/magento-connector.svg)](https://packagist.org/packages/fullmetrix/magento-connector)

Connects a Magento 2 / Adobe Commerce store to Fullmetrix. Streams orders, customers, products, categories, cart price rules and credit memos, dispatches realtime entity webhooks and visitor tracking events, and supports remote coupon management and cart recovery links.

Compatible with Magento 2.4+ and Adobe Commerce (PHP 8.1+).

## Installation

Via Composer:

```bash
composer require fullmetrix/magento-connector
bin/magento module:enable Fullmetrix_Connector
bin/magento setup:upgrade
bin/magento cache:flush
```

Or manually: copy this repository into `app/code/Fullmetrix/Connector`, then run the same `module:enable`, `setup:upgrade` and `cache:flush` commands.

## Configuration

The extension works without configuration. Defaults point to `https://fullmetrix.com`. To override the API base (self-hosted or staging):

```bash
bin/magento config:set fullmetrix/general/api_base "https://fullmetrix.com/api/plugin"
```

## Usage

1. In the Magento admin, go to `Marketing -> Fullmetrix`.
2. Enter the connection code provided by Fullmetrix (format `FMTX-XXXX-XXXX-XXXX`).
3. Click Connect. The extension registers with Fullmetrix and receives an HMAC secret.
4. Fullmetrix performs an initial historical sync and then receives realtime webhooks.

A CLI flow is also available:

```bash
bin/magento fullmetrix:connect FMTX-XXXX-XXXX-XXXX
bin/magento fullmetrix:status
bin/magento fullmetrix:disconnect
```

## What is synced

- Orders with line items, shipping, taxes, coupon lines and payments
- Customers with addresses and newsletter opt-in status
- Products, configurable products and their variants, images, categories
- Cart price rules with coupon codes
- Credit memos (refunds) with line items
- Server-side tracking events (identify, add to cart) and the Fullmetrix storefront tracker

## Security

All exchanges between the store and Fullmetrix are signed with HMAC-SHA256 (per-connection secret, timestamped, 5 minute tolerance). The extension never exposes data without a valid signature.

## Support

- Email: support@fullmetrix.com
- Website: https://fullmetrix.com
