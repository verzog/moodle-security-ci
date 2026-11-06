#!/usr/bin/env php
<?php
// This file is part of moodle-security-ci - https://github.com/verzog/moodle-security-ci
//
// moodle-security-ci is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// moodle-security-ci is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with moodle-security-ci.  If not, see <https://www.gnu.org/licenses/>.

/**
 * S20/S21: which libraries in thirdpartylibs.xml OSV cannot match, so need a manual advisory check.
 *
 * A library is covered when a lockfile OSV reads lists it:
 *   - a composer.lock in any folder above the library (up to the plugin root) that lists the package installed
 *     at exactly that path (<vendor-dir>/<vendor>/<name>), or that is the vendor directory itself, or
 *   - a package-lock.json above it that lists exactly that path (node_modules/<name>), or
 *   - failing those, a composer.lock, package-lock.json or yarn.lock inside the library's own folder.
 * Paths are compared case-sensitively, as on the Linux runners: Vendor/acme/x is not vendor/acme/x.
 * When a parent lockfile lists the library, a different declared <version> is reported, because OSV checks the
 * lockfile's version and the declaration would be stale. A lockfile inside the library describes its
 * dependencies, not its own installed version, so it never replaces that comparison.
 *
 * Reads files only; never executes plugin code. Prints GitHub annotations and always exits 0 (warnings only).
 *
 * Usage: php thirdparty-lockfile-check.php <plugin-dir>
 *
 * @package    moodle_security_ci
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

/**
 * Packages a lockfile in a directory lists, keyed by installed path relative to that directory.
 *
 * @param string $dir Absolute directory.
 * @return array 'paths' => [relative installed path => version], 'vendordir' => composer vendor dir or null.
 */
function lockfile_packages(string $dir): array {
    $out = ['paths' => [], 'vendordir' => null];
    $composerlock = $dir . '/composer.lock';
    if (is_file($composerlock)) {
        $vendordir = 'vendor';
        $json = is_file($dir . '/composer.json') ? json_decode((string) file_get_contents($dir . '/composer.json'), true) : null;
        if (is_array($json) && is_string($json['config']['vendor-dir'] ?? null)) {
            $vendordir = trim($json['config']['vendor-dir'], '/');
        }
        $lock = json_decode((string) file_get_contents($composerlock), true);
        if (is_array($lock)) {
            $out['vendordir'] = $vendordir;
            foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $p) {
                if (is_string($p['name'] ?? null)) {
                    $out['paths'][$vendordir . '/' . $p['name']] = (string) ($p['version'] ?? '');
                }
            }
        }
    }
    $npmlock = $dir . '/package-lock.json';
    if (is_file($npmlock)) {
        $lock = json_decode((string) file_get_contents($npmlock), true);
        foreach (is_array($lock) ? ($lock['packages'] ?? []) : [] as $path => $p) {
            if (is_string($path) && str_starts_with($path, 'node_modules/')) {
                $out['paths'][$path] = (string) ($p['version'] ?? '');
            }
        }
    }
    return $out;
}

/**
 * Version string without a leading "v", for comparing declared and locked versions.
 *
 * @param string $version Version.
 * @return string
 */
function normalise_version(string $version): string {
    return ltrim(trim($version), 'vV');
}

$root = realpath($argv[1] ?? '.');
if ($root === false || !is_file($root . '/thirdpartylibs.xml')) {
    exit(0);
}
$xml = @simplexml_load_file($root . '/thirdpartylibs.xml');
if ($xml === false) {
    echo "::warning file=thirdpartylibs.xml::Could not parse thirdpartylibs.xml; check every library's advisories by hand.\n";
    exit(0);
}

$cache = [];
foreach ($xml->library as $lib) {
    $loc = trim((string) $lib->location, '/');
    $name = trim((string) $lib->name);
    $declared = trim((string) $lib->version);
    if ($loc === '' || str_contains('/' . $loc . '/', '/../')) {
        printf("::warning file=thirdpartylibs.xml::%s: location '%s' is empty or leaves the plugin; check by hand.\n", $name, $loc);
        continue;
    }

    // Walk from the folder above the library up to the plugin root, looking for a lockfile that lists it.
    $covered = null;
    $parts = explode('/', $loc);
    for ($depth = count($parts) - 1; $depth >= 0 && $covered === null; $depth--) {
        $dirrel = implode('/', array_slice($parts, 0, $depth));
        $dir = $dirrel === '' ? $root : $root . '/' . $dirrel;
        $cache[$dir] ??= lockfile_packages($dir);
        $relative = implode('/', array_slice($parts, $depth));
        $packages = $cache[$dir];
        if (array_key_exists($relative, $packages['paths'])) {
            $covered = [$dirrel === '' ? 'the plugin root' : $dirrel, $packages['paths'][$relative]];
        } else if ($relative === $packages['vendordir']) {
            // The vendor directory itself (Composer's autoloader) is managed by that composer.lock.
            $covered = [$dirrel === '' ? 'the plugin root' : $dirrel, null];
        }
    }

    // Fallback: a lockfile inside the library lets OSV match its dependencies.
    if ($covered === null && glob($root . '/' . $loc . '/{composer.lock,package-lock.json,yarn.lock}', GLOB_BRACE)) {
        printf("osv-check: %s (%s) is covered by its own lockfile.\n", $name, $loc);
        continue;
    }

    if ($covered === null) {
        printf("::warning::Check advisories by hand: %s %s (%s) — no lockfile for OSV to match.\n", $name, $declared, $loc);
        continue;
    }
    [$where, $locked] = $covered;
    printf("osv-check: %s (%s) is covered by the lockfile in %s.\n", $name, $loc, $where);
    if ($locked !== null && $declared !== '' && normalise_version($declared) !== normalise_version($locked)) {
        printf(
            "::warning file=thirdpartylibs.xml::%s: thirdpartylibs.xml declares %s but the lockfile has %s. "
                . "OSV checks the lockfile version; update the declaration.\n",
            $name,
            $declared,
            $locked
        );
    }
}
