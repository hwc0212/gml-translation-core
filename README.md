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

## 0.9.1 Human Review Cache Coherency

Approving or rejecting an exact translation snapshot now rotates the rendered
page-cache namespace inside the same database transaction as the current review
row and append-only audit event. The generation uses an atomic database
increment, so concurrent review or content invalidations cannot collapse into a
single stale namespace. It also advances beyond a persistent-cache value that
is ahead of the database, preventing namespace reuse after a database restore
with stale Redis or Memcached data. Anonymous routing, canonical, hreflang, and
sitemap output therefore cannot keep serving an older publication decision
after a reviewer acts.

If the cache generation cannot be updated, the review decision and its audit
event are rolled back and the existing decision remains authoritative. Database
regressions cover approval, rejection, cache-write failure, and bounded product
indexability reads.

## 0.9.0 Derived Publication Eligibility

Phase 2D adds one product-neutral, derived publication decision for every exact
resource/language snapshot. A local translated route is public eligible only
when the source resource is indexable, the route round-trips safely, machine
readiness is complete, Human Review approved the current snapshot, and that
approval still matches the current manifest and Translation Memory generation.
The result is calculated from authoritative state and is never stored as a
second `published` flag.

Bulk APIs load Human Review state in one joined query per 500 resources. The
manifest table now has an additive index on its existing `source_url_hash`, so
sitemap adapters can resolve source and translated URLs back to resources in
bounded reads without URL-by-language database loops. External-domain targets
remain `external_unverified` and fail closed until a future authenticated
cross-site readiness protocol exists.

Core only supplies the eligibility decision and URL cluster. Product adapters
remain responsible for SEO-plugin indexability integration and for applying the
decision consistently to routing, previews, switchers, hreflang, and sitemaps.

## Vendoring

Product repositories contain `translation-core.lock.json` plus a vendoring
tool. The lock records this package version, source commit, and SHA-256 hash for
every shipped Core file. CI verifies both the committed vendor directory and,
when checked out, this exact source commit.

## 0.8.1 Snapshot-Safe Review Hardening

The Review form now carries a bounded expected tuple containing the manifest
fingerprint/generation, global generation, translation generation, deterministic
translation snapshot fingerprint, machine state, and Human Review revision.
These values are concurrency expectations only. The decision transaction locks
and reconstructs the authoritative current database tuple and rejects every
mismatch with a dedicated conflict; it never approves whichever snapshot happens
to be current when an old form is submitted.

Concurrent decisions use first-committer-wins with an explicit stale-form
conflict. Approve/Approve, Approve/Reject, and Reject/Reject never use silent
last-writer-wins: one transaction records one current row and one append-only
audit event, while the other reviewer must refresh.

All supported Translation Memory create/update/delete paths use the Core-owned
mutation service. It invalidates durable resource readiness, advances each
affected reviewed resource/language generation once, and commits the translation
write in the same transaction. The deterministic translation fingerprint hashes
only the ordered hashes in the exact current manifest, target language, effective
translation status, and a SHA-256 digest of each effective translated value. It
therefore catches legacy/downgraded writers after readiness re-entry without
placing complete source or translation bodies in Human Review or its audit log.
Batch readiness rebuilds calculate up to 500 fingerprints per query so this
additional integrity check does not reintroduce per-resource reads.

Human Review transactions fail closed unless every participating table exists
and uses InnoDB. Transaction start, current-decision write, audit insert, and
commit results are all checked. Existing non-transactional tables are reported
as an actionable health error and are never converted automatically.

Machine and Human states remain separate:

| Machine snapshot | Stored decision | Snapshot match | Effective Human Review |
| --- | --- | --- | --- |
| complete | none | n/a | unreviewed |
| complete | approved | yes | approved |
| complete | rejected | yes | rejected |
| complete | approved/rejected | no | stale |
| incomplete/stale/render_error/excluded | none | n/a | blocked |
| incomplete/stale/render_error/excluded | approved/rejected | yes | blocked |
| any | approved/rejected | no | stale |
| external_unverified | none | n/a | blocked and not locally reviewable |

There is still no persisted `public_eligible` flag. In 0.9.0 the exact current
Human Review decision is one input to the derived publication eligibility API;
product adapters decide how that API affects anonymous routes and SEO output.

## 0.8.0 Human Review and Snapshot Approval

Phase 2C adds a product-neutral human-review layer above machine readiness.
Machine-complete resources remain unreviewed until an administrator explicitly
approves or rejects one exact resource/language snapshot. Decisions bind to the
manifest generation, manifest fingerprint, global generation, and translation
generation; any real source or translated-text change makes the old decision
stale. Identical authoritative rescans preserve the current decision.

The additive schema stores a cheap current decision, a translation generation,
and append-only audit events. Audit rows contain identifiers, fingerprints,
decision metadata, and notes, but never copy source or translated page text.
Translation writes, readiness invalidation, and approval-generation changes are
committed atomically, so a failed write cannot leave a new translation with an
old approval or a partial invalidation. Phase 2C does not alter routing, public
visibility, canonical output, hreflang, or sitemaps; those remain a later
publication-eligibility cutover.

The real-database suite now performs a direct MariaDB preflight before loading
WordPress, preventing a WordPress database error page with exit code zero from
being mistaken for a successful regression run.

## 0.7.2 Current-Corpus Queue and Readiness

Language progress, public language readiness, normal queue batches, limited
retries, and the safety circuit now use only unique source hashes referenced by
the current completed resource-manifest generation. Text removed or replaced
during a redesign no longer depresses current completion percentages, consumes
retry quota, or blocks a language indefinitely.

Historical queue failures, pending rows, Translation Memory, manual
translations, glossary data, and audit details are retained without migration
or deletion. The administration screens label obsolete failures as stored
history, while current missing, pending, failed, and not-yet-queued text remain
separate actionable counts.

Current-corpus filtering activates only after the full manifest inventory is
complete and free of current render errors. Before that point, public readiness
fails closed and queue controls keep the legacy scope. Public readiness requires
all critical SEO strings and at least 95 percent current-string coverage. This
is machine readiness, not Human Approval.

Frontend readiness uses a short-lived lightweight coverage cache and does not
scan queue history. Actionable failure counts are also cached briefly and are
invalidated with queue, Translation Memory, manifest, and backfill changes. No
database schema, URL, Provider, prompt, or uninstall policy changes are made.

## 0.7.1 Durable Readiness Invalidation

Translation Memory changes now fail closed with one indexed database update:
every current resource/language readiness row related to the changed source
hash becomes `stale` before background work begins. The readiness table itself
is the durable work ledger, replacing the single per-hash continuation option
that could be overwritten when multiple hashes had more than 500 resources.

A product-neutral worker claims at most 500 stale rows under the existing
owner-token lease, computes the batch without AI or queue changes, and persists
results in bounded multi-row writes. Claimed rows use an expiring `rebuilding`
state, so a crashed process can delay completion but cannot leave a false
`complete` result. A recurring recovery wakeup reclaims expired work after PHP,
Cron, or server interruption. Concurrent invalidation wins over an older
worker result, and overlapping hashes deduplicate through the existing unique
resource/language row.

Database schema version 3.0.1 adds only `status_id (status, id)` to the existing
readiness table. Translation Memory, queue rows, manual translations, glossary,
exclusions, routes, and public SEO output are unchanged. This remains
infrastructure-only shadow readiness; it does not approve or publish a language
variant.

## 0.7.0 Shadow Resource Readiness

Adds an additive, database-authoritative resource manifest and readiness layer
for Phase 2B shadow evaluation. Immutable resource identities connect exact
same-install, anonymous HTTP 200 HTML renders to versioned manifests, current
resource/string relations, and per-language readiness snapshots. A language is
ready only when the current manifest is complete, every critical SEO string is
translated, and at least 95 percent of all required strings are available.

Source changes stale only the affected resource. Routing or other global
changes advance one request-bounded generation and schedule bounded
rediscovery. Translation hash changes recalculate affected resources through a
reverse index without duplicating shared translation-memory rows. Backfill and
dirty discovery share the existing owner-token lease lock and support bounded,
pause/resume processing. Readiness remains available with AI disabled and is
read directly from the database; persistent object caches are never an
authority.

This release is infrastructure-only. It does not approve translations, expose
resource readiness publicly, or change routing, canonical, hreflang, sitemap,
language-switcher, or AI queue behavior.

## 0.6.2 Deferred Import Routing Refresh

Routing-affecting multilingual option changes now set one idempotent deferred
rewrite marker. Bulk imports can update several language records without
flushing per item; the next safe non-AJAX, non-Cron `init` registers the final
language rules, performs one soft refresh, and clears the marker after the
attempt. Re-importing an identical routing configuration does not schedule
another refresh.

The routing signature is limited to multilingual state, source language,
enabled target languages, and their local/external URL configuration. Display
names, flags, and ordering do not trigger rewrite work. Existing root and
subdirectory URL rules, stored translations, AI settings, queue state, and
Phase 1 atomic locks are unchanged.

## 0.6.1 Atomic Worker Locks

Queue processing, full-site crawling, and incremental discovery now share a
database compare-and-swap lease lock. Acquisition, stale takeover, renewal, and
release use owner tokens and exact stored-value comparisons, so a stale process
cannot delete or overwrite a newer owner's lock even when a persistent object
cache returns old option data. Existing queue-array and crawler-integer lock
values remain readable and are replaced atomically after they expire.

Queue recovery of rows left in `processing` is restricted to the current lease
owner. Provider calls and crawler fetches are bounded, and each worker renews
its lease before and after slow work and before committing shared progress. A
worker that outlives its lease stops writing; its shutdown handler can release
only a lock with the same token. No table, translation row, Translation Memory
entry, URL, provider, prompt, or readiness rule changes in this release.

## 0.6.0 Uninstall Data Retention

Products can now apply an explicit, per-site uninstall preference through the
shared uninstaller. Runtime Cron events and disposable page cache are stopped
when the last GML translation host is removed. Translation memory, manual edits,
queue rows, language configuration, glossary, credentials, and settings remain
available for a later reinstall unless an administrator previously selected
complete removal.

Complete removal drops the current and legacy translation tables and deletes
translation options and transients while leaving GML SEO-owned options intact.
If another installed GML product can reuse the shared translation data, the
uninstaller does not touch shared tables, settings, cache, or jobs. Product
adapters remain responsible for their own data and for the administrator UI.

## 0.5.0 External Language Sites

A target language can now be served by an independent HTTPS website. External
languages remain available to the language provider, switcher, and hreflang
adapter, while local rewrite rules, output translation, crawling, and AI queue
selection explicitly ignore them. Missing `site_mode` values remain local, so
existing language configuration and translation data require no migration.

Same-path mapping preserves the source page path but never copies query strings
or credentials to the other domain. Homepage-only mapping is available when URL
structures differ; it is intentionally omitted from inner-page hreflang output
to avoid declaring unrelated pages as equivalents. No remote request is made.

## 0.4.11 Incremental Sync And Token Efficiency

Saving a published public post now records a bounded dirty-object marker and
schedules a two-object incremental discovery job. The job renders only changed
objects, queues missing source hashes for enabled languages, and leaves the AI
worker's pause state untouched. Provider failures or disabled AI leave markers
for a later admin/Cron retry; no provider call occurs in the editor save request.

Translation batches deduplicate identical source strings, include only protected
terms and glossary rules present in that batch, and use an output allowance sized
to the request. Pure specifications such as `<40°C`, `<70%`, and `90*45*30mm`
bypass AI and are stored unchanged. Plain-text cleanup, memory reads, attributes,
and rendered HTML preserve comparison signs while still stripping actual markup.

Standalone credential storage now includes independent encrypted Qwen and OpenAI
keys in addition to Gemini and DeepSeek. No existing key, queue row, translation,
URL, manual edit, glossary rule, or pause setting is migrated or deleted.

## 0.4.10 Failure Recovery And Readiness

Provider failures are classified without changing the queue schema. HTTP 429,
5xx, timeout and network failures return the current batch to pending without
consuming item attempts, then use a bounded automatic cooldown while the
recurring worker remains scheduled. Credential, permission, invalid-request and
missing-model failures still open the manual safety circuit. Credentials and raw
provider responses are redacted before diagnostics are stored or displayed.

Successful connection tests acknowledge existing failed rows instead of deleting
them. Administrators can distinguish later failures, review twenty recent rows,
and retry one language in samples of at most 25. Explicit retry first reconciles
rows whose translation already exists in memory, avoiding duplicate API work.

Language-level SEO readiness now tolerates a small historical tail once stored
coverage reaches 95 percent; each rendered page still requires all SEO-critical
text and 95 percent of its own visible text. New queue discoveries invalidate
readiness and rendered-page cache state. No tables, option names, translations,
URLs, pause settings or API credentials are migrated or deleted.

## 0.4.9 Gemini Responses And Cache Confirmation

The shared Gemini parser collects final text across parts, excludes thought
parts, and rejects truncated, blocked or empty output. Diagnostics contain only
allowlisted finish reasons and bounded token counts, never raw response text or
credentials. Translation and SEO adapters use the same parser. Explicit tests
have a 1024-token budget and do not retry automatically; no model is changed.

Manual page-cache refresh requires the literal REFRESH confirmation in addition
to administrator capability and nonce checks, including legacy clear actions.
Automatic content-change invalidation is unaffected. Cache commands preserve
translation storage, manual edits, queue records and all pause/safety settings.

References: https://ai.google.dev/api/generate-content and
https://ai.google.dev/gemini-api/docs/generate-content/thinking?hl=en

## 0.4.8 Independent Queue Controls

Normal pending work and approved failed-item samples have separate controls.
Start All selects every enabled normal language, while language actions affect
only their own normal scope. Paused sample IDs stay excluded from normal work.
Sample completion cannot stop explicitly started normal work. Global pause
stops both scopes; resuming one scope never implicitly resumes the other.

The optional gml_translation_normal_queue_enabled and
gml_translation_retry_sample_paused flags preserve legacy sample-only defaults
without an upgrade migration or automatic scope expansion. Explicit zero/one
values avoid WordPress treating a missing false-valued option as unchanged.
Existing tables, translations, URLs and all historical failed rows are retained.
Pause all work before rolling back files to a version without these controls.

Starts install a recurring every_minute event, replacing legacy one-off events.
The worker retains one provider batch per tick, language rotation, locking,
provider-wide circuit breaking and a maximum 25-row failure retry sample.
Content scanning no longer depends on sample state and never resumes a queue.
No new frontend scans, network requests or migrations are introduced.

## 0.4.7 Paused Sample Recovery (Historical)

An approved retry sample now has its own explicit Resume Sample command.
Pausing it no longer leaves every start control disabled. Resume validates
the existing scope (one enabled language, at most 25 IDs), credentials,
permissions, nonce, circuit breaker and active worker lock. Scheduling must
succeed before unpausing. Existing IDs, attempts, translations and unrelated
language pauses are preserved. Sample completion still pauses the queue;
neither a normal full start nor a content scan bypasses the sample boundary.

Admin-only sample summaries use a bounded primary-key lookup. No frontend
queries, provider calls, data migrations or automatic retries are introduced.
New retries use the same scheduling-first resume path. Database and local
browser tests cover repeated pause/resume, partial completion, protected
failure paths and automatic pause after completing only the approved sample.

## 0.4.6 Routing And Queue Fairness

Both adapters register language rules before the activation flush, including
activation after WordPress init. An authorized, non-AJAX/non-Cron admin request
can repair missing or outdated language rules without replacing other plugins'
persisted routes. No frontend rewrite repair, AI request or translation-data
migration is added. Multilingual routing never requires a provider credential.

Queue selection rotates after the last processed language, wrapping when no
later eligible language has work. The existing lock, one-batch limit, pause,
circuit breaker and bounded retry selection remain unchanged. Selection uses
at most two bounded queries and does not increase concurrency or reset progress.

Real WordPress regressions cover late activation, lost rules, route preservation,
language changes, disable/re-enable, root/subdirectory homepages and inner pages,
search and genuine 404s, plus Russian/Spanish fairness with a synthetic provider.

## 0.4.5 Credential Safety

Both adapters share legacy-compatible credential storage. Failed decryption
returns no key, never ciphertext. Writes must succeed and read back exactly.
The existing raw-salt AES-CBC format and option names remain unchanged for
rollback; historical Gemini/DeepSeek plaintext formats are read-only fallbacks.
Unrecognized plaintext records require re-entry, while newly saved opaque keys
are supported without a provider-prefix assumption. No keys are migrated on read.

Standalone availability checks only the selected provider's readable credential.
Missing or unreadable keys stop new AI work, not existing multilingual output.
Connection errors redact the actual request credential, including opaque formats.
Database tests cover save/read/test, failed writes, wrong salts, admin nonces,
permissions and preservation of the paused queue. All credentials are synthetic
and all HTTP responses are mocked; these tests do not certify a live API account.

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
