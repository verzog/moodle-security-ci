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

namespace local_myplugin;

/**
 * P1/P3: proves the number of database reads does not grow with the size of the input.
 *
 * Template — replace local_myplugin, the covered class and run_import() with the real
 * operation. One test per loop-over-site-data path (imports, syncs, exports, report builders).
 *
 * @package    local_myplugin
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_myplugin\importer::class)]
final class query_scaling_test extends \advanced_testcase {
    /** @var int Extra reads allowed per extra row. Keep at 0 unless a core API forces one; say which in a comment. */
    private const READS_PER_EXTRA_ROW = 0;

    /** @var int Fixed slack for caches warming up. */
    private const SLACK = 5;

    /**
     * Count the database reads a callable performs.
     *
     * @param callable $fn The operation to measure.
     * @return int Number of reads.
     */
    private function count_reads(callable $fn): int {
        global $DB;
        $before = $DB->perf_get_reads();
        $fn();
        return $DB->perf_get_reads() - $before;
    }

    /**
     * Build an import file with $n rows and run it.
     *
     * @param \stdClass $course Course.
     * @param \stdClass $group Group.
     * @param int $n Number of rows.
     */
    private function run_import(\stdClass $course, \stdClass $group, int $n): void {
        $rows = ["courseid,groupid,name,start"];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = "{$course->id},{$group->id},Event {$i},01/03/2027 09:00";
        }
        $file = make_request_directory() . '/import.csv';
        file_put_contents($file, implode("\n", $rows));
        importer::import_file($file, ['skipduplicates' => true]);
    }

    /**
     * Reads must stay flat when the row count grows tenfold.
     */
    public function test_reads_do_not_grow_with_rows(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        // Warm caches so the first run is not penalised.
        $this->run_import($course, $group, 1);

        $small = $this->count_reads(fn() => $this->run_import($course, $group, 5));
        $large = $this->count_reads(fn() => $this->run_import($course, $group, 50));

        $allowed = $small + self::SLACK + (45 * self::READS_PER_EXTRA_ROW);
        $this->assertLessThanOrEqual(
            $allowed,
            $large,
            "Reads grew from {$small} (5 rows) to {$large} (50 rows): a query is running per row."
        );
    }
}
