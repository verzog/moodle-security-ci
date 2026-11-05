<?php
namespace local_clean\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_value;

/** Good web service. */
class get_items extends external_api {
    /**
     * Parameters.
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(['courseid' => new external_value(PARAM_INT, 'Course')]);
    }

    /**
     * Execute.
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        global $DB, $SESSION;
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/clean:view', $context);
        $SESSION->local_clean_last = $params['courseid'];
        // Regression: string contents such as ")" must not be read as code punctuation.
        $note = "Items ({$params['courseid']})";
        $title = get_string('pluginname', 'local_clean') . $note;
        $where = 'courseid = :courseid';
        $sql = "SELECT id, name FROM {local_clean_items} WHERE $where ORDER BY name";
        return array_values($DB->get_records_sql($sql, ['courseid' => $params['courseid']]));
    }

    /**
     * Returns.
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_value(PARAM_RAW, 'x'));
    }
}
