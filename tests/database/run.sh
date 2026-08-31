#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
: "${GML_TEST_PRODUCT_DIR:?Product checkout required}"
: "${GML_TEST_WP_ROOT:?Disposable WordPress installation required}"
test "${GML_DATABASE_TESTS:-}" = 1
php "$ROOT/upgrade.php"
php "$ROOT/request-context.php" ajax
php "$ROOT/request-context.php" cron
php "$ROOT/enqueue.php" prepare
pids=()
for i in {1..8}; do
    php "$ROOT/enqueue.php" worker &
    pids+=("$!")
done
for pid in "${pids[@]}"; do wait "$pid"; done
php "$ROOT/enqueue.php" verify
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
echo "OK real WordPress / MariaDB regressions for $GML_TEST_PRODUCT_DIR"
