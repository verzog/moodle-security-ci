<?php
namespace local_fixture\local;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php'); // EXPECT:Q8-deprecated

/** Legacy patterns. */
class legacy {
    /**
     * Everything wrong in one place.
     * @param int $courseid
     * @param string $token
     */
    public function run(int $courseid, string $token): void {
        global $DB, $CFG, $SESSION, $PAGE;
        add_to_log($courseid, 'x', 'y'); // EXPECT:Q8-deprecated
        $out = shell_exec('ls ' . $courseid); // EXPECT:S9-shell
        $ok = $this->exec('safe method');
        $ctx = \context_system::instance();
        $b = `whoami`; // EXPECT:S9-shell
        mtrace('Using token ' . $token); // EXPECT:S11-secret-log
        mtrace('Token refreshed');
        debugging('Key length ' . strlen($token));
        mtrace('Key ' . sesskey()); // EXPECT:S16-sesskey-leak
        $CFG->debug = 32767; // EXPECT:Q3-core-global
        $SESSION->lastpage = 3; // EXPECT:Q3-core-global
        $SESSION->local_fixture_lastpage = 3;
        $SESSION->fixture_filters = [];
        $rows = $DB->get_records_sql("SELECT * FROM {course} WHERE id = $courseid"); // EXPECT:S7-sql-variable
        $rows = $DB->get_records_select('course', 'category = ' . $courseid); // EXPECT:S7-sql-variable
        $rows = $DB->get_records_sql('SELECT * FROM {course} WHERE id = :id', ['id' => $courseid]);
        [$insql, $params] = $DB->get_in_or_equal([1, 2], SQL_PARAMS_NAMED);
        $rows = $DB->get_records_sql("SELECT * FROM {course} c WHERE c.id $insql", $params);
        $rows = $DB->get_records_sql('SELECT * FROM {course} WHERE id = ' . (int) $courseid);
        $html = "<div class=\"x\">{$courseid}</div>";
        readfile('/tmp/x.pdf'); // EXPECT:S15-file-serve
        $PAGE->requires->js(new \moodle_url('https://cdn.example.com/lib.js')); // EXPECT:S6-external-asset
        $PAGE->requires->js(new \moodle_url('/local/fixture/lib.js'));
    }
}
