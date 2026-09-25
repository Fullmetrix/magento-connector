# Changelog

## 1.5.0

- The module no longer works during shoppers' requests. Checkout, cart, login, imports and stock updates only record the identifier of what changed, and the Magento cron sends everything to Fullmetrix every minute.
- Queue rows written during a database transaction are written after the commit, never inside it, so a Fullmetrix row can neither slow down nor break a checkout, a save or an import.
- No module error can interrupt an order or a save, and storefront pages never contact Fullmetrix.
- The cron job runs in its own `fullmetrix` group, in a separate process, and never delays the other Magento jobs. A crontab that only runs explicit groups must add `bin/magento cron:run --group=fullmetrix`.
- A refused row no longer blocks the others, tracking is sent even while Fullmetrix refuses store data, and catalog changes always get a share of each run.
- Cart images sent with tracking events are the storefront thumbnails again.
- Once an hour, the queue purges tracking unchanged for 24 hours, rows unchanged for 7 days and rows Fullmetrix refused 30 times, in small batches that never hold up a storefront write. Consents and deletions are never purged for their refusals, only after 7 days without change. A network outage or an unreachable Fullmetrix API (timeout, HTTP 429, 502, 503, 504, Cloudflare 520 to 527 and 530) only delays rows by a minute and never counts toward the 30 refusals. An HTTP 500 counts as a refusal of that row alone and never holds up the rows behind it.

### Upgrading and rolling back

- In production mode, run `setup:upgrade`, `setup:di:compile` and `cache:flush` for the upgrade, and the same sequence for a rollback. Without `setup:di:compile`, the checkout fails right after payment.
- Before rolling back to 1.4, empty the queue: wait until `bin/magento fullmetrix:status` shows `Queue: 0 pending`. 1.4 cannot read the rows 1.5 writes and would drop them.
- PHP 8.1 or later is required. Do not copy the module onto a store still running PHP 7.4.
