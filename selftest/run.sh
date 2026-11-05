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
