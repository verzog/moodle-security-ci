<?php
namespace local_fixture\local;

/** P1 through helpers in the same file. */
class helpers {
    /**
     * Loops that call helpers.
     * @param array $rows
     * @param object $other
     */
    public function run(array $rows, $other): void {
        foreach ($rows as $row) {
            $this->load_user($row->userid); // EXPECT:P1-loop-query
            self::load_twice($row->userid); // EXPECT:P1-loop-query
            $this->format_row($row);
            $other->load_user($row->userid);
            \core_text::strtolower($row->name);
        }
        $this->load_user(1);
    }

    /**
     * Runs a query.
     * @param int $id
     * @return object|false
     */
    private function load_user(int $id) {
        global $DB;
        return $DB->get_record('user', ['id' => $id]);
    }

    /**
     * Runs a query through another helper.
     * @param int $id
     * @return object|false
     */
    private static function load_twice(int $id) {
        return load_fixture_user($id);
    }

    /**
     * No query.
     * @param object $row
     * @return string
     */
    private function format_row($row): string {
        return (string) $row->name;
    }
}

/**
 * Plain function that queries.
 * @param int $id
 * @return object|false
 */
function load_fixture_user(int $id) {
    global $DB;
    return $DB->get_record('user', ['id' => $id]);
}
