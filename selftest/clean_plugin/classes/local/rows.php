<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_clean\local;

/**
 * Safe version of P1 through helpers: preload once, then loop over plain helpers.
 *
 * @package    local_clean
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rows {
    /**
     * Names for the given rows, with one query.
     *
     * @param array $rows Rows with a userid.
     * @return string[]
     */
    public function names(array $rows): array {
        $users = $this->load_users(array_column($rows, 'userid'));
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->label($users[$row->userid] ?? null);
        }
        return $out;
    }

    /**
     * Preload users.
     *
     * @param int[] $ids User ids.
     * @return array
     */
    private function load_users(array $ids): array {
        global $DB;
        return $ids ? $DB->get_records_list('user', 'id', $ids, '', 'id, username') : [];
    }

    /**
     * Label for a user record.
     *
     * @param object|null $user User.
     * @return string
     */
    private function label(?object $user): string {
        return $user === null ? '' : (string) $user->username;
    }
}
