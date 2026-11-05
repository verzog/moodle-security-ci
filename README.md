# moodle-security-ci

A shared CI and release-testing process for Moodle plugins, built from the recurring findings in the
Moodle Audit Service reports of 05/11/2026 (see `AUDIT-SUMMARY.md`). It adds three layers on top of the
existing moodle-plugin-ci checks, and a release gate.

| Layer | What | Runs | Catches |
|---|---|---|---|
| 1. Static lint | `bin/security-lint.php` + PHPMD unused-code rules | Every push/MR, ~1 minute, no Moodle install | Patterns: queries in loops, risky output sinks, missing events/risk flags, GET writes, debug code |
| 2. Behaviour tests | PHPUnit templates in `templates/tests/` | Existing PHPUnit matrix | What a pattern can't see: queries hidden in helpers, events per action, privacy erasure, hostile input |
| 3. Manual release checks | `TESTING.md` + `tests/release/<tag>.md` record | Each release | Role matrix, POST/sesskey in the browser, payloads in real UI, large-data timings, upgrade |
| Gate | `ci/github/release.yml` | Publishing a GitHub release | No zip unless lint and OSV are clean and the test record is complete; the record is attached to the release |

## Layout

```
bin/security-lint.php          The lint (PHP 8.2+, no dependencies)
bin/check-test-record.sh       Release-record checker (used by the release gate)
bin/osv-check.sh               Known-vulnerability check for lockfiles (S20/S21)
config/phpmd-unused.xml        Blocking unused-code rules (Q7)
ci/github/moodle-ci-security-job.yml   Job to add to each plugin's moodle-ci.yml
ci/github/release.yml          Full replacement "Release zip" workflow with the gates
ci/gitlab/security-job.gitlab-ci.yml   Same checks for self-hosted GitLab
templates/TESTING.md           Standing test plan for a plugin
templates/tests/release/TEMPLATE.md    Per-release test record
templates/tests/*.php          PHPUnit security/performance test templates
templates/mode-a.phpcs.xml     Mode A .phpcs.xml that passes the licence-tag sniff
templates/gitattributes.txt    export-ignore line for TESTING.md
selftest/                      Fixture plugins: one where every rule fires, one that must stay clean
```

## Rules

Run `php bin/security-lint.php --list-rules` for the full list. Every rule is named after the audit check ID.
`CHECKLIST-COVERAGE.md` maps every ID on the Moodle Audit Service checklist to the rule, test or manual check
that covers it.

| Rule | Audit ID | Flags |
|---|---|---|
| `S1-no-login`, `S1-login-late` | S1 | Page script that shows/changes data with no `require_login()`/`require_course_login()`/`admin_externalpage_setup()`, or output/writes before it |
| `S3-superglobal`, `S3-param-raw` | S3 | `$_GET`/`$_POST`/…; `PARAM_RAW` (outside web-service definitions) |
| `S3-dynamic-column` | S3 | `$record->$var = …` in a file that writes records |
| `S5-php-exception` | S5 | `$e->getMessage()` into `get_string()`/`notification()`/`echo` without `s()` |
| `S5-php-strip` | S5 | `preg_replace`/`str_replace` that strips `</style` or `</script` (bypassable) |
| `S5-php-textarea` | S5 | Unescaped value inside `<textarea>` (`html_writer::tag()` does not escape) |
| `S5-js-html` | S5 | `innerHTML`, `outerHTML`, `insertAdjacentHTML`, jQuery `.html(x)`, `document.write` |
| `S5-js-href` | S5 | `href="${…}"` or `setAttribute('href', var)` without a `safeUrl()` helper |
| `S5-mustache-raw` | S5 | `{{{ }}}` in a template |
| `S6-external-asset` | S6 | `<script src="https://…">`, external `<link>`, `$PAGE->requires->js('https://…')`, `createElement('script')` |
| `S7-sql-variable` | S7 | A value variable interpolated or concatenated into SQL text (fragment names like `$where`, `$insql`, `$groupidin` are allowed) |
| `S8-get-write` | S8 | Entry point that changes state and checks sesskey but never requires POST |
| `S9-shell` | S9 | `exec`, `system`, `passthru`, `shell_exec`, `popen`, `proc_open`, backticks |
| `S11-secret-log` | S11 | A `$password`/`$token`/`$secret`/`$apikey` variable in `mtrace`, `debugging`, `error_log`, a logger or an exception |
| `S12-no-events`, `S12-delete-no-event` | S12 | No `classes/event/` at all; a function that deletes records without triggering an event |
| `S14-write-no-risk`, `S14-risky-archetype`, `S14-personal` | S13/S14 | `db/access.php` risk flags (and your §4.2 rule) |
| `S14-unreadable` | S14 | `db/access.php` uses code the static reader will not evaluate (it is never executed) |
| `S15-file-serve` | S15 | `readfile`, `send_file`, `send_stored_file`… outside `<component>_pluginfile()` |
| `S16-sesskey-leak` | S16 | `sesskey()` in logs, event `other` data or a URL to another domain (PHP and JS) |
| `S17-no-provider`, `S17-task-not-erased` | S17 | No privacy provider; adhoc task the provider never mentions |
| `S18-no-guard` | S18 | Top-level `define()`/`require` in a file with no MOODLE_INTERNAL guard or config.php bootstrap |
| `S19-external` | S19 | `classes/external` `execute()` without `validate_parameters()` (result used), `validate_context()` and a capability check (same-file helpers are followed) |
| `S20-undeclared`, `S20-missing-location` | S20 | Vendored code (`vendor/`, `*.min.js`, nested LICENSE/composer.json) not in `thirdpartylibs.xml`; declared paths that don't exist |
| `P1-loop-query` | P1 | `$DB` query, `grade_item::fetch()`, `is_enrolled()`, `groups_get_all_groups()` etc. inside `foreach`/`for`/`while`, directly or through functions and methods in the same file (getters that cache their result are skipped) |
| `P2-no-index` | P2 | `$DB->get_records('plugin_table', ['col' => …])` where `col` is not the first column of a key/index in `install.xml` |
| `P3-request-io` | P3 | `email_to_user()`, `message_send()`, `curl`, `SoapClient` in entry points, `lib.php`, external/output/form classes, observers |
| `P4-sleep`, `P4-lock-wait` | P4 | `sleep()` or a lock wait over 2 s outside CLI/task code |
| `Q3-core-global` | Q3 | Replacing or writing `$CFG`/`$USER`/`$COURSE`/`$SITE` properties; `$SESSION` keys not prefixed with the component |
| `Q4-no-tests`, `Q6-no-readme` | Q4/Q6 | No `tests/*_test.php`; no non-empty README |
| `Q7-debug`, `Q7-unused-use` | Q7 | `var_dump`/`print_r`/`var_export`; unused `use` imports |
| `Q8-deprecated` | Q8 | `add_to_log`, `print_error`, `get_context_instance`, `get_referer`, `cron_setup_user`…; `externallib.php`; global `external_api`; lib.php callbacks replaced by hooks |

Also in CI: `bin/osv-check.sh` (S20/S21) checks lockfiles against the OSV vulnerability database with a pinned,
checksum-verified osv-scanner, and lists declared libraries without a lockfile for a manual check.

### Suppressing a finding

Put this on the flagged line or up to three lines above it. The reason in brackets is required.

```php
// Security-lint: ignore P1 (bounded: loops over the three fixed template types).
```

The comment follows the phpcs inline-comment rule (capital letter, ends with a full stop). The same syntax works
in JS (`//`) and Mustache (`{{! … }}`). For `db/access.php`, put it anywhere inside the capability's array.
For an adhoc task with no personal data, put `Security-lint: ignore S17 (reason)` anywhere in the task file.

The workflow snippets pin GitHub Actions to full commit SHAs (with the version in a comment), so a moved or
compromised tag cannot change what runs, which matters most in the release workflow, which can write to
releases. Dependabot keeps this repo's pins current; copy new pins into plugin repos when you bump
`SECURITY_CI_REF`.

## Adopting it in a plugin

1. **Publish this repo** (suggested `verzog/moodle-security-ci`, public, GPLv3+) and tag `v1.2.1`. It is
   dev tooling and is never packaged into a plugin zip, so it is fine for Mode A and C repos to use it.
2. **First run, report only.** In the plugin checkout:
   `php ../moodle-security-ci/bin/security-lint.php --report-only .`
3. **Triage.** Fix what is real; suppress what is deliberate, with a reason. Do this as its own PR.
4. **Enforce.** Add the job from `ci/github/moodle-ci-security-job.yml` (or the GitLab job), and remove the old
   non-blocking "PHP Mess Detector" step.
5. **Add tests.** Copy the relevant files from `templates/tests/`, rename `local_myplugin`, use the header for the
   plugin's licence mode, and fill the data providers. Not every plugin needs all five — see the table in
   `templates/TESTING.md`.
6. **Release gate.** Copy `templates/TESTING.md` to the plugin root, `templates/tests/release/TEMPLATE.md` to
   `tests/release/TEMPLATE.md`, add the line from `templates/gitattributes.txt`, and replace
   `.github/workflows/release.yml` with `ci/github/release.yml`.

## Release process (replaces the checklist in §1.1)

1. On a branch: bump `version.php` (`version`, `release`), copy `tests/release/TEMPLATE.md` to
   `tests/release/v1.3.0.md`, and work through it. Tick each item (or tick and write `N/A: reason`).
2. Run the Moodle Audit Service on the branch and fill the triage table.
3. Set `Result: PASS`, merge to the plugin repo's `main`, confirm CI is green on that commit.
4. Publish the GitHub release with tag `v1.3.0`. The workflow checks the tag, re-runs the lint, checks the record,
   then attaches `<component>-v1.3.0.zip`, `<component>-v1.3.0.zip.sha256` and `<component>-v1.3.0-test-record.md`.
5. Confirm all three files are on the release.

## Self-test

`bash selftest/run.sh` runs the lint over `selftest/fixture_plugin`, which has one `EXPECT:<rule>` marker per
expected finding and several suppressed or safe look-alikes that must stay quiet, then over
`selftest/clean_plugin`, which uses the safe version of every pattern and must produce no findings (from the
lint or the PHPMD ruleset), then over `selftest/hostile_plugin`, whose `db/access.php` writes a canary file if it
is ever executed. CI also runs ShellCheck on the scripts and moodle-cs on `templates/tests/`.

## Limits

The lint never runs plugin code. Every file, `db/access.php` included, is read as text or with PHP's tokenizer,
so it is safe to point at a plugin you have not reviewed yet.

This is a pattern lint, not a data-flow analyser. It follows helpers in the same file, but will miss a query
inside a helper in another file that is called from a loop, and it cannot tell whether a value reaching
`innerHTML` was already sanitised. The PHPUnit layer and the release checks exist for exactly those gaps. It also does not replace a human review of the audit reports.
