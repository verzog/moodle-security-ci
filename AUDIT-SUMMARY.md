# Consolidated audit findings — 05/11/2026

Source: Moodle Audit Service reports for four plugins (AI-generated; each finding still needs a human check).

| Plugin | Source audited | Findings | Medium | Low |
|---|---|---|---|---|
| mod_casestudy | ZIP v1.3 (2026110301) | 62 + 206 static-analysis diagnostics | 13 | 49 |
| local_membershipbridge | GitHub `main` @ 77e58e0 | 17 | 3 | 14 |
| tiny_bootstrap | GitHub `main` @ 33b22d2 | 5 | 1 | 4 |
| local_grpcalendarimport | GitHub `main` @ 0cd5091 | 2 | 2 | 0 |
| **Total** | | **86** | **19** | **67** |

No Critical or High findings in any plugin.

## By check, across all four plugins

| ID | What it means | casestudy | membership | bootstrap | grpcal | Total | Root cause pattern |
|---|---|---|---|---|---|---|---|
| P1 | Query inside a loop over growing data | 13 | 7 | – | 2 | **22** | `$DB->get_*` / `record_exists` / `grade_item::fetch` / `is_enrolled` per row instead of one preload |
| Q7 | Unused / dead code | 26 | – | 1 | – | **27** | Leftover variables, imports, methods, placeholder stubs |
| S5 | Output not encoded for its context | 7 | 1 | 4 | – | **12** | `innerHTML` / modal title from decoded text; `href` with no scheme check; single-pass `</style` strip; exception text into `notification()`; raw `<textarea>` |
| S12 | Auditable action fires no event | 5 | 3 | – | – | **8** | Deletes, bulk exports and pushes to iMIS with no `\core\event` |
| P3 | Heavy/external work on the web request | 4 | – | – | (1) | **4–5** | Synchronous email, bulk enrolment, image resize, whole-file import |
| S8 | State change via GET | 2 | 1 | – | – | **3** | `confirm_sesskey()` with no POST requirement; record created on page load |
| S10 | External data not cleaned | – | 3 | – | – | **3** | iMIS names/group names stored raw; erasure leaves queued tasks that still send data |
| S14 | Capability risk flags | 1 | 1 | – | – | **2** | Write capability with no `riskbitmask`; view-all capability without `RISK_PERSONAL` |
| S3 | Request value not allow-listed | 1 | – | – | – | **1** | `mode` param used as a DB column name |
| S4 | Form field without `setType()` | 1 | – | – | – | **1** | Override form |
| S18 | Missing MOODLE_INTERNAL guard | 1 | – | – | – | **1** | `define()` at top of `lib.php` |
| P2 | Missing index | 1 | – | – | – | **1** | `casestudy_submissions.parentid` |
| P4 | Blocking wait on request | – | 1 | – | – | **1** | 10-second lock wait in a grade observer |

### What this says

1. **Performance in loops is the biggest single risk** (22 findings, 7 of the 19 Mediums). It happens in every
   plugin that processes lists. No existing CI check looks for it.
2. **Events are consistently missing** for deletes, exports and outbound pushes of learner data. This is a
   design habit rather than a one-off slip, so it needs a test per action, not just a lint.
3. **Output encoding fails at the edges**: JavaScript sinks, URLs, the inside of `<style>`/`<textarea>`, and text
   from external systems. Normal `{{ }}` and `format_string()` output was not a problem.
4. **Privacy erasure was incomplete in one plugin** (queued tasks still sending data after a delete request).
   Presence of `provider.php` is not enough; erasure needs a test.
5. **Dead code (27)** is low-risk but noisy; one blocking PHPMD rule set clears most of it.

## Static-analysis diagnostics (mod_casestudy only)

| Diagnostic | Count | Real CI would… | Action |
|---|---|---|---|
| `BoilerplateComment.NoBoilerplateComment` | 96 | Pass (excluded by your Mode A `.phpcs.xml`) | None — expected for Mode A |
| `FileExpectedTags.LicenseTagInvalid` (warning) | 96 | **Fail** with `--max-warnings 0` (moodle-cs ≥ 3.4.2) unless configured | Use `templates/mode-a.phpcs.xml` |
| `MoodleInternal.MoodleInternalNotNeeded` | 2 | **Fail** | Remove the guard in `classes/local/submission_pdf.php`, `classes/table/submission_table_filterset.php` |
| `PSR12.Properties.ConstantVisibility.NotFound` | 7 | Pass (moodle-cs sets severity 0) | Optional: add `public`/`private` to the constants |
| `MUSTACHE_PARSE` in `templates/local/modal/lightbox.mustache` | 5 | Probably pass | Likely the audit's parser not understanding `{{< core/modal}}` block syntax; confirm with `moodle-plugin-ci mustache` |

Because the two items marked **Fail** would stop CI, either mod_casestudy's CI is not running on the code that
was zipped, or it uses an older moodle-cs. Worth checking before anything else.

## How well the new lint covers these findings

Measured by running `security-lint` on the exact commits audited (mod_casestudy source was not available):

| Plugin | Audit findings | Flagged at the reported location | Partly | Missed |
|---|---|---|---|---|
| local_grpcalendarimport | 2 | 1 | – | 1 (query hidden in a helper called per row) |
| tiny_bootstrap | 5 | 4 | – | 1 (unused require in `.grunt/`, not scanned) |
| local_membershipbridge | 17 | 12 | 2 | 3 (two S10 cleaning gaps; one helper-hidden P1) |
| **Total** | **24** | **17** | **2** | **5** |

The misses are what the PHPUnit layer is for: the query-scaling test catches queries hidden inside helpers,
and the output-safety test covers cleaning of external data.

The lint also reports things the audit did not (for example every `innerHTML` in tiny_bootstrap's
`commands.js`, `PARAM_RAW` settings, and the RISK_CONFIG capability granted to managers in grpcalendarimport).
Expect roughly 10–40 findings per plugin on the first run; most need a fix, the rest a one-line suppression
with a reason.
