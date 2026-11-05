# Changelog

## v1.2.1 — 06/11/2026

- Fixed false positives found adopting the lint on local_codechecker:
  - Language files (`lang/`) are no longer checked by the PHP line rules. Their strings often name the very
    functions the rules look for (for example "No sleep() on the request path"), and lang files cannot hold
    suppression comments.
  - `S3-superglobal`, `S3-param-raw`, `S5-php-exception`, `Q7-debug` and `P4-sleep` ignore text inside plain
    string literals, so a sniff or rule list that names `'$_GET'` or `'PARAM_RAW'` is not reported.
    Interpolated strings such as `"{$_GET['x']}"` are still checked.

## v1.2.0 — 06/11/2026

- Security: `db/access.php` is now read with the tokenizer instead of being `include`d, so the lint never
  executes plugin code. A file it cannot read statically reports the new rule `S14-unreadable`. Checked against
  all 174 `db/access.php` files in Moodle 5.1 core (762 capabilities, all read).
- Fixed: the PHPMD step in the GitHub and GitLab jobs failed on every plugin because `composer --working-dir`
  pointed at a directory that did not exist.
- Release gate also attaches `<component>-<tag>.zip.sha256`.
- CI jobs: `TZ: Australia/Sydney`, read-only `permissions` on the security job.
- Self-test: hostile fixture proving `db/access.php` is not executed; Code Quality JSON check; the clean fixture
  must also pass PHPMD; ShellCheck on the scripts; moodle-cs on `templates/tests/`; read-only permissions and
  no duplicate push/PR runs.
- `P1-loop-query` follows functions and methods in the same file: a loop that calls `$this->load_user()`,
  `self::x()` or a plain function that runs a query (directly or through further helpers) is reported at the call,
  naming the chain. Getters that cache their result (check and assign a property or static, or use the cache
  API) are skipped. Expect more P1 findings on first run.
- GitHub Actions in the workflows and snippets are pinned to commit SHAs (`actions/checkout` v4.4.0,
  `shivammathur/setup-php` 2.40.0); Dependabot keeps this repo's pins current.
- `bin/security-lint.php` passes Moodle coding style; `.phpcs.xml` documents the CLI-only exclusions and CI checks
  `bin/` with the templates.
- Added `LICENSE` (GPLv3) and `.gitattributes` (LF line endings).

## v1.1.0 — 06/11/2026

- 16 new lint rules (40 in total) so every automatable check ID on the Moodle Audit Service checklist is covered:
  S1, S6, S7, S9, S11, S15, S16, S18, S19, S20, Q3, Q4, Q6, Q8 (see `CHECKLIST-COVERAGE.md`).
- `bin/osv-check.sh`: known-vulnerability check (S20/S21) with a pinned, checksum-verified osv-scanner v2.6.0;
  added to the CI job and the release gate.
- Release record: new manual items for S0 (second read of permission logic), S13 (confirmation screens) and
  third-party advisories.
- Fixed: text inside strings (for example a string that is just ")" or ";") and `"{$var}"` interpolation braces
  were read as code, which could mis-detect loops and statements.
- Self-test now also checks a clean fixture plugin that must produce no findings.

## v1.0.0 — 06/11/2026

- First version: 24 rules from the 05/11/2026 audit reports, PHPUnit templates, release gate.
