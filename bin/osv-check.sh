#!/usr/bin/env bash
# S20/S21: known-vulnerability check for third-party code in a Moodle plugin.
#
# Usage: osv-check.sh <plugin-dir>
#
# 1. Scans every lockfile in the plugin (composer.lock, package-lock.json, yarn.lock, ...) against the
#    OSV database with a pinned, checksum-verified osv-scanner. Fails if any advisory matches.
# 2. Lists libraries declared in thirdpartylibs.xml that no lockfile lists (in their folder, or a composer.lock /
#    package-lock.json above it that names their path), because OSV cannot match them by itself. Those need the
#    manual check in the release record (section 7). Also warns when a declared version differs from the lockfile.
set -euo pipefail

plugin="${1:?plugin dir}"
version="v2.6.0"
sha256="ca69b3d3cd08f889a49dc0a383122f71cc528b83803671df5fd874d97485b108"
bin="${RUNNER_TEMP:-/tmp}/osv-scanner-${version}"

if [ ! -x "$bin" ]; then
    curl -sSfL -o "$bin" "https://github.com/google/osv-scanner/releases/download/${version}/osv-scanner_linux_amd64"
    echo "${sha256}  ${bin}" | sha256sum -c --quiet -
    chmod +x "$bin"
fi

status=0
"$bin" scan source -r "$plugin" || status=$?
case "$status" in
    0) echo "osv-check: no known vulnerabilities in lockfiles." ;;
    128) echo "osv-check: no lockfiles in the plugin (nothing for OSV to match)." ;;
    1) echo "::error::osv-check: known vulnerabilities found (see the table above)."; exit 1 ;;
    *) echo "::error::osv-check: osv-scanner failed with exit code $status."; exit "$status" ;;
esac

# Libraries in thirdpartylibs.xml that no lockfile lists need the manual check in the release record.
php "$(dirname "$0")/thirdparty-lockfile-check.php" "$plugin"
