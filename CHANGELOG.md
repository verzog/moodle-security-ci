# Changelog

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
