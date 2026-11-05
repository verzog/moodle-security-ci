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
 * Static security / performance lint for Moodle plugins.
 *
 * Each rule maps to a check ID used by the Moodle Audit Service reports (S = security,
 * P = performance, Q = code quality). The script never executes plugin code: files,
 * including db/access.php, are read as text or with the PHP tokenizer.
 *
 * Usage:
 *   php security-lint.php [options] <plugin-dir>
 *
 * Options:
 *   --report-only            Print findings but always exit 0 (use when first adopting).
 *   --annotate-prefix=PATH   Prefix for GitHub annotation paths (e.g. "plugin/").
 *   --codequality=FILE       Also write a GitLab Code Quality JSON report to FILE.
 *   --list-rules             Print the rule list and exit.
 *
 * Suppress a finding on the same line or up to three lines above it with:
 *   // Security-lint: ignore P1 (bounded: loops over the three fixed template types).
 * A reason in brackets is required; a suppression without one is ignored.
 *
 * @package    moodle_security_ci
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

/** Rule catalogue: id => [audit check, short description, how to fix]. */
const RULES = [
    'S3-superglobal' => ['S3', 'Direct superglobal access', 'Use optional_param()/required_param() or $SESSION.'],
    'S3-param-raw' => ['S3', 'PARAM_RAW input', 'Use a stricter PARAM_* type, or suppress with the justification.'],
    'S3-dynamic-column' => [
        'S3',
        'Variable used as a property/column name in a record that is written to the DB',
        'Check the variable against an explicit allow-list before using it as a column name.',
    ],
    'S5-php-exception' => [
        'S5',
        'Exception message output without escaping',
        'Wrap $e->getMessage() in s() before get_string()/notification()/echo.',
    ],
    'S5-php-strip' => [
        'S5',
        'Single-pass strip of </style or </script (bypassable, e.g. "<</style/style>")',
        "Escape every '<' instead (str_replace('<', '\\\\3c ', \$css)) or reject '<' on save.",
    ],
    'S5-php-textarea' => [
        'S5',
        'Value placed inside <textarea> without escaping',
        'Escape with s() — html_writer::tag() does not escape its content.',
    ],
    'S5-js-html' => [
        'S5',
        'HTML sink in JavaScript (innerHTML, insertAdjacentHTML, .html(), document.write)',
        'Use textContent / DOM building, or pass through the plugin\'s sanitiser and suppress with the reason.',
    ],
    'S5-js-href' => [
        'S5',
        'URL placed into href/action from a variable without scheme validation',
        'Pass it through a safeUrl() helper that allows only http, https, mailto and tel.',
    ],
    'S5-mustache-raw' => [
        'S5',
        'Unescaped triple-stache {{{ }}} in a template',
        'Use {{ }} or make sure the value is already format_text()/format_string() output, then suppress.',
    ],
    'S8-get-write' => [
        'S8',
        'Entry point checks sesskey but never requires POST',
        'Gate state changes on data_submitted() && confirm_sesskey(), or use a moodleform.',
    ],
    'S12-no-events' => [
        'S12',
        'Plugin writes data or sends data externally but defines no events (classes/event/ is empty)',
        'Add event classes and trigger them for create/update/delete/export actions.',
    ],
    'S12-delete-no-event' => [
        'S12',
        'Function deletes records but triggers no event',
        'Trigger a *_deleted event after the delete (or suppress if the caller triggers it).',
    ],
    'S14-write-no-risk' => ['S14', 'Write capability without riskbitmask', 'Add the appropriate RISK_* flags.'],
    'S14-risky-archetype' => [
        'S14',
        'RISK_CONFIG/XSS/DATALOSS capability granted to archetypes by default',
        'Remove the archetypes or justify with a suppression comment in db/access.php.',
    ],
    'S14-unreadable' => [
        'S14',
        'db/access.php could not be read without executing it',
        'Keep $capabilities = [...] a plain array of strings, numbers and CAP_/CONTEXT_/RISK_ constants.',
    ],
    'S14-personal' => [
        'S14',
        'Capability that exposes other users\' data lacks RISK_PERSONAL',
        'Add RISK_PERSONAL to the riskbitmask.',
    ],
    'S17-no-provider' => ['S17', 'No privacy provider', 'Add classes/privacy/provider.php (null_provider if no data).'],
    'S17-task-not-erased' => [
        'S17',
        'Adhoc task class is not referenced in the privacy provider',
        'If the task carries user data, delete its queued rows on erasure; otherwise suppress in the task file.',
    ],
    'P1-loop-query' => [
        'P1',
        'Database query (or query helper) inside a loop',
        'Preload with get_in_or_equal()/get_records_list() before the loop and look up from an array.',
    ],
    'P2-no-index' => [
        'P2',
        'Plugin table queried on a column with no key or index',
        'Add an INDEX/KEY on that column in db/install.xml (with an upgrade step).',
    ],
    'P3-request-io' => [
        'P3',
        'Email or external HTTP/SOAP call on a request path',
        'Queue it as an adhoc task.',
    ],
    'P4-sleep' => ['P4', 'sleep()/usleep() outside CLI or task code', 'Remove it or move the work to a task.'],
    'P4-lock-wait' => [
        'P4',
        'Lock wait longer than 2 seconds outside CLI or task code',
        'Use a timeout of 0-2 seconds, or take the lock in the adhoc task.',
    ],
    'Q7-debug' => ['Q7', 'Debug output left in code', 'Remove var_dump()/print_r()/var_export().'],
    'Q7-unused-use' => ['Q7', 'Unused use import', 'Remove the import.'],
    'S1-no-login' => [
        'S1',
        'Entry point has no require_login()/require_course_login()/admin_externalpage_setup()',
        'Call one of them before any data access; for a public endpoint, verify a credential and suppress with the reason.',
    ],
    'S1-login-late' => [
        'S1',
        'Entry point reads data or produces output before require_login()',
        'Move require_login() (with the course/cm) above the first $DB/$OUTPUT/echo.',
    ],
    'S6-external-asset' => [
        'S6',
        'Script or stylesheet loaded from an external URL',
        'Bundle it (AMD module / plugin CSS) instead of loading it from another domain.',
    ],
    'S7-sql-variable' => [
        'S7',
        'Variable placed directly into SQL text',
        'Use a named placeholder (:name) and pass the value in $params; only fragment variables ($insql, $where...) belong in SQL.',
    ],
    'S9-shell' => [
        'S9',
        'Shell execution',
        'Avoid it; if unavoidable, wrap every argument in escapeshellarg() and suppress with the reason.',
    ],
    'S11-secret-log' => [
        'S11',
        'Possible secret (password/token/key) in a log, debug message or exception',
        'Log an identifier or a redacted value instead.',
    ],
    'S15-file-serve' => [
        'S15',
        'File sent to the browser outside the plugin\'s pluginfile callback',
        'Serve files through <component>_pluginfile() and send_stored_file() with forcedownload for untrusted files.',
    ],
    'S16-sesskey-leak' => [
        'S16',
        'sesskey placed in a log, event data or a URL to another domain',
        'Keep sesskey out of logs, events and external URLs.',
    ],
    'S18-no-guard' => [
        'S18',
        'File runs top-level code (define/require) without a MOODLE_INTERNAL guard or config.php bootstrap',
        "Add defined('MOODLE_INTERNAL') || die(); after the docblock.",
    ],
    'S19-external' => [
        'S19',
        'Web service execute() is missing a required check',
        'Call $params = self::validate_parameters(...), self::validate_context(...), then require_capability(...).',
    ],
    'S20-undeclared' => [
        'S20',
        'Third-party code not declared in thirdpartylibs.xml',
        'Declare it (location, name, version, licence) or remove it.',
    ],
    'S20-missing-location' => [
        'S20',
        'thirdpartylibs.xml declares a location that does not exist',
        'Fix the <location> path or remove the entry.',
    ],
    'Q3-core-global' => [
        'Q3',
        'Write to a core global ($CFG, $USER, $COURSE, $PAGE, $OUTPUT, $DB, $SESSION, $SITE)',
        'Use core APIs (set_config(), set_user_preference(), $PAGE setters); keep $SESSION keys prefixed with the component.',
    ],
    'Q4-no-tests' => ['Q4', 'No PHPUnit tests (tests/*_test.php)', 'Add tests, starting with the templates in this repo.'],
    'Q6-no-readme' => ['Q6', 'No non-empty README in the plugin root', 'Add README.md.'],
    'Q8-deprecated' => [
        'Q8',
        'Deprecated core API',
        'Use the replacement: events instead of add_to_log(), moodle_exception instead of print_error(), '
            . 'context_*::instance() instead of get_context_instance(), core_external\\ classes, hooks instead of lib.php callbacks.',
    ],
];

/** Deprecated functions and their replacements. */
const DEPRECATED_FUNCTIONS = [
    'add_to_log' => 'an event', 'print_error' => 'throw new moodle_exception()',
    'get_context_instance' => 'context_*::instance()', 'get_system_context' => 'context_system::instance()',
    'get_referer' => 'get_local_referer()', 'cron_setup_user' => '\\core\\cron::setup_user()',
    'events_trigger' => 'an event',
];

/** Component callbacks replaced by hooks in Moodle 4.4+. */
const HOOKED_CALLBACKS = [
    'before_footer', 'before_http_headers', 'before_standard_html_head', 'before_standard_top_of_body_html',
    'add_htmlattributes', 'after_config', 'after_require_login',
];

/** Variable names that conventionally hold SQL fragments rather than values. */
const SQL_FRAGMENT_WORDS = '/(sql|where|select|join|from|order|sort|field|cond|clause|table|limit|group|having|column|fragment|'
    . 'filter|subquery|alias|prefix|names|since|^sub)/i';

/** Name endings that mean a value even when a fragment word appears (e.g. $groupid, $tablecount). */
const SQL_VALUE_ENDINGS = '/(id|ids|count|num|value|val|text|name|search|term)$/i';

/** $DB methods that run a query. */
const DB_QUERY_METHODS = '/^(get_record|get_records|get_recordset|get_field|get_fieldset|record_exists|count_records|'
    . 'insert_record|insert_records|update_record|delete_records|set_field|execute)/';

/** Core functions that run one or more queries per call. */
const QUERY_HELPERS = [
    'is_enrolled', 'groups_get_all_groups', 'groups_get_members', 'groups_get_group', 'get_coursemodule_from_instance',
    'get_coursemodule_from_id', 'get_course', 'enrol_get_instances', 'get_complete_user_data', 'email_to_user',
    'role_assign', 'role_unassign', 'get_enrolled_users', 'count_enrolled_users', 'user_get_users_by_id',
    'grade_get_grades', 'has_capability_in_accessdata',
];

/** Directories never scanned. */
const SKIP_DIRS = ['.git', 'vendor', 'node_modules', 'tests', '.grunt', 'amd/build'];

/** Constants allowed in db/access.php, with core's values. */
const ACCESS_CONSTANTS = [
    'CAP_INHERIT' => 0, 'CAP_ALLOW' => 1, 'CAP_PREVENT' => -1, 'CAP_PROHIBIT' => -1000,
    'CONTEXT_SYSTEM' => 10, 'CONTEXT_USER' => 30, 'CONTEXT_COURSECAT' => 40, 'CONTEXT_COURSE' => 50,
    'CONTEXT_MODULE' => 70, 'CONTEXT_BLOCK' => 80, 'RISK_MANAGETRUST' => 0x0001, 'RISK_CONFIG' => 0x0002,
    'RISK_XSS' => 0x0004, 'RISK_PERSONAL' => 0x0008, 'RISK_SPAM' => 0x0010, 'RISK_DATALOSS' => 0x0020,
    'true' => true, 'false' => false, 'null' => null,
];

/**
 * Reads $capabilities from db/access.php with the tokenizer, without executing the file.
 *
 * Accepts array literals ([] or array()), strings, integers, the constants in ACCESS_CONSTANTS,
 * unary minus and the | operator. Anything else throws UnexpectedValueException (code = line).
 */
final class access_parser {
    /** @var array Significant tokens (whitespace and comments removed). */
    private array $tokens = [];

    /** @var int Current token index. */
    private int $pos = 0;

    /**
     * Constructor.
     *
     * @param string $code Contents of db/access.php.
     */
    public function __construct(string $code) {
        foreach (token_get_all($code) as $t) {
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                continue;
            }
            $this->tokens[] = $t;
        }
    }

    /**
     * The value assigned to $capabilities.
     *
     * @return array
     */
    public function capabilities(): array {
        $count = count($this->tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $this->tokens[$i];
            if (is_array($t) && $t[0] === T_VARIABLE && $t[1] === '$capabilities' && ($this->tokens[$i + 1] ?? null) === '=') {
                $this->pos = $i + 2;
                $value = $this->value();
                if (!is_array($value)) {
                    $this->fail('$capabilities is not an array');
                }
                return $value;
            }
        }
        $this->fail('no "$capabilities = [...]" assignment found');
    }

    /**
     * Parse one value, including "a | b | c".
     *
     * @return mixed
     */
    private function value(): mixed {
        $value = $this->operand();
        while (($this->tokens[$this->pos] ?? null) === '|') {
            $this->pos++;
            $right = $this->operand();
            if (!is_int($value) || !is_int($right)) {
                $this->fail('"|" used on a non-integer');
            }
            $value |= $right;
        }
        return $value;
    }

    /**
     * Parse a literal, constant or array.
     *
     * @return mixed
     */
    private function operand(): mixed {
        $t = $this->tokens[$this->pos] ?? null;
        if ($t === '[') {
            $this->pos++;
            return $this->items(']');
        }
        if ($t === '-') {
            $this->pos++;
            $value = $this->operand();
            if (!is_int($value)) {
                $this->fail('"-" used on a non-integer');
            }
            return -$value;
        }
        if (is_array($t) && $t[0] === T_ARRAY && ($this->tokens[$this->pos + 1] ?? null) === '(') {
            $this->pos += 2;
            return $this->items(')');
        }
        if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
            $this->pos++;
            $body = substr($t[1], 1, -1);
            return $t[1][0] === "'" ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body) : stripcslashes($body);
        }
        if (is_array($t) && $t[0] === T_LNUMBER) {
            $this->pos++;
            return intval($t[1], 0);
        }
        if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
            $name = ltrim($t[1], '\\');
            $key = array_key_exists($name, ACCESS_CONSTANTS) ? $name : strtolower($name);
            if (array_key_exists($key, ACCESS_CONSTANTS)) {
                $this->pos++;
                return ACCESS_CONSTANTS[$key];
            }
        }
        $this->fail('unsupported code "' . (is_array($t) ? $t[1] : (string) $t) . '"');
    }

    /**
     * Parse array items up to the closing bracket.
     *
     * @param string $close ']' or ')'.
     * @return array
     */
    private function items(string $close): array {
        $out = [];
        while (($this->tokens[$this->pos] ?? null) !== $close) {
            $first = $this->value();
            $arrow = $this->tokens[$this->pos] ?? null;
            if (is_array($arrow) && $arrow[0] === T_DOUBLE_ARROW) {
                $this->pos++;
                if (!is_int($first) && !is_string($first)) {
                    $this->fail('array key is not a string or integer');
                }
                $out[$first] = $this->value();
            } else {
                $out[] = $first;
            }
            $next = $this->tokens[$this->pos] ?? null;
            if ($next === ',') {
                $this->pos++;
            } else if ($next !== $close) {
                $this->fail('expected "," or "' . $close . '"');
            }
        }
        $this->pos++;
        return $out;
    }

    /**
     * Stop parsing.
     *
     * @param string $why Reason.
     * @return never
     */
    private function fail(string $why): never {
        $t = $this->tokens[min($this->pos, count($this->tokens) - 1)] ?? null;
        throw new UnexpectedValueException($why, is_array($t) ? (int) $t[2] : 0);
    }
}

/**
 * The linter.
 */
final class security_lint {
    /** @var string Plugin root directory. */
    private string $root;

    /** @var array List of findings: [rule, relpath, line, detail]. */
    private array $findings = [];

    /** @var string[] Relative paths excluded via thirdpartylibs.xml. */
    private array $thirdparty = [];

    /** @var array Cached file lines keyed by relative path. */
    private array $lines = [];

    /** @var string Plugin component (frankenstyle) from version.php, or '' if unknown. */
    private string $component = '';

    /**
     * Constructor.
     *
     * @param string $root Plugin root directory.
     */
    public function __construct(string $root) {
        $this->root = rtrim($root, '/');
        $this->load_thirdparty();
        $version = $this->root . '/version.php';
        if (is_file($version)
                && preg_match('/\$plugin->component\s*=\s*[\'"](\w+)[\'"]/', (string) file_get_contents($version), $m)) {
            $this->component = $m[1];
        }
    }

    /**
     * Run every rule.
     *
     * @return array Findings.
     */
    public function run(): array {
        $files = $this->collect_files();
        foreach ($files as $rel) {
            $abs = $this->root . '/' . $rel;
            $code = (string) file_get_contents($abs);
            $this->lines[$rel] = preg_split('/\R/', $code);
            if (str_ends_with($rel, '.php')) {
                $this->php_line_rules($rel, $code);
                $this->php_token_rules($rel, $code);
            } else if (str_ends_with($rel, '.js')) {
                $this->js_rules($rel);
            } else if (str_ends_with($rel, '.mustache')) {
                $this->mustache_rules($rel);
            }
        }
        $this->plugin_rules($files);
        $this->apply_suppressions();
        usort($this->findings, fn($a, $b) => [$a[1], $a[2], $a[0]] <=> [$b[1], $b[2], $b[0]]);
        return $this->findings;
    }

    /**
     * Read thirdpartylibs.xml so declared libraries are skipped.
     */
    private function load_thirdparty(): void {
        $file = $this->root . '/thirdpartylibs.xml';
        if (!is_file($file)) {
            return;
        }
        $xml = @simplexml_load_file($file);
        if ($xml === false) {
            return;
        }
        foreach ($xml->library as $lib) {
            $this->thirdparty[] = trim((string) $lib->location, '/');
        }
    }

    /**
     * List scannable files relative to the plugin root.
     *
     * @return string[] Relative paths.
     */
    private function collect_files(): array {
        $out = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                function (SplFileInfo $f) {
                    $rel = $this->rel($f->getPathname());
                    if (preg_match('#(^|/)(tests|amd/build|node_modules|vendor)(/|$)#', $rel)) {
                        return false;
                    }
                    foreach (array_merge(SKIP_DIRS, $this->thirdparty) as $skip) {
                        if ($rel === $skip || str_starts_with($rel, $skip . '/')) {
                            return false;
                        }
                    }
                    return true;
                }
            )
        );
        foreach ($it as $f) {
            if ($f->isFile() && preg_match('/\.(php|js|mustache)$/', $f->getFilename())) {
                $rel = $this->rel($f->getPathname());
                if (str_ends_with($rel, '.js') && !str_starts_with($rel, 'amd/src/')) {
                    continue;
                }
                $out[] = $rel;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Path relative to the plugin root.
     *
     * @param string $abs Absolute path.
     * @return string
     */
    private function rel(string $abs): string {
        return ltrim(substr($abs, strlen($this->root)), '/');
    }

    /**
     * Record a finding.
     *
     * @param string $rule Rule id.
     * @param string $rel Relative path.
     * @param int $line Line number.
     * @param string $detail Extra detail.
     */
    private function add(string $rule, string $rel, int $line, string $detail = ''): void {
        $this->findings[] = [$rule, $rel, $line, $detail];
    }

    /**
     * True when the file is a web entry point (it bootstraps config.php itself).
     *
     * @param string $rel Relative path.
     * @param string $code File contents.
     * @return bool
     */
    private function is_entry_point(string $rel, string $code): bool {
        if (preg_match('#^(classes|db|lang|cli|backup|templates|amd)/#', $rel)) {
            return false;
        }
        return (bool) preg_match('/require(_once)?\s*\(?[^;]*config\.php/', $code);
    }

    /**
     * True when the file runs on a web request path (not CLI or task code).
     *
     * @param string $rel Relative path.
     * @param string $code File contents.
     * @return bool
     */
    private function is_request_path(string $rel, string $code): bool {
        if ($this->is_entry_point($rel, $code)) {
            return true;
        }
        return (bool) preg_match(
            '#^(lib\.php|classes/(external|output|form)/|classes/observer[^/]*\.php|classes/hook_callbacks\.php)#',
            $rel
        );
    }

    /**
     * True when the file is CLI, task or DB install/upgrade code.
     *
     * @param string $rel Relative path.
     * @return bool
     */
    private function is_background(string $rel): bool {
        return (bool) preg_match('#^(cli/|classes/task/|db/)#', $rel);
    }

    /**
     * Regex rules applied line by line to PHP files.
     *
     * @param string $rel Relative path.
     * @param string $code File contents.
     */
    private function php_line_rules(string $rel, string $code): void {
        $lines = $this->lines[$rel];
        $writes = (bool) preg_match('/\$DB->(update_record|insert_record)\s*\(/', $code);
        $requestpath = $this->is_request_path($rel, $code);
        $background = $this->is_background($rel);
        $external = str_starts_with($rel, 'classes/external/') || $rel === 'db/services.php';

        foreach ($lines as $i => $l) {
            $n = $i + 1;
            if (preg_match('/^\s*(\/\/|\*|#)/', $l)) {
                continue;
            }
            if (preg_match('/\$_(GET|POST|REQUEST|COOKIE|FILES|SESSION)\b/', $l)) {
                $this->add('S3-superglobal', $rel, $n);
            }
            if (!$external && preg_match('/\bPARAM_RAW\b/', $l)) {
                $this->add('S3-param-raw', $rel, $n);
            }
            if ($writes && preg_match('/->(\$\w+|\{\$[^}]+\})\s*=(?!=)/', $l)) {
                $this->add('S3-dynamic-column', $rel, $n);
            }
            if (preg_match('/getMessage\(\)/', $l)
                    && preg_match('/(get_string|notification|echo|print\b|html_writer|\$OUTPUT)/', $l)
                    && !preg_match('/(\bs\(|format_string|htmlspecialchars|s\(\s*\$\w+->getMessage)/', $l)) {
                $this->add('S5-php-exception', $rel, $n);
            }
            if (preg_match('#(preg_replace|str_ireplace|str_replace)\s*\(.*<\\\\?/(style|script)#i', $l)) {
                $this->add('S5-php-strip', $rel, $n);
            }
            if ((preg_match('/<textarea[^>]*>[\'"]?\s*\.\s*\$/i', $l)
                    || preg_match("/html_writer::tag\(\s*'textarea'\s*,\s*\\$/", $l))
                    && !preg_match('/\bs\(|htmlspecialchars/', $l)) {
                $this->add('S5-php-textarea', $rel, $n);
            }
            if (preg_match('/\bvar_dump\s*\(/', $l)
                    || preg_match('/\b(print_r|var_export)\s*\((?![^;]*,\s*true\s*\))/', $l)) {
                $this->add('Q7-debug', $rel, $n);
            }
            if (!$background && preg_match('/\b(u?sleep|time_nanosleep)\s*\(/', $l)) {
                $this->add('P4-sleep', $rel, $n);
            }
            if (!$background && preg_match('/get_lock\s*\([^;]*,\s*(\d+)\s*\)/', $l, $m) && (int) $m[1] > 2) {
                $this->add('P4-lock-wait', $rel, $n, "timeout {$m[1]}s");
            }
            if ($requestpath && preg_match(
                '/\b(email_to_user|message_send|download_file_content)\s*\(|new\s+\\\\?(curl|SoapClient)\b/',
                $l
            )) {
                $this->add('P3-request-io', $rel, $n);
            }
            if ($this->external_asset($l)) {
                $this->add('S6-external-asset', $rel, $n);
            }
            if (preg_match('/\b(mtrace|debugging|error_log|syslog)\s*\(|throw\s+new\s+\\\\?\w*exception\s*\(|'
                    . '->(log|record_call|debug|info|warning|error)\s*\(/i', $l)
                    && preg_match('/(\$|->)\w*(password|passwd|secret|token|apikey|api_key|bearer|privatekey)\w*\b(?!\s*\()/i', $l)
                    && !preg_match('/(redact|mask|strlen|empty|isset|is_null|hash|md5|sha1)\s*\(/i', $l)) {
                $this->add('S11-secret-log', $rel, $n);
            }
            if (preg_match('/sesskey\s*\(\s*\)/', $l)
                    && preg_match('/(mtrace|debugging|error_log|[\'"]other[\'"]\s*=>|->log\s*\(|logger|[\'"]https?:\/\/)/i', $l)) {
                $this->add('S16-sesskey-leak', $rel, $n);
            }
            if ($q3 = $this->core_global_write($l)) {
                $this->add('Q3-core-global', $rel, $n, $q3);
            }
            if (!str_starts_with($rel, 'lang/')
                    && preg_match('/(?<![\w$>:\\\\])(' . implode('|', array_keys(DEPRECATED_FUNCTIONS)) . ')\s*\(/', $l, $m)
                    && !preg_match('/function\s+' . $m[1] . '\b/', $l)) {
                $this->add('Q8-deprecated', $rel, $n, $m[1] . '() → ' . DEPRECATED_FUNCTIONS[$m[1]]);
            }
            if (preg_match('/require(_once)?\s*\(?.*externallib\.php/', $l)) {
                $this->add('Q8-deprecated', $rel, $n, 'externallib.php → core_external\\ classes');
            }
            if (preg_match('/extends\s+\\\\?external_api\b/', $l) && !preg_match('/use\s+core_external\\\\external_api\s*;/', $code)) {
                $this->add('Q8-deprecated', $rel, $n, 'global external_api → core_external\\external_api');
            }
            if ($this->component !== ''
                    && preg_match('/function\s+' . $this->component . '_(' . implode('|', HOOKED_CALLBACKS) . ')\s*\(/', $l, $m)) {
                $this->add('Q8-deprecated', $rel, $n, $m[1] . ' callback → hook');
            }
        }

        // S1: an entry point must log in before its first protected effect.
        if ($this->is_entry_point($rel, $code) && !preg_match('/define\s*\(\s*[\'"]CLI_SCRIPT/', $code)) {
            $login = null;
            $effect = null;
            foreach ($lines as $i => $l) {
                if (preg_match('/^\s*(\/\/|\*|#)/', $l)) {
                    continue;
                }
                if ($login === null && preg_match(
                    '/\b(require_login|require_course_login|admin_externalpage_setup|require_admin|require_user_key_login)\s*\(/',
                    $l
                )) {
                    $login = $i + 1;
                }
                if ($effect === null && preg_match(
                    '/^\s*(echo|print)\b|\$OUTPUT->header\s*\(|\$DB->(insert|update|delete|set_field|execute)\w*\s*\(|'
                        . '\b(send_file|send_stored_file|readfile)\s*\(/',
                    $l
                )) {
                    $effect = $i + 1;
                }
            }
            $protected = preg_match('/\$DB->|\$OUTPUT->|->header\s*\(|^\s*(echo|print)\b|send_\w*file\s*\(|readfile\s*\(|->render\w*\s*\(/m',
                $code)
                || preg_match('/(->|::)(push|sync|delete|remove|save|update|create|insert|reset|enrol|unenrol|suspend|import|queue|'
                    . 'send|export|download)\w*\s*\(|\bemail_to_user\s*\(/i', $code);
            if ($login === null && $protected) {
                $this->add('S1-no-login', $rel, $this->find_line($rel, '/config\.php/'));
            } else if ($effect !== null && $effect < $login) {
                $this->add('S1-login-late', $rel, $effect);
            }
        }

        // A state change is assumed when the entry point writes to the DB or calls a mutating-sounding method.
        $mutates = preg_match('/\$DB->(insert|update|delete|set_field|execute)/', $code)
            || preg_match('/(->|::)(push|sync|delete|remove|save|update|create|insert|reset|enrol|unenrol|suspend|'
                . 'import|queue|move|clone|reorder)\w*\s*\(/i', $code);
        if ($mutates && $this->is_entry_point($rel, $code)
                && preg_match('/\b(confirm_sesskey|require_sesskey)\s*\(/', $code)
                && !preg_match('/data_submitted\s*\(|->get_data\s*\(|REQUEST_METHOD|is_post_request/', $code)) {
            foreach ($lines as $i => $l) {
                if (preg_match('/\b(confirm_sesskey|require_sesskey)\s*\(/', $l)) {
                    $this->add('S8-get-write', $rel, $i + 1);
                    break;
                }
            }
        }
    }

    /**
     * True when a line loads a script or stylesheet from an external URL (PHP, JS or Mustache).
     *
     * @param string $l Line.
     * @return bool
     */
    private function external_asset(string $l): bool {
        $url = '\\\\?["\'`]?(https?:)?\/\/';
        return (bool) (preg_match('/<script\b[^>]*\bsrc\s*=\s*' . $url . '/i', $l)
            || preg_match('/<link\b[^>]*\bhref\s*=\s*' . $url . '/i', $l)
            || preg_match('/->requires->(js|css)\s*\(\s*(new\s+\\\\?moodle_url\s*\(\s*)?[\'"](https?:)?\/\//', $l)
            || preg_match('/html_writer::script\s*\([^)]*[\'"]https?:\/\//', $l)
            || preg_match('/createElement\s*\(\s*[\'"]script[\'"]\s*\)/', $l)
            || preg_match('/\b(import|require)\s*\(\s*\[?\s*[\'"]https?:\/\//', $l));
    }

    /**
     * Describe a write to a core global on this line, or return '' when there is none.
     *
     * @param string $l Line.
     * @return string
     */
    private function core_global_write(string $l): string {
        if (preg_match('/\$(CFG|USER|COURSE|PAGE|OUTPUT|DB|SESSION|SITE)\s*=(?![=>])/', $l, $m)) {
            return '$' . $m[1] . ' replaced';
        }
        if (preg_match('/\$(CFG|USER|COURSE|SITE)->(\w+)\s*(\[[^\]]*\]\s*)?(\.|\+|-)?=(?![=>])/', $l, $m)
                && !($m[1] === 'USER' && $m[2] === 'editing')) {
            return '$' . $m[1] . '->' . $m[2];
        }
        if (preg_match('/\$SESSION->(\w+)\s*(\[[^\]]*\]\s*)?(\.|\+|-)?=(?![=>])/', $l, $m)) {
            $short = $this->component === '' ? '' : substr($this->component, strpos($this->component, '_') + 1);
            // Core login/enrol redirect keys are set by plugins through this documented pattern.
            $ok = in_array($m[1], ['wantsurl', 'enrolcancel'], true) || ($this->component !== ''
                && (str_starts_with($m[1], $this->component) || ($short !== '' && str_starts_with($m[1], $short))));
            return $ok ? '' : '$SESSION->' . $m[1] . ' (not prefixed with the component)';
        }
        return '';
    }

    /**
     * True when a variable name conventionally holds an SQL fragment ($where, $insql, $groupidin...) not a value.
     *
     * @param string $name Variable name without the $.
     * @return bool
     */
    private function is_sql_fragment_name(string $name): bool {
        if (in_array($name, ['DB', 'CFG', 'this', 'in', 'notin'], true)) {
            return true;
        }
        // Results of get_in_or_equal() are usually named ...in / ...insql / ...notin.
        if (preg_match('/(in|insql|notin)$/i', $name) && !preg_match('/(admin|login|origin|plugin|domain|margin|begin)$/i', $name)) {
            return true;
        }
        return preg_match(SQL_FRAGMENT_WORDS, $name) && !preg_match(SQL_VALUE_ENDINGS, $name);
    }

    /**
     * S7: report variables placed into SQL text within one statement.
     *
     * @param array $tokens Tokens.
     * @param int $from First token index.
     * @param int $to Last token index (exclusive).
     * @param string $rel Relative path.
     */
    private function sql_statement(array $tokens, int $from, int $to, string $rel): void {
        $strong = false;
        for ($i = $from; $i < $to; $i++) {
            $t = $tokens[$i];
            if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                    && preg_match('/\b(SELECT|UPDATE|DELETE\s+FROM|INSERT\s+INTO|WHERE|JOIN)\b|(?<!\{)\{[a-z][a-z0-9_]*\}(?!\})/', $t[1])) {
                $strong = true;
                break;
            }
        }
        $inquote = false;
        $reported = [];
        for ($i = $from; $i < $to; $i++) {
            $t = $tokens[$i];
            if ($t === '"' || (is_array($t) && in_array($t[0], [T_START_HEREDOC, T_END_HEREDOC], true))) {
                $inquote = !$inquote;
                continue;
            }
            if (!is_array($t) || $t[0] !== T_VARIABLE) {
                continue;
            }
            $name = substr($t[1], 1);
            if ($this->is_sql_fragment_name($name)) {
                continue;
            }
            $prev = $this->prev_code($tokens, $i);
            $next = $this->next_code($tokens, $i);
            $prevtok = $prev === null ? null : $tokens[$prev];
            if (is_array($prevtok) && in_array($prevtok[0], [T_INT_CAST, T_DOUBLE_CAST, T_BOOL_CAST], true)) {
                continue;
            }
            // Walk back over a concatenation dot to the string piece the variable is joined to.
            $piece = null;
            if ($inquote && is_array($prevtok) && $prevtok[0] === T_ENCAPSED_AND_WHITESPACE) {
                $piece = $prevtok[1];
            } else if ($prevtok === '.') {
                $before = $this->prev_code($tokens, $prev);
                if ($before !== null && is_array($tokens[$before]) && $tokens[$before][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $piece = $tokens[$before][1];
                }
            }
            $comparison = $piece !== null
                && preg_match('/\w\s+(=|<>|!=|<=|>=|<|>|LIKE|IN)\s*\(?\s*\'?[\'"]?$/', $piece);
            $joined = $inquote || $prevtok === '.' || ($next !== null && $tokens[$next] === '.');
            if (($comparison || ($strong && $joined)) && !isset($reported[$t[2]])) {
                $reported[$t[2]] = true;
                $this->add('S7-sql-variable', $rel, $t[2], $t[1]);
            }
        }
    }

    /**
     * Token-based rules: queries in loops, deletes without events, unused imports.
     *
     * @param string $rel Relative path.
     * @param string $code File contents.
     */
    private function php_token_rules(string $rel, string $code): void {
        $tokens = token_get_all($code);
        $count = count($tokens);
        $stack = [];
        $paren = 0;
        $pendingloop = null;
        $pendingfunc = null;
        $loopdepth = 0;
        $funcs = [];
        $uses = [];
        $usetokens = [];
        $line = 1;
        $checkp1 = !str_starts_with($rel, 'db/');
        $checkdelete = !preg_match('#^(db/|backup/|classes/privacy/|classes/task/|cli/)#', $rel);
        $reported = [];
        $stmtstart = 0;
        $sideeffect = null;
        $backticks = [];
        $shell = ['exec', 'system', 'passthru', 'shell_exec', 'popen', 'proc_open', 'pcntl_exec'];
        $serve = ['readfile', 'fpassthru', 'send_file', 'send_stored_file', 'send_temp_file', 'send_content_uncached',
            'readfile_accel'];

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            $id = is_array($t) ? $t[0] : null;
            $text = is_array($t) ? $t[1] : $t;
            // Structural punctuation only comes from single-character tokens, never from string contents.
            $char = is_array($t) ? null : $t;
            if (is_array($t)) {
                $line = $t[2];
            }

            if (in_array($id, [T_FOREACH, T_FOR, T_WHILE, T_DO], true)) {
                $pendingloop = $paren;
            } else if ($id === T_FUNCTION) {
                $name = 'closure';
                $j = $this->next_code($tokens, $i);
                if ($j !== null && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $name = $tokens[$j][1];
                }
                $pendingfunc = ['paren' => $paren, 'name' => $name, 'line' => $line];
            } else if ($id === T_USE && empty($stack)) {
                $start = $i;
                $parsed = $this->parse_use($tokens, $i, $line);
                for ($k = $start; $k <= $i; $k++) {
                    $usetokens[$k] = true;
                }
                $uses = array_merge($uses, $parsed);
                continue;
            } else if ($char === '(') {
                $paren++;
            } else if ($char === ')') {
                $paren--;
            } else if ($char === ';') {
                if ($paren === 0) {
                    $this->sql_statement($tokens, $stmtstart, $i, $rel);
                    $stmtstart = $i + 1;
                }
                if ($pendingloop !== null && $paren === $pendingloop) {
                    $pendingloop = null;
                }
                if ($pendingfunc !== null && $paren === $pendingfunc['paren']) {
                    $pendingfunc = null;
                }
            } else if ($char === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $interp = $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES;
                if (!$interp) {
                    $this->sql_statement($tokens, $stmtstart, $i, $rel);
                    $stmtstart = $i + 1;
                }
                $frame = ['loop' => false, 'func' => null, 'interp' => $interp];
                if (!$interp && $pendingloop !== null && $paren === $pendingloop) {
                    $frame['loop'] = true;
                    $loopdepth++;
                    $pendingloop = null;
                } else if (!$interp && $pendingfunc !== null && $paren === $pendingfunc['paren']) {
                    $funcs[] = $pendingfunc + ['deletes' => [], 'triggers' => false, 'start' => $i, 'end' => $count - 1];
                    $frame['func'] = count($funcs) - 1;
                    $pendingfunc = null;
                }
                $stack[] = $frame;
            } else if ($char === '}') {
                $frame = array_pop($stack);
                if ($frame && $frame['loop']) {
                    $loopdepth--;
                }
                if ($frame && $frame['func'] !== null) {
                    $funcs[$frame['func']]['end'] = $i;
                }
                if (!$frame || !$frame['interp']) {
                    $this->sql_statement($tokens, $stmtstart, $i, $rel);
                    $stmtstart = $i + 1;
                }
            } else if ($char === '`' && !isset($backticks[$line])) {
                $backticks[$line] = true;
                $this->add('S9-shell', $rel, $line, 'backtick operator');
            }

            // S18: top-level define() or include.
            if ($sideeffect === null && empty($stack)) {
                if (in_array($id, [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
                    $sideeffect = $line;
                } else if ($id === T_STRING && strtolower($text) === 'define') {
                    $prev = $this->prev_code($tokens, $i);
                    $next = $this->next_code($tokens, $i);
                    $prevtok = $prev === null ? null : $tokens[$prev];
                    $ismethod = is_array($prevtok) && in_array($prevtok[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);
                    if (!$ismethod && $next !== null && $tokens[$next] === '(') {
                        $sideeffect = $line;
                    }
                }
            }

            $func = null;
            for ($s = count($stack) - 1; $s >= 0; $s--) {
                if ($stack[$s]['func'] !== null) {
                    $func = $stack[$s]['func'];
                    break;
                }
            }

            // Query detection.
            $query = null;
            if ($id === T_VARIABLE && $text === '$DB') {
                $j = $this->next_code($tokens, $i);
                $k = $j === null ? null : $this->next_code($tokens, $j);
                $arrows = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR];
                if ($k !== null && is_array($tokens[$j]) && in_array($tokens[$j][0], $arrows, true)
                        && is_array($tokens[$k]) && $tokens[$k][0] === T_STRING
                        && preg_match(DB_QUERY_METHODS, $tokens[$k][1])) {
                    $query = '$DB->' . $tokens[$k][1] . '()';
                    if ($func !== null && str_starts_with($tokens[$k][1], 'delete_records')) {
                        $funcs[$func]['deletes'][] = $line;
                    }
                }
            } else if ($id === T_STRING && in_array(strtolower($text), QUERY_HELPERS, true)) {
                $prev = $this->prev_code($tokens, $i);
                $next = $this->next_code($tokens, $i);
                $prevtok = $prev === null ? null : $tokens[$prev];
                $isdecl = is_array($prevtok) && in_array($prevtok[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true);
                if (!$isdecl && $next !== null && $tokens[$next] === '(') {
                    $query = $text . '()';
                }
            } else if (in_array($id, [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)
                    && preg_match('/(^|\\\\)grade_(item|grade)$/', $text)) {
                $j = $this->next_code($tokens, $i);
                $k = $j === null ? null : $this->next_code($tokens, $j);
                if ($k !== null && is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_COLON
                        && is_array($tokens[$k]) && in_array($tokens[$k][1], ['fetch', 'fetch_all'], true)) {
                    $query = ltrim($text, '\\') . '::' . $tokens[$k][1] . '()';
                }
            } else if ($id === T_STRING && in_array(strtolower($text), $shell, true)) {
                $prev = $this->prev_code($tokens, $i);
                $next = $this->next_code($tokens, $i);
                $prevtok = $prev === null ? null : $tokens[$prev];
                $isdecl = is_array($prevtok) && in_array($prevtok[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true);
                if (!$isdecl && $next !== null && $tokens[$next] === '(') {
                    $this->add('S9-shell', $rel, $line, $text . '()');
                }
            } else if ($id === T_STRING && in_array(strtolower($text), $serve, true)) {
                $prev = $this->prev_code($tokens, $i);
                $next = $this->next_code($tokens, $i);
                $prevtok = $prev === null ? null : $tokens[$prev];
                $isdecl = is_array($prevtok) && in_array($prevtok[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);
                $infunc = $func === null ? '' : $funcs[$func]['name'];
                if (!$isdecl && $next !== null && $tokens[$next] === '(' && !str_ends_with($infunc, '_pluginfile')) {
                    $this->add('S15-file-serve', $rel, $line, $text . '()');
                }
            } else if ($id === T_STRING && $text === 'trigger' && $func !== null) {
                $prev = $this->prev_code($tokens, $i);
                if ($prev !== null && is_array($tokens[$prev]) && $tokens[$prev][0] === T_OBJECT_OPERATOR) {
                    $funcs[$func]['triggers'] = true;
                }
            }
            if ($query !== null && $loopdepth > 0 && $checkp1 && !isset($reported[$line])) {
                $reported[$line] = true;
                $this->add('P1-loop-query', $rel, $line, $query);
            }
        }

        if ($checkdelete) {
            foreach ($funcs as $f) {
                $housekeeping = preg_match('/(_delete_instance|purge|prune|cleanup|clean_up)$/i', $f['name']);
                if ($f['deletes'] && !$f['triggers'] && !$housekeeping) {
                    $this->add('S12-delete-no-event', $rel, $f['deletes'][0], $f['name'] . '()');
                }
            }
        }

        // S18: guard or bootstrap required when the file has top-level side effects.
        $guarded = preg_match('/defined\s*\(\s*[\'"]MOODLE_INTERNAL[\'"]\s*\)/', $code)
            || preg_match('/require(_once)?\s*\(?[^;]*config\.php/', $code);
        if ($sideeffect !== null && !$guarded) {
            $this->add('S18-no-guard', $rel, $sideeffect);
        }

        // S19: web service execute() checks.
        if (str_starts_with($rel, 'classes/external/')) {
            foreach ($funcs as $f) {
                if ($f['name'] !== 'execute') {
                    continue;
                }
                $bodyof = function (array $fn) use ($tokens): string {
                    $out = '';
                    for ($k = $fn['start']; $k <= $fn['end']; $k++) {
                        $out .= is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                    }
                    return $out;
                };
                $body = $bodyof($f);
                // Follow one level of helpers defined in the same file (self::x(), static::x(), $this->x()).
                if (preg_match_all('/(?:self|static)::(\w+)\s*\(|\$this->(\w+)\s*\(/', $body, $calls)) {
                    $names = array_filter(array_merge($calls[1], $calls[2]));
                    foreach ($funcs as $helper) {
                        if ($helper['name'] !== 'execute' && in_array($helper['name'], $names, true)) {
                            $body .= $bodyof($helper);
                        }
                    }
                }
                $missing = [];
                if (!preg_match('/validate_parameters\s*\(/', $body)) {
                    $missing[] = 'validate_parameters()';
                } else if (!preg_match('/=\s*(self|static|parent|\\\\?[\w\\\\]+)::validate_parameters\s*\(/', $body)) {
                    $missing[] = 'use of the validate_parameters() result';
                }
                if (!preg_match('/validate_context\s*\(|validate_(?!parameters)\w+\s*\(/', $body)) {
                    $missing[] = 'validate_context()';
                }
                if (!preg_match('/\b(require_capability|has_capability|has_any_capability|has_all_capabilities|require_admin|'
                        . 'is_siteadmin|\w*_can_\w+|can_\w+|require_\w+|check_\w*(capability|permission|access)\w*)\s*\(/', $body)) {
                    $missing[] = 'capability check';
                }
                if ($missing) {
                    $this->add('S19-external', $rel, $f['line'], 'missing ' . implode(', ', $missing));
                }
            }
        }

        // Unused imports.
        foreach ($uses as [$short, $useline]) {
            $used = false;
            foreach ($tokens as $idx => $t) {
                if (isset($usetokens[$idx]) || !is_array($t)) {
                    continue;
                }
                if ($t[0] === T_STRING && strcasecmp($t[1], $short) === 0) {
                    $used = true;
                } else if (in_array($t[0], [T_NAME_QUALIFIED], true)
                        && strcasecmp(explode('\\', $t[1])[0], $short) === 0) {
                    $used = true;
                } else if (in_array($t[0], [T_DOC_COMMENT, T_COMMENT], true)
                        && preg_match('/(?<![\w\\\\])' . preg_quote($short, '/') . '(?!\w)/i', $t[1])) {
                    $used = true;
                }
                if ($used) {
                    break;
                }
            }
            if (!$used) {
                $this->add('Q7-unused-use', $rel, $useline, $short);
            }
        }
    }

    /**
     * Parse a top-level use statement starting at $i; advances $i to its semicolon.
     *
     * @param array $tokens Tokens.
     * @param int $i Index of T_USE (updated).
     * @param int $line Line of the statement.
     * @return array List of [shortname, line].
     */
    private function parse_use(array $tokens, int &$i, int $line): array {
        $out = [];
        $names = [];
        $alias = false;
        $skip = false;
        $count = count($tokens);
        for ($i++; $i < $count && $tokens[$i] !== ';'; $i++) {
            $t = $tokens[$i];
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_FUNCTION, T_CONST], true))) {
                $skip = true;
            }
            if (!is_array($t)) {
                if ($t === ',') {
                    $alias = false;
                }
                continue;
            }
            if ($t[0] === T_AS) {
                $alias = true;
            } else if (in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $parts = explode('\\', $t[1]);
                $short = end($parts);
                if ($alias) {
                    array_pop($names);
                }
                $names[] = $short;
            }
        }
        if ($skip) {
            return [];
        }
        foreach ($names as $n) {
            $out[] = [$n, $line];
        }
        return $out;
    }

    /**
     * Index of the next non-whitespace, non-comment token.
     *
     * @param array $tokens Tokens.
     * @param int $i Start index.
     * @return int|null
     */
    private function next_code(array $tokens, int $i): ?int {
        $count = count($tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $j;
            }
        }
        return null;
    }

    /**
     * Index of the previous non-whitespace, non-comment token.
     *
     * @param array $tokens Tokens.
     * @param int $i Start index.
     * @return int|null
     */
    private function prev_code(array $tokens, int $i): ?int {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $j;
            }
        }
        return null;
    }

    /**
     * Rules for amd/src JavaScript.
     *
     * @param string $rel Relative path.
     */
    private function js_rules(string $rel): void {
        foreach ($this->lines[$rel] as $i => $l) {
            $n = $i + 1;
            if (preg_match('#^\s*(//|\*)#', $l)) {
                continue;
            }
            $sink = preg_match('/\.(innerHTML|outerHTML)\s*\+?=(?!=)/', $l)
                && !preg_match('/\.(innerHTML|outerHTML)\s*=\s*(\'\'|""|``)\s*;/', $l);
            if ($sink || preg_match('/insertAdjacentHTML\s*\(|document\.write(ln)?\s*\(|\.html\(\s*[^)\s]/', $l)) {
                $this->add('S5-js-html', $rel, $n);
            }
            if ((preg_match('/\b(href|action|formaction)=\\\\?["\']?\$\{/', $l)
                    || preg_match('/setAttribute\(\s*[\'"](href|action|formaction)[\'"]\s*,\s*[^\'"]/', $l))
                    && !preg_match('/safe(Url|Href)\s*\(|sanitizeUrl\s*\(/', $l)) {
                $this->add('S5-js-href', $rel, $n);
            }
            if ($this->external_asset($l)) {
                $this->add('S6-external-asset', $rel, $n);
            }
            if (preg_match('/sesskey/i', $l) && preg_match('/[\'"`]https?:\/\//', $l) && !preg_match('/M\.cfg\.wwwroot/', $l)) {
                $this->add('S16-sesskey-leak', $rel, $n);
            }
        }
    }

    /**
     * Rules for Mustache templates.
     *
     * @param string $rel Relative path.
     */
    private function mustache_rules(string $rel): void {
        foreach ($this->lines[$rel] as $i => $l) {
            if (preg_match('/\{\{\{\s*[^}]+\}\}\}/', $l)) {
                $this->add('S5-mustache-raw', $rel, $i + 1);
            }
            if ($this->external_asset($l)) {
                $this->add('S6-external-asset', $rel, $i + 1);
            }
        }
    }

    /**
     * Plugin-level rules.
     *
     * @param string[] $files Scanned files.
     */
    private function plugin_rules(array $files): void {
        $anchor = is_file($this->root . '/version.php') ? 'version.php' : ($files[0] ?? 'version.php');

        // S17: privacy provider and adhoc tasks.
        $providerfile = $this->root . '/classes/privacy/provider.php';
        $provider = is_file($providerfile) ? (string) file_get_contents($providerfile) : null;
        if ($provider === null) {
            $this->add('S17-no-provider', $anchor, 1);
        }
        $nullprovider = $provider !== null && str_contains($provider, 'null_provider');
        foreach ($files as $rel) {
            if (!preg_match('#^classes/task/(\w+)\.php$#', $rel, $m)) {
                continue;
            }
            $code = implode("\n", $this->lines[$rel]);
            if (!preg_match('/class\s+' . $m[1] . '\s+extends\s+\\\\?(core\\\\task\\\\)?adhoc_task/', $code)) {
                continue;
            }
            if ($provider !== null && !$nullprovider && !str_contains($provider, $m[1])) {
                $this->add('S17-task-not-erased', $rel, $this->find_line($rel, '/class\s+' . $m[1] . '\b/'), $m[1]);
            }
        }

        // S12: plugin writes or exports data but has no events at all.
        $hasevents = (bool) glob($this->root . '/classes/event/*.php');
        if (!$hasevents) {
            foreach ($files as $rel) {
                if (!str_ends_with($rel, '.php') || preg_match('#^(db/|backup/|classes/privacy/)#', $rel)) {
                    continue;
                }
                $code = implode("\n", $this->lines[$rel]);
                if (preg_match('/\$DB->(insert_record|update_record|delete_records\w*|set_field\w*)\s*\(/', $code)
                        || preg_match('/new\s+\\\\?(curl|SoapClient)\b/', $code)) {
                    $this->add('S12-no-events', $anchor, 1, 'first write in ' . $rel);
                    break;
                }
            }
        }

        // Q4 and Q6: tests and README present.
        $tests = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(is_dir($this->root . '/tests') ? $this->root . '/tests' : $this->root,
                FilesystemIterator::SKIP_DOTS)
        );
        $hastests = false;
        if (is_dir($this->root . '/tests')) {
            foreach ($tests as $f) {
                if (str_ends_with($f->getFilename(), '_test.php')) {
                    $hastests = true;
                    break;
                }
            }
        }
        if (!$hastests) {
            $this->add('Q4-no-tests', $anchor, 1);
        }
        $readme = glob($this->root . '/{README,readme,Readme}{,.md,.txt,.MD}', GLOB_BRACE) ?: [];
        $readme = array_filter($readme, fn($f) => trim((string) file_get_contents($f)) !== '');
        if (!$readme) {
            $this->add('Q6-no-readme', $anchor, 1);
        }

        $this->thirdparty_rules();
        $this->capability_rules();
        $this->index_rules($files);
    }

    /**
     * S20: undeclared third-party code and declared locations that do not exist.
     */
    private function thirdparty_rules(): void {
        foreach ($this->thirdparty as $loc) {
            if ($loc !== '' && !file_exists($this->root . '/' . $loc)) {
                $this->add('S20-missing-location', 'thirdpartylibs.xml', $this->find_line('thirdpartylibs.xml', '/' . preg_quote($loc, '/') . '/'), $loc);
            }
        }
        $declared = function (string $rel): bool {
            foreach ($this->thirdparty as $loc) {
                if ($loc !== '' && ($rel === $loc || str_starts_with($rel, $loc . '/'))) {
                    return true;
                }
            }
            return false;
        };
        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                function (SplFileInfo $f) {
                    $rel = $this->rel($f->getPathname());
                    return !preg_match('#(^|/)(\.git|node_modules|amd/build|tests)$#', $rel);
                }
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $seen = [];
        foreach ($it as $f) {
            $rel = $this->rel($f->getPathname());
            if ($declared($rel)) {
                continue;
            }
            $dir = dirname($rel);
            $hit = null;
            if ($f->isDir() && $f->getFilename() === 'vendor') {
                $hit = $rel;
            } else if ($f->isFile() && preg_match('/\.min\.(js|css)$/', $rel)) {
                $hit = $rel;
            } else if ($f->isFile() && $dir !== '.' && preg_match('/^(LICEN[CS]E|COPYING)(\.\w+)?$/i', $f->getFilename())) {
                $hit = $dir;
            } else if ($f->isFile() && $dir !== '.' && in_array($f->getFilename(), ['composer.json', 'package.json'], true)) {
                $hit = $dir;
            }
            if ($hit !== null && !isset($seen[$hit])) {
                foreach (array_keys($seen) as $prior) {
                    if (str_starts_with($hit, $prior . '/')) {
                        continue 2;
                    }
                }
                $seen[$hit] = true;
                $this->add('S20-undeclared', $hit, 1);
            }
        }
    }

    /**
     * S14 rules over db/access.php.
     *
     * The file is read with the tokenizer and never executed, so a hostile plugin cannot run code in CI.
     */
    private function capability_rules(): void {
        $file = $this->root . '/db/access.php';
        if (!is_file($file)) {
            return;
        }
        $rel = 'db/access.php';
        try {
            $caps = (new access_parser((string) file_get_contents($file)))->capabilities();
        } catch (UnexpectedValueException $e) {
            $this->add('S14-unreadable', $rel, $e->getCode() ?: 1, $e->getMessage());
            return;
        }
        foreach ($caps as $name => $def) {
            if (!is_array($def)) {
                continue;
            }
            $line = $this->find_line($rel, '/[\'"]' . preg_quote((string) $name, '/') . '[\'"]\s*=>/');
            $risk = (int) ($def['riskbitmask'] ?? 0);
            if (($def['captype'] ?? '') === 'write' && $risk === 0) {
                $this->add('S14-write-no-risk', $rel, $line, (string) $name);
            }
            if (($risk & (ACCESS_CONSTANTS['RISK_CONFIG'] | ACCESS_CONSTANTS['RISK_XSS'] | ACCESS_CONSTANTS['RISK_DATALOSS']))
                    && !empty($def['archetypes'])) {
                $this->add('S14-risky-archetype', $rel, $line, (string) $name);
            }
            $personal = '/:(view(all|any|submissions|reports|grades)|managesubmissions|grade|export|viewreport)/';
            if (preg_match($personal, (string) $name) && !($risk & ACCESS_CONSTANTS['RISK_PERSONAL'])) {
                $this->add('S14-personal', $rel, $line, (string) $name);
            }
        }
    }

    /**
     * P2: plugin-table lookups by a column that is not the leading column of a key or index.
     *
     * @param string[] $files Scanned files.
     */
    private function index_rules(array $files): void {
        $file = $this->root . '/db/install.xml';
        if (!is_file($file)) {
            return;
        }
        $xml = @simplexml_load_file($file);
        if ($xml === false) {
            return;
        }
        $covered = [];
        foreach ($xml->TABLES->TABLE as $table) {
            $tname = (string) $table['NAME'];
            $covered[$tname] = ['id' => true];
            foreach ([$table->KEYS->KEY ?? [], $table->INDEXES->INDEX ?? []] as $set) {
                foreach ($set as $k) {
                    $first = trim(explode(',', (string) $k['FIELDS'])[0]);
                    $covered[$tname][$first] = true;
                }
            }
        }
        $methods = 'get_record|get_records|get_recordset|record_exists|count_records|delete_records|get_records_menu';
        foreach ($files as $rel) {
            if (!str_ends_with($rel, '.php') || str_starts_with($rel, 'db/')) {
                continue;
            }
            $code = implode("\n", $this->lines[$rel]);
            $pattern = '/\$DB->(' . $methods . ')\s*\(\s*\'(\w+)\'\s*,\s*\[([^\]]*)\]/s';
            if (preg_match_all($pattern, $code, $ms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($ms as $m) {
                    $table = $m[2][0];
                    if (!isset($covered[$table])) {
                        continue;
                    }
                    preg_match_all('/\'(\w+)\'\s*=>/', $m[3][0], $keys);
                    if (!$keys[1]) {
                        continue;
                    }
                    $hit = false;
                    foreach ($keys[1] as $col) {
                        if (isset($covered[$table][$col])) {
                            $hit = true;
                        }
                    }
                    if (!$hit) {
                        $line = substr_count(substr($code, 0, $m[0][1]), "\n") + 1;
                        $this->add('P2-no-index', $rel, $line, $table . '.' . implode(',', $keys[1]));
                    }
                }
            }
        }
    }

    /**
     * First line in a file matching a regex.
     *
     * @param string $rel Relative path.
     * @param string $regex Pattern.
     * @return int Line number (1 when not found).
     */
    private function find_line(string $rel, string $regex): int {
        if (!isset($this->lines[$rel])) {
            $this->lines[$rel] = preg_split('/\R/', (string) file_get_contents($this->root . '/' . $rel));
        }
        foreach ($this->lines[$rel] as $i => $l) {
            if (preg_match($regex, $l)) {
                return $i + 1;
            }
        }
        return 1;
    }

    /**
     * Drop findings covered by a "Security-lint: ignore RULE (reason)." comment.
     */
    private function apply_suppressions(): void {
        $kept = [];
        foreach ($this->findings as $f) {
            [$rule, $rel, $line] = $f;
            $lines = $this->lines[$rel] ?? [];
            $suppressed = false;
            for ($n = $line; $n >= max(1, $line - 3); $n--) {
                $text = $lines[$n - 1] ?? '';
                if (preg_match('/Security-lint:\s*ignore\s+([\w ,\-]+?)\s*\(([^)]*\S[^)]*)\)/i', $text, $m)) {
                    foreach (preg_split('/[\s,]+/', trim($m[1])) as $want) {
                        if ($want !== '' && ($want === $rule || str_starts_with($rule, $want . '-'))) {
                            $suppressed = true;
                        }
                    }
                }
            }
            // Task files may opt out of the erasure rule anywhere in the file.
            if (!$suppressed && $rule === 'S17-task-not-erased') {
                $suppressed = (bool) preg_grep('/Security-lint:\s*ignore\s+S17[\w\-]*\s*\([^)]*\S/i', $lines);
            }
            // Capabilities: allow the suppression anywhere inside the capability's block.
            if (!$suppressed && str_starts_with($rule, 'S14-')) {
                $pattern = '/Security-lint:\s*ignore\s+[^(]*\b(S14|' . preg_quote($rule, '/') . ')\b[^(]*\(\s*\S/i';
                for ($n = $line; isset($lines[$n - 1]); $n++) {
                    if (preg_match($pattern, $lines[$n - 1])) {
                        $suppressed = true;
                        break;
                    }
                    // Stop at the closing bracket of this capability's definition.
                    if ($n > $line && preg_match('/^ {4}\],?\s*$/', $lines[$n - 1])) {
                        break;
                    }
                }
            }
            if (!$suppressed) {
                $kept[] = $f;
            }
        }
        $this->findings = $kept;
    }
}

// Command line handling.
$args = array_slice($argv, 1);
$opts = ['report-only' => false, 'annotate-prefix' => '', 'codequality' => null, 'list-rules' => false];
$paths = [];
foreach ($args as $a) {
    if (preg_match('/^--([\w-]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    } else {
        $paths[] = $a;
    }
}
if ($opts['list-rules']) {
    foreach (RULES as $id => [$check, $desc, $fix]) {
        printf("%-22s %-4s %s\n%27s Fix: %s\n", $id, $check, $desc, '', $fix);
    }
    exit(0);
}
$root = realpath($paths[0] ?? '.');
if ($root === false || !is_dir($root)) {
    fwrite(STDERR, "Plugin directory not found.\n");
    exit(2);
}

$findings = (new security_lint($root))->run();
$github = getenv('GITHUB_ACTIONS') === 'true';
$bycount = [];
$cq = [];
foreach ($findings as [$rule, $rel, $line, $detail]) {
    [$check, $desc, $fix] = RULES[$rule];
    $msg = $desc . ($detail !== '' ? " [{$detail}]" : '') . '. Fix: ' . $fix;
    printf("%s:%d  %s  %s\n", $rel, $line, $rule, $msg);
    if ($github) {
        $level = $opts['report-only'] ? 'warning' : 'error';
        printf("::%s file=%s%s,line=%d,title=%s (%s)::%s\n", $level, $opts['annotate-prefix'], $rel, $line, $rule, $check,
            str_replace(["\n", '%'], [' ', '%25'], $msg));
    }
    $bycount[$rule] = ($bycount[$rule] ?? 0) + 1;
    $cq[] = [
        'description' => "{$rule}: {$msg}",
        'check_name' => $rule,
        'fingerprint' => md5($rule . $rel . $line . $detail),
        'severity' => str_starts_with($rule, 'S') ? 'major' : 'minor',
        'location' => ['path' => $opts['annotate-prefix'] . $rel, 'lines' => ['begin' => $line]],
    ];
}
if (is_string($opts['codequality'])) {
    file_put_contents($opts['codequality'], json_encode($cq, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
echo "\n";
if (!$findings) {
    echo "security-lint: no findings.\n";
    exit(0);
}
ksort($bycount);
echo "security-lint: " . count($findings) . " finding(s)\n";
foreach ($bycount as $rule => $c) {
    printf("  %-22s %d\n", $rule, $c);
}
exit($opts['report-only'] ? 0 : 1);
