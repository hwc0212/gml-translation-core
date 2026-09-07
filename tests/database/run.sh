#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
: "${GML_TEST_PRODUCT_DIR:?Product checkout required}"
: "${GML_TEST_WP_ROOT:?Disposable WordPress installation required}"
test "${GML_DATABASE_TESTS:-}" = 1

scenario_count=0
expected_scenarios=92
run_scenario() {
    local output
    if ! output="$("$@" 2>&1)"; then
        printf '%s\n' "$output" >&2
        return 1
    fi
    printf '%s\n' "$output"
    if ! grep -Fq 'MARKER wordpress_bootstrap_loaded' <<<"$output"; then
        echo "Database scenario exited without the WordPress bootstrap marker: $*" >&2
        return 1
    fi
    scenario_count=$((scenario_count + 1))
    GML_LAST_SCENARIO_OUTPUT="$output"
}

php "$ROOT/preflight.php"
php "$ROOT/empty-database-preflight.php"
run_scenario php "$ROOT/upgrade.php"
run_scenario php "$ROOT/request-context.php" ajax
run_scenario php "$ROOT/request-context.php" cron
run_scenario php "$ROOT/scan-context.php"
run_scenario php "$ROOT/enqueue.php" prepare
pids=()
for i in {1..8}; do
    php "$ROOT/enqueue.php" worker &
    pids+=("$!")
done
for pid in "${pids[@]}"; do wait "$pid"; done
run_scenario php "$ROOT/enqueue.php" verify
run_scenario php "$ROOT/controls.php"
export GML_TEST_CORE_SRC="$(cd "$ROOT/../../src" && pwd)"
run_scenario php "$ROOT/locking.php"
unset GML_TEST_CORE_SRC
run_scenario php "$ROOT/cache-confirmation.php"
run_scenario php "$ROOT/gemini-response.php"
run_scenario php "$ROOT/failure-recovery.php"
run_scenario php "$ROOT/seo-connection.php"
run_scenario php "$ROOT/sample-resume.php"
run_scenario php "$ROOT/sample-schedule.php"
run_scenario php "$ROOT/queue-scopes.php"
run_scenario php "$ROOT/technical-text.php"
run_scenario php "$ROOT/technical-queue.php"
run_scenario php "$ROOT/token-efficiency.php"
run_scenario php "$ROOT/credentials.php"
run_scenario php "$ROOT/credentials-admin.php"
run_scenario php "$ROOT/provider-adapters.php"
run_scenario php "$ROOT/external-language-sites.php"
run_scenario php "$ROOT/queue-fairness.php"
for scenario in resume breaker sample schedule queue-schedule no-key unauthorized nonce; do
    run_scenario php "$ROOT/crawl.php" "$scenario"
done
run_scenario php "$ROOT/incremental-sync.php"

slug="$(basename "$GML_TEST_PRODUCT_DIR")"
test "$slug" = gml-seo || test "$slug" = gml-translate
test -f "$GML_TEST_WP_ROOT/wp-config.php"
if [ ! -e "$GML_TEST_WP_ROOT/wp-content/plugins/$slug" ]; then
    ln -s "$GML_TEST_PRODUCT_DIR" "$GML_TEST_WP_ROOT/wp-content/plugins/$slug"
fi
run_scenario php "$ROOT/lifecycle.php" activate
if ! grep -Fq 'MARKER product_activated' <<<"$GML_LAST_SCENARIO_OUTPUT"; then
    echo 'Plugin activation scenario did not prove the product became active.' >&2
    exit 1
fi
pids=()
for i in {1..8}; do
    php "$ROOT/lifecycle.php" frontend &
    pids+=("$!")
done
for pid in "${pids[@]}"; do wait "$pid"; done
run_scenario php "$ROOT/lifecycle.php" deactivate
run_scenario php "$ROOT/activation-conflict.php"

for home in http://gml-regression.test http://gml-regression.test/ygnaglul; do
    export GML_TEST_HOME="$home"
    run_scenario php "$ROOT/routing.php" prepare
    run_scenario php "$ROOT/routing.php" request / home
    run_scenario php "$ROOT/routing.php" request /es/ home
    run_scenario php "$ROOT/routing.php" request '/es/?utm_source=test' home
    run_scenario php "$ROOT/routing.php" request /de/routing-about/ page
    run_scenario php "$ROOT/routing.php" request /es/missing-fixture/ missing
    run_scenario php "$ROOT/routing.php" request /xx/ missing
    run_scenario php "$ROOT/routing.php" request '/es/?s=test' search
    run_scenario php "$ROOT/routing.php" break-rules
    run_scenario php "$ROOT/routing.php" repair
    run_scenario php "$ROOT/routing.php" request /de/ home
    run_scenario php "$ROOT/routing.php" import
    run_scenario php "$ROOT/routing.php" request /fr/ home
    run_scenario php "$ROOT/routing.php" request /it/ home
    run_scenario php "$ROOT/resource-readiness.php"
    run_scenario php "$ROOT/readiness-durable.php"
    run_scenario php "$ROOT/current-corpus-readiness.php"
    run_scenario php "$ROOT/resource-approval.php"
    run_scenario php "$ROOT/resource-approval-hardening.php"
    run_scenario php "$ROOT/resource-approval-invalidation.php"
    run_scenario php "$ROOT/public-eligibility.php"
    for concurrent_case in approve_approve approve_reject reject_reject; do
        case "$concurrent_case" in
            approve_approve) decision_one=approve; decision_two=approve ;;
            approve_reject) decision_one=approve; decision_two=reject ;;
            reject_reject) decision_one=reject; decision_two=reject ;;
        esac
        run_scenario php "$ROOT/resource-approval-concurrency.php" prepare "$concurrent_case"
        worker_one_log="$(mktemp)"
        worker_two_log="$(mktemp)"
        php "$ROOT/resource-approval-concurrency.php" worker "$concurrent_case" one "$decision_one" >"$worker_one_log" 2>&1 & worker_one_pid=$!
        php "$ROOT/resource-approval-concurrency.php" worker "$concurrent_case" two "$decision_two" >"$worker_two_log" 2>&1 & worker_two_pid=$!
        wait "$worker_one_pid"
        wait "$worker_two_pid"
        cat "$worker_one_log" "$worker_two_log"
        grep -Fq 'MARKER wordpress_bootstrap_loaded' "$worker_one_log"
        grep -Fq 'MARKER wordpress_bootstrap_loaded' "$worker_two_log"
        rm -f "$worker_one_log" "$worker_two_log"
        run_scenario php "$ROOT/resource-approval-concurrency.php" verify "$concurrent_case"
    done
    run_scenario php "$ROOT/routing.php" cleanup
done

if [ -f "$GML_TEST_PRODUCT_DIR/tests/database/uninstall.php" ]; then
    expected_scenarios=$((expected_scenarios + 1))
    run_scenario php "$GML_TEST_PRODUCT_DIR/tests/database/uninstall.php"
fi
run_scenario php "$ROOT/uninstall.php"
if [ "$scenario_count" -ne "$expected_scenarios" ]; then
    echo "Database scenario count mismatch: ran $scenario_count, expected $expected_scenarios." >&2
    exit 1
fi
echo "MARKER required_scenarios_ran=$scenario_count"
echo "OK real WordPress / MariaDB regressions for $GML_TEST_PRODUCT_DIR"
