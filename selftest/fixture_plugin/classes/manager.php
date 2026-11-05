<?php
namespace local_fixture;

use core_external\external_api; // EXPECT:Q7-unused-use
use core\context\course;
use core_user\fields;

/** Manager. */
class manager {
    /**
     * Loops.
     * @param array $rows
     * @param fields $f
     */
    public function loops(array $rows, $f): void {
        global $DB;
        $ctx = course::instance(1);
        foreach ($rows as $row) {
            $DB->get_record('user', ['id' => $row->userid]); // EXPECT:P1-loop-query
            $gi = \grade_item::fetch(['id' => $row->gi]); // EXPECT:P1-loop-query
            if (is_enrolled($ctx, $row->userid)) { // EXPECT:P1-loop-query
                $x = 1;
            }
            // Security-lint: ignore P1 (bounded by three template types).
            $DB->record_exists('course', ['id' => 1]);
        }
        $DB->get_record('user', ['id' => 1]);
        $all = array_map(function ($r) {
            return $r;
        }, $rows);
        for ($i = 0; $i < 3; $i++) {
            $DB->count_records('user'); // EXPECT:P1-loop-query
        }
        $DB->get_records('local_fixture_items', ['parentid' => 3]); // EXPECT:P2-no-index
        $DB->get_records('local_fixture_items', ['courseid' => 3, 'parentid' => 2]);
    }

    /**
     * Delete without event.
     * @param int $id
     */
    public function remove(int $id): void {
        global $DB;
        $DB->delete_records('local_fixture_items', ['id' => $id]); // EXPECT:S12-delete-no-event
    }

    /**
     * Delete with event.
     * @param int $id
     */
    public function remove_ok(int $id): void {
        global $DB;
        $DB->delete_records('local_fixture_items', ['id' => $id]);
        \local_fixture\event\item_deleted::create([])->trigger();
    }

    /**
     * Dynamic column.
     * @param string $type
     */
    public function save(string $type): void {
        global $DB;
        $record = new \stdClass();
        $record->$type = 'x'; // EXPECT:S3-dynamic-column
        $DB->update_record('local_fixture_items', $record);
        print_r($record); // EXPECT:Q7-debug
        $s = print_r($record, true);
    }
}
