#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
: "${GML_TEST_PRODUCT_DIR:?Product checkout required}"
: "${GML_TEST_WP_ROOT:?Disposable WordPress installation required}"
test "${GML_DATABASE_TESTS:-}" = 1
php "$ROOT/upgrade.php"
php "$ROOT/request-context.php" ajax
php "$ROOT/request-context.php" cron
php "$ROOT/scan-context.php"
php "$ROOT/enqueue.php" prepare
pids=()
for i in {1..8}; do
    php "$ROOT/enqueue.php" worker &
    pids+=("$!")
done
for pid in "${pids[@]}"; do wait "$pid"; done
php "$ROOT/enqueue.php" verify
php "$ROOT/controls.php"
php "$ROOT/cache-confirmation.php"
php "$ROOT/gemini-response.php"
php "$ROOT/failure-recovery.php"
php "$ROOT/seo-connection.php"
php "$ROOT/sample-resume.php"
php "$ROOT/sample-schedule.php"
php "$ROOT/queue-scopes.php"
php "$ROOT/technical-text.php"
php "$ROOT/credentials.php"
php "$ROOT/credentials-admin.php"
php "$ROOT/queue-fairness.php"
for scenario in resume breaker sample schedule queue-schedule no-key unauthorized nonce; do
    php "$ROOT/crawl.php" "$scenario"
done
slug="$(basename "$GML_TEST_PRODUCT_DIR")"
test "$slug" = gml-seo || test "$slug" = gml-translate
test -f "$GML_TEST_WP_ROOT/wp-config.php"
if [ ! -e "$GML_TEST_WP_ROOT/wp-content/plugins/$slug" ]; then
    ln -s "$GML_TEST_PRODUCT_DIR" "$GML_TEST_WP_ROOT/wp-content/plugins/$slug"
fi
php "$ROOT/lifecycle.php" activate
pids=()
for i in {1..8}; do
    php "$ROOT/lifecycle.php" frontend &
    pids+=("$!")
done
for pid in "${pids[@]}"; do wait "$pid"; done
php "$ROOT/lifecycle.php" deactivate
php "$ROOT/activation-conflict.php"
for home in http://gml-regression.test http://gml-regression.test/ygnaglul; do
    export GML_TEST_HOME="$home"
    php "$ROOT/routing.php" prepare
    php "$ROOT/routing.php" request / home
    php "$ROOT/routing.php" request /es/ home
    php "$ROOT/routing.php" request '/es/?utm_source=test' home
    php "$ROOT/routing.php" request /de/routing-about/ page
    php "$ROOT/routing.php" request /es/missing-fixture/ missing
    php "$ROOT/routing.php" request /xx/ missing
    php "$ROOT/routing.php" request '/es/?s=test' search
    php "$ROOT/routing.php" break-rules
    php "$ROOT/routing.php" repair
    php "$ROOT/routing.php" request /de/ home
    php "$ROOT/routing.php" cleanup
done
echo "OK real WordPress / MariaDB regressions for $GML_TEST_PRODUCT_DIR"
