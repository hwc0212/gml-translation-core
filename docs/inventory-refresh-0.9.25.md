# Core 0.9.25 inventory-only acceptance

The explicit API is `refresh_inventory(subject, expected_snapshot, source_revision)`. It reuses the existing source render, CAS/transactions, membership replacement, TM reads, readiness policy and cache outbox. It does not repair the missing automatic product-to-aggregate dependency notification. Source/TM data and index gates are preserved.

The GML Translate consumer supplies a capability/nonce/HMAC-protected action and a narrow bootstrap for its AJAX and correctly signed source request. Ordinary requests and existing background workers retain their normal behavior; other plugins can independently start work. No global scheduler is disabled by the product.

## Verified on 2026-11-26 before packaging

- NAS internal network, no host port, synthetic WordPress 7.1 / WooCommerce 10.5.3 / PHP 8.3.33 / MariaDB 10.6.28. No production DB/cache/queue/provider or forms.
- Inventory baseline: 8 assertions; candidate: 48; exact-URL cache/outbox: 8.
- Native source HTTP, authenticated AJAX and unsigned-header rejection: 3 scenarios / 11 assertions.
- Existing inventory/readiness/redesign/queue bridge/cache regressions: 7 scripts / 336 assertions. Existing consumer integration and syntax results are in the linked release report.
- Consumer report and final package validation: [Translate PR #3](https://github.com/hwc0212/gml-translate/pull/3), [rc.14 acceptance](https://github.com/hwc0212/gml-translate/blob/codex/2.11.2-rc.5-review/docs/releases/v2.11.2-rc.14/INVENTORY_REFRESH_REVIEW.md).

## Reproducing the added tests

Use only a disposable WordPress root with a database name beginning `gml_regression`, prefix `test_`, `GML_DATABASE_TESTS=1`, `GML_TEST_WP_ROOT` and `GML_TEST_PRODUCT_DIR` pointing to the Translate consumer. Install a verified offline WooCommerce test copy for `inventory-refresh.php`; do not add WooCommerce to a production site for testing. The scripts are explicit standalone probes; the WooCommerce/native-HTTP cases are not implicitly run by the existing WooCommerce-free database CI.

1. Run `php tests/database/inventory-refresh.php baseline` to observe current product-save notification behavior; `candidate` exercises replacement, TM reuse, no-work controls, other-resource preservation, conflicts, failures and permissions. Fixture setup is destructive to the disposable test tables.
2. Run `php tests/database/inventory-cache-effects.php` for historical/current URL retention, missing adapter, adapter exception and successful acknowledgement; adapters are in-process replacements.
3. For native HTTP, configure an internal-only web server and set `GML_INVENTORY_HTTP_TEST=1` and `GML_INVENTORY_HTTP_HOME` to that exact disposable home URL; run `inventory-refresh-http.php prepare`, then `source`, `ajax-cold`, and `unsigned`. The fixture mapping is created by the candidate script. HTTP tests must explicitly hold unrelated WooCommerce asynchronous work in test configuration using its existing `action_scheduler_allow_async_request_runner` filter. That test-only guard is not part of this Core or the product.

## Limitations and recovery

The first changing refresh can rotate the global internal GML cache epoch multiple times and records this resource's current/historical source and language URL outbox. This is not a zero-cache action or a directly verified external CDN purge. Current production adapters were not inspected.

Manifest replacement and all per-language readiness writes are not one atomic transaction. Injected relation-write failure rolls back membership; later error state/cache writes can still commit. Injected partial-language failure leaves a committed manifest and mixed language generations before a best-effort stale mark. A subsequent explicit refresh recovers consistency in the lab; restoring an old whole-resource backup/approval/cache state is not implemented or tested as atomic rollback.

The epoch fence observes notified changes, not every custom dependency mutation or a fully atomic product-write boundary. Simultaneous dependent-product editing, custom builders, persistent caches, load behavior and production HTTP/SEO remain outside this verification. No production resource has been processed.
