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
 * S12: every create/update/delete/export action fires an event with the right context and user.
 *
 * Template — add one data-provider row per auditable action in the plugin. The audit reports
 * flagged deletes, bulk exports and pushes of learner data to external systems most often.
 *
 * @package    local_myplugin
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_myplugin\event\submission_deleted::class)]
final class events_test extends \advanced_testcase {
    /**
     * Each auditable action and the event it must fire.
     *
     * @return array Test cases.
     */
    public static function action_provider(): array {
        return [
            'Delete a submission' => [
                'delete_submission',
                \local_myplugin\event\submission_deleted::class,
            ],
            'Export submissions to PDF' => [
                'export_pdf',
                \local_myplugin\event\submissions_exported::class,
            ],
        ];
    }

    /**
     * Run the named action against fresh test data.
     *
     * @param string $action Action name from the provider.
     * @param \stdClass $course Course.
     * @param \stdClass $user Affected user.
     */
    private function perform(string $action, \stdClass $course, \stdClass $user): void {
        $manager = new local\submission_manager($course->id);
        $submissionid = $manager->create_submission($user->id);
        match ($action) {
            'delete_submission' => $manager->delete_submission($submissionid),
            'export_pdf' => (new local\submission_pdf($course->id))->export_all(),
        };
    }

    /**
     * The action fires exactly one event of the expected class, with sane data.
     *
     * @param string $action Action name.
     * @param string $eventclass Expected event class.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('action_provider')]
    public function test_action_fires_event(string $action, string $eventclass): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $sink = $this->redirectEvents();
        $this->perform($action, $course, $user);
        $events = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof $eventclass));
        $sink->close();

        $this->assertCount(1, $events, "{$action} must fire exactly one {$eventclass}.");
        $event = $events[0];
        $this->assertNotEmpty($event->get_context());
        $this->assertNotEmpty($event->get_description());
        // No credentials, tokens or full payloads in the log.
        $other = json_encode($event->other ?? []);
        $this->assertDoesNotMatchRegularExpression('/(password|token|secret|apikey|sesskey)/i', $other);
    }
}
