# Testing plan — local_myplugin

This is the standing test plan. Each release also gets a filled-in record in
`tests/release/<tag>.md` (copied from `tests/release/TEMPLATE.md`); the release workflow checks it
and attaches it to the GitHub release next to the zip.

Both files are `export-ignore`d, so they are not in the installable zip.

## What runs automatically

| When | Check | Catches (audit IDs) |
|---|---|---|
| Every push / MR | moodle-plugin-ci: phplint, phpcs, phpdoc, validate, savepoints, mustache, grunt | Style, docs, templates, AMD build |
| Every push / MR | `security-lint` (moodle-security-ci) | S1, S3, S5–S9, S11, S12, S14–S20, P1–P4, Q3, Q4, Q6–Q8 patterns |
| Every push / MR | OSV known-vulnerability check | S20/S21 for lockfiles |
| Every push / MR | PHPMD unused-code ruleset (blocking) | Q7 unused variables, private methods, private fields |
| Every push / MR | PHPUnit, including the security tests below | P1 by measurement, S12, S17, S5, S10, S4, S2/S19 |
| Every push / MR | Behat (one cell) | User journeys |
| Release (tag published) | Tag = `$plugin->release`, security-lint, OSV, test record complete | Releasing untested or unreviewed code |

## Security and performance tests in this plugin

Keep one row per data-provider entry current. A new loop over site data, auditable action, adhoc
task, form, web service or template is not finished until it has a row here and in the test.

| Test file | Covers | Entries |
|---|---|---|
| `tests/query_scaling_test.php` | Reads stay flat as data grows (P1/P3) | e.g. CSV import, template build |
| `tests/events_test.php` | Each create/update/delete/export fires an event (S12) | e.g. delete submission, PDF export |
| `tests/privacy_test.php` | Erasure clears data and queued tasks (S17) | Personal and non-personal task lists |
| `tests/output_safety_test.php` | Hostile values stay inert (S5/S10) | Sanitisers, templates, URL helper, external data |
| `tests/forms_and_access_test.php` | Forms set types; web services refuse unprivileged users (S4/S2/S19) | Each form, each external function |

## Manual checks every release

These cannot be automated reliably, so they are in the release record:

1. Second read of every auth/permission logic change (S0), and role matrix on new or changed pages and web
   services (S1/S2).
2. State-changing actions are POST + sesskey; a pasted GET URL changes nothing (S8); destructive actions
   confirm first (S13).
3. Hostile-input payloads in every new free-text field, URL field, admin setting and external feed (S3/S5/S10).
4. Privacy export and delete through the Data requests tool (S17).
5. Bulk paths against a large dataset, with timings (P1/P3/P4).
6. Upgrade from the previous release, backup/restore, lowest and highest supported versions.
7. Moodle Audit Service report on the release commit, every finding triaged.

## Suppressions

`security-lint` findings that are deliberate are suppressed in the code, with a reason:

```php
// Security-lint: ignore P1 (bounded: loops over the three fixed template types).
```

Each release record lists suppressions added since the previous release, so they are reviewed once.

## If the release gate fails

The gates check the code at the tag, so a fix merged to `main` afterwards is not in it. If a gate fails
because of the code or the record, delete the release and its tag (nothing was attached), fix on `main`
and publish again. Re-run the workflow only for a temporary failure (network, GitHub outage).
