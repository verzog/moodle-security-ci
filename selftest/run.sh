#!/usr/bin/env bash
# Self-test, part 1: every "EXPECT:<rule>" marker in the fixture must be reported on that line,
# and nothing else may be reported (apart from the plugin-level findings listed below).
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
fixture="$here/fixture_plugin"
expected="$(mktemp)"
actual="$(mktemp)"
trap 'rm -f "$expected" "$actual"' EXIT

grep -rnoE 'EXPECT:[A-Za-z0-9-]+' "$fixture" \
    | sed -E "s#^$fixture/##; s#^([^:]+):([0-9]+):EXPECT:(.*)#\1:\2 \3#" > "$expected"
printf "%s\n" "version.php:1 S12-no-events" "version.php:1 Q4-no-tests" "version.php:1 Q6-no-readme" \
    "lib/chart:1 S20-undeclared" >> "$expected"
sort -o "$expected" "$expected"

php "$here/../bin/security-lint.php" --report-only "$fixture" \
    | grep -E '^[^ ]+:[0-9]+  ' | awk '{print $1, $2}' | sort > "$actual"

if diff -u "$expected" "$actual"; then
    echo "selftest: PASS ($(wc -l < "$expected") expected findings)"
else
    echo "selftest: FAIL (- missing, + unexpected)"
    exit 1
fi

# The clean fixture uses the safe version of every pattern and must produce no findings.
if ! php "$here/../bin/security-lint.php" "$here/clean_plugin" > /dev/null; then
    echo "selftest: FAIL (clean_plugin produced findings)"
    php "$here/../bin/security-lint.php" "$here/clean_plugin"
    exit 1
fi
echo "selftest: PASS (clean_plugin has no findings)"

# The lint must never execute plugin code: db/access.php here writes a canary file if included,
# and calls a function inside $capabilities, which the static reader must refuse (S14-unreadable).
canary="$(mktemp -u)"
out="$(SECURITY_LINT_CANARY="$canary" php "$here/../bin/security-lint.php" --report-only "$here/hostile_plugin")"
if [ -e "$canary" ]; then
    rm -f "$canary"
    echo "selftest: FAIL (hostile_plugin/db/access.php was executed)"
    exit 1
fi
if ! grep -qE '^db/access.php:[0-9]+  S14-unreadable' <<< "$out"; then
    echo "selftest: FAIL (hostile_plugin did not report S14-unreadable)"
    echo "$out"
    exit 1
fi
echo "selftest: PASS (db/access.php read without executing it)"

# The GitLab Code Quality report must be valid JSON with one entry per finding.
report="$(mktemp)"
trap 'rm -f "$expected" "$actual" "$report"' EXIT
php "$here/../bin/security-lint.php" --report-only --codequality="$report" "$fixture" > /dev/null
php -r '
    $data = json_decode((string) file_get_contents($argv[1]), true);
    if (!is_array($data) || count($data) !== (int) $argv[2]) {
        fwrite(STDERR, "selftest: FAIL (Code Quality report is not valid or has the wrong count)\n");
        exit(1);
    }
    foreach ($data as $e) {
        if (!isset($e["check_name"], $e["fingerprint"], $e["location"]["path"], $e["location"]["lines"]["begin"])) {
            fwrite(STDERR, "selftest: FAIL (Code Quality entry missing a field)\n");
            exit(1);
        }
    }
' "$report" "$(wc -l < "$expected")"
echo "selftest: PASS (Code Quality report)"

# Third-party libraries: covered by a lockfile in or above their folder, or flagged for a manual check.
tp="$(php "$here/../bin/thirdparty-lockfile-check.php" "$here/thirdparty_plugin")"
tpfail=0
for want in 'Widget (vendor/acme/widget) is covered by the lockfile in the plugin root' \
    'Composer autoloader (vendor) is covered by the lockfile in the plugin root' \
    'Leftpad (node_modules/leftpad) is covered by the lockfile in the plugin root' \
    'Stale: thirdpartylibs.xml declares 2.0.0 but the lockfile has 2.1.0' \
    'Check advisories by hand: Chart 4.0.0 (lib/chart)' \
    "Outside: location '../outside' is empty or leaves the plugin"; do
    grep -qF -- "$want" <<< "$tp" || { echo "selftest: FAIL (third-party check missing: $want)"; tpfail=1; }
done
if [ "$(grep -c 'Check advisories by hand' <<< "$tp")" -ne 1 ]; then
    echo "selftest: FAIL (expected exactly one library flagged for a manual check)"
    tpfail=1
fi
if [ "$tpfail" -ne 0 ]; then
    echo "$tp"
    exit 1
fi
echo "selftest: PASS (third-party lockfile coverage)"
