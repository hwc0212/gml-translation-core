# GML Translation Core

Shared, product-neutral translation code for GML Translate and the translation
module bundled with GML SEO.

This is a build-time source package, not a WordPress plugin. End users never
install it. Each product release vendors the locked Core files into its own ZIP
and remains independently installable without Composer, npm, Git submodules, or
a third runtime plugin.

## Boundaries

Core owns translation state, storage schema, translation memory, safe AI
transport, translation prompts, bounded queue processing, manual editor logic,
bounded content crawling, translated HTML caching, parsing, glossary and
exclusion logic, language/URL utilities, and read-only translated URL
relationships.

Runtime lookups load only translation hashes used by the current page. Core
does not place an entire language dictionary into PHP or a persistent object
cache during ordinary frontend requests.

Core must not register product menus, decide which plugin owns SEO output, read
product-specific settings pages, or emit competing canonical/hreflang/sitemap
markup. Product adapters supply credentials and product state, select the SEO
authority, and expose the appropriate WordPress administration experience.

Legacy `GML_*` class names, `gml_*` options, and database tables are retained so
existing installations and rollback releases keep working.

## Vendoring

Product repositories contain `translation-core.lock.json` plus a vendoring
tool. The lock records this package version, source commit, and SHA-256 hash for
every shipped Core file. CI verifies both the committed vendor directory and,
when checked out, this exact source commit.

## 0.4.4 Controls And Technical Text

Content discovery and AI work are independent. A scan can enqueue missing text
while AI is ordinarily paused, but never resumes the worker or invokes a paid
provider itself. Existing running workers keep their existing state. Credential,
multilingual, circuit-breaker and bounded-sample guards remain in force.

Shared administration controls check capabilities and nonces, schedule before
resuming AI, and expose actual batch activity rather than equating unpaused with
Running. Legacy cache commands now invalidate rendered pages only; translation
memory, manual edits and queue rows survive. Unsupported queue deletion is rejected.

Technical-safe wrapper cleanup preserves dimensions such as `90*45*30mm`,
operators and SKU symbols in provider output, visible text, titles and attributes.
This does not reconstruct already-corrupted saved translations or translate new
website content. Those require review and bounded follow-up, not a database reset.

## 0.4.3 Upgrade Safety

The 0.4.2 installer could perform an unbounded queue self-join DELETE and
dbDelta ALTER on ordinary requests. This caused a confirmed production outage.
Do not reuse that migration strategy.

0.4.3 preserves existing 2.4.0/2.5.1 tables, including historical duplicates and
manual translations. It creates only missing tables; no existing-table ALTER,
automatic deduplication, or empty-context deletion is performed. New queues
retain their unique key. A zero-wait, site/language-scoped database advisory lock
plus a recheck protects new enqueues in legacy queues without that key. If a
lock is unavailable, enqueue is skipped rather than blocking a page request.

Setup runs only on activation or permitted non-AJAX/non-Cron admin requests,
under a zero-wait per-site database lock with a two-second metadata lock timeout.
Failure does not advance the schema version, releases the lock, and has a
60-second admin retry cooldown. Existing options and translations are retained.
The schema version is a compatibility marker, not a claim that all old indexes
were rebuilt. Releases predating schema 2.4.0 need separate legacy validation.

0.4.3 tied explicit Auto-Translate start to both Cron events. 0.4.4 replaces that
behavior with independent scan and queue commands. Missing credentials, circuit
breakers, active limited samples and scheduling failures cannot be bypassed.

## Real Database Regression Tests

`tests/database` requires a disposable MariaDB 10.11 database whose name starts
with `gml_regression`, a WordPress 7.1 installation using the supplied config,
and explicit `GML_DATABASE_TESTS=1`. These tests DROP fixture tables and MUST
NOT run against a website database. They use synthetic data, not customer data.

Set `GML_TEST_WP_ROOT`, `GML_TEST_DB_HOST`, and `GML_TEST_PRODUCT_DIR`, copy
`tests/database/wp-config.php` into the disposable WordPress root, then run:

```sh
php tests/database/setup.php
bash tests/database/run.sh
```

Each product's CI runs the shared tests against its own vendored Core: fresh
activation, 130,000 legacy queue rows, 52,000 manual translations, SQL checksums,
held metadata/named locks, real permission failure/recovery, eight concurrent
enqueues and visitor bootstraps, AJAX/Cron exclusion, pause/breaker/sample/key
states, cron scheduling failure, capability and nonce rejection. API calls are
blocked. This does not replace theme/CDN/Redis and paid-provider staging tests.
