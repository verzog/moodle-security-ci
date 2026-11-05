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

use core_privacy\local\request\approved_contextlist;
use local_myplugin\privacy\provider;

/**
 * S17: privacy erasure removes queued adhoc tasks that carry a learner's data.
 *
 * Template — the first test discovers every adhoc task in classes/task and fails when a new one
 * is added without a decision. The second proves erasure clears the queue.
 *
 * @package    local_myplugin
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class privacy_test extends \core_privacy\tests\provider_testcase {
    /** @var string[] Adhoc tasks whose custom data includes a user id and must be erased. */
    private const PERSONAL_TASKS = [
        \local_myplugin\task\push_score_task::class,
        \local_myplugin\task\sync_user_task::class,
    ];

    /** @var string[] Adhoc tasks reviewed and confirmed to carry no personal data. */
    private const NON_PERSONAL_TASKS = [
        \local_myplugin\task\rebuild_cache_task::class,
    ];

    /**
     * Every adhoc task class must be classified as personal or non-personal.
     */
    public function test_every_adhoc_task_is_classified(): void {
        $classes = \core_component::get_component_classes_in_namespace('local_myplugin', 'task');
        foreach (array_keys($classes) as $class) {
            if (!is_subclass_of($class, \core\task\adhoc_task::class)) {
                continue;
            }
            $known = in_array($class, self::PERSONAL_TASKS, true) || in_array($class, self::NON_PERSONAL_TASKS, true);
            $this->assertTrue($known, "{$class} is new: add it to PERSONAL_TASKS or NON_PERSONAL_TASKS.");
        }
    }

    /**
     * Erasing a user deletes their queued tasks and leaves other users' tasks alone.
     */
    public function test_delete_data_for_user_clears_queued_tasks(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');

        foreach (self::PERSONAL_TASKS as $class) {
            foreach ([$target, $other] as $user) {
                $task = new $class();
                $task->set_custom_data(['userid' => (int) $user->id, 'courseid' => (int) $course->id]);
                \core\task\manager::queue_adhoc_task($task);
            }
        }

        $context = \context_course::instance($course->id);
        provider::delete_data_for_user(new approved_contextlist($target, 'local_myplugin', [$context->id]));

        foreach (self::PERSONAL_TASKS as $class) {
            $remaining = $DB->get_records('task_adhoc', ['classname' => '\\' . $class]);
            $userids = array_map(fn($r) => (int) (json_decode($r->customdata)->userid ?? 0), $remaining);
            $this->assertNotContains((int) $target->id, $userids, "{$class} still queued for the erased user.");
            $this->assertContains((int) $other->id, $userids, "{$class} for another user was wrongly removed.");
        }
    }
}
