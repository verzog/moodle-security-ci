#!/usr/bin/env bash
# Check the release test record for a tag before a release zip is built.
#
# Usage: check-test-record.sh <plugin-dir> <tag>
# Expects <plugin-dir>/tests/release/<tag>.md, filled in from tests/release/TEMPLATE.md.
#
# Fails when the record is missing, names a different version, has an unticked box,
# has no tester or date (DD/MM/YYYY), or does not say "Result: PASS".
set -euo pipefail

plugin="${1:?plugin dir}"
tag="${2:?tag, e.g. v1.3.0}"
record="$plugin/tests/release/$tag.md"
fail=0

err() {
    echo "::error file=${record}::$1" >&2
    echo "test record: $1" >&2
    fail=1
}

if [ ! -f "$record" ]; then
    err "missing $record — copy tests/release/TEMPLATE.md, fill it in and merge it before releasing."
    exit 1
fi

head -n 5 "$record" | grep -qF -- "$tag" || err "the heading does not name $tag."
grep -qE '^Result:[[:space:]]*PASS[[:space:]]*$' "$record" || err "no 'Result: PASS' line."
grep -qE '^Tester:[[:space:]]*[^[:space:]]' "$record" || err "Tester is blank."
grep -qE '^Date:[[:space:]]*[0-3][0-9]/[01][0-9]/20[0-9]{2}[[:space:]]*$' "$record" || err "Date is blank or not DD/MM/YYYY."

unticked=$(grep -cE '^[[:space:]]*- \[ \]' "$record" || true)
if [ "$unticked" -gt 0 ]; then
    err "$unticked unticked item(s). Tick each one, or tick it and write 'N/A: <reason>'."
    grep -nE '^[[:space:]]*- \[ \]' "$record" >&2
fi
ticked=$(grep -ciE '^[[:space:]]*- \[x\]' "$record" || true)
[ "$ticked" -gt 0 ] || err "no ticked items."

# Every audit finding row needs a decision.
if grep -nE '^\|[[:space:]]*[0-9]+[[:space:]]*\|' "$record" | grep -E '\|[[:space:]]*\|[[:space:]]*$|\|[[:space:]]*\|[^|]*\|[[:space:]]*$' >/dev/null; then
    err "an audit finding row has an empty Decision or Note cell."
fi

if [ "$fail" -ne 0 ]; then
    exit 1
fi
echo "test record: $record OK ($ticked items ticked)."
