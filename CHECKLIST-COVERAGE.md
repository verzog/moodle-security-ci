# Moodle Audit Service checklist — coverage

Each check ID on the Moodle Audit Service checklist, and what in this toolkit covers it.

- **Lint**: `bin/security-lint.php` (every push)
- **CI**: other automated steps (moodle-plugin-ci, PHPMD, OSV)
- **Test**: PHPUnit templates in `templates/tests/`
- **Release**: manual item in `templates/tests/release/TEMPLATE.md`

"Partly" means the automated check finds the common form of the problem but not every form; the
release record covers the rest.

## Security

| ID | Check (short) | Coverage | How |
|---|---|---|---|
| S0 | Security-critical logic error | Release | Code review of every auth/permission change (section 2). Not automatable. |
| S1 | Login before first protected effect | Lint (partly) + Release | `S1-no-login`, `S1-login-late`; role matrix in the release record |
| S2 | Capability checks in the right context | Test + Release | `forms_and_access_test.php` for web services; role matrix for pages |
| S3 | Input via `*_param()`, strict types | Lint | `S3-superglobal`, `S3-param-raw`, `S3-dynamic-column` |
| S4 | `setType()` on every field | Test | `forms_and_access_test.php` (fails on setType debugging) |
| S5 | Context-appropriate output encoding | Lint (partly) + Test + Release | Six `S5-*` rules; `output_safety_test.php`; payloads in the real UI |
| S6 | No external script/stylesheet loads | Lint | `S6-external-asset` (PHP, JS, Mustache) |
| S7 | SQL values bound through placeholders | Lint | `S7-sql-variable` |
| S8 | POST + sesskey for state changes | Lint (partly) + Release | `S8-get-write`; browser network-tab check |
| S9 | No shell execution | Lint | `S9-shell` |
| S10 | External data cleaned | Test + Release | `output_safety_test.php` (external names); hostile values in external feeds |
| S11 | No secrets/PII in logs or errors | Lint (partly) | `S11-secret-log` (secret-named variables only; PII in logs stays a review item) |
| S12 | Significant actions fire events | Lint + Test | `S12-no-events`, `S12-delete-no-event`; `events_test.php` |
| S13 | Bulk-destructive ops confirmed; RISK_DATALOSS | Lint (risk flags) + Release | `S14-*` rules; confirmation screen checked by hand (section 2) |
| S14 | Correct risk bitmasks | Lint | `S14-write-no-risk`, `S14-risky-archetype`, `S14-personal`, `S14-unreadable` |
| S15 | Files served via pluginfile | Lint | `S15-file-serve` |
| S16 | Sesskey kept out of logs/events/external URLs | Lint | `S16-sesskey-leak` (PHP and JS) |
| S17 | Privacy provider | Lint + Test + Release | `S17-no-provider`, `S17-task-not-erased`; `privacy_test.php`; Data requests export/delete |
| S18 | MOODLE_INTERNAL guard / bootstrap | Lint + CI | `S18-no-guard`; phpcs `moodle.Files.MoodleInternal` |
| S19 | Web service validation | Lint + Test | `S19-external`; `forms_and_access_test.php` |
| S20 | Third-party libraries declared | Lint + CI + Release | `S20-undeclared`, `S20-missing-location`; `osv-check.sh` lists libraries without lockfiles |
| S21 | Third-party source vulnerabilities | CI + Release | `osv-check.sh` (lockfiles, OSV database); manual advisory check for libraries without lockfiles |

## Code quality

| ID | Check (short) | Coverage | How |
|---|---|---|---|
| Q1 | Namespaces + PSR-4 | CI | moodle-plugin-ci `phpcs` and `validate` |
| Q2 | PHPDoc | CI | moodle-plugin-ci `phpdoc --max-warnings 0` |
| Q3 | No writes to core globals | Lint | `Q3-core-global` |
| Q4 | PHPUnit tests present | Lint | `Q4-no-tests` |
| Q5 | Optional uninstall hook | — | Optional by definition; not checked |
| Q6 | README present | Lint | `Q6-no-readme` |
| Q7 | No unused/debug code | Lint + CI | `Q7-debug`, `Q7-unused-use`; PHPMD unused-code ruleset (blocking) |
| Q8 | No deprecated APIs | Lint + CI | `Q8-deprecated`; Moodle `main` CI cell surfaces new deprecation debugging |

## Performance

| ID | Check (short) | Coverage | How |
|---|---|---|---|
| P1 | No per-row queries in loops | Lint + Test | `P1-loop-query`; `query_scaling_test.php` catches queries hidden in helpers |
| P2 | Indexes for selective predicates | Lint (partly) | `P2-no-index` (array-condition lookups; hand-written WHERE clauses stay a review item) |
| P3 | No growing/blocking work on requests | Lint (partly) + Test + Release | `P3-request-io`; `query_scaling_test.php`; large-data timings |
| P4 | No sleep/polling on requests | Lint | `P4-sleep`, `P4-lock-wait` |

## Not automatable

S0 and the confirmation step of S13 need a person. Everything else has at least one automated check, and
the "partly" items have a matching manual step in the release record.

## Validation

- `selftest/run.sh`: every rule fires on `fixture_plugin`; nothing fires on `clean_plugin`.
- Run against the audited commits of local_grpcalendarimport, tiny_bootstrap and local_membershipbridge,
  and against ten Moodle 5.1 core plugins (forum, data, feedback, glossary, assign, enrol_manual,
  tool_dataprivacy, tool_uploaduser, report_log, block_html) to find and remove false positives.
