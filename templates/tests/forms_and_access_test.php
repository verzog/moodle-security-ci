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
 * S4/S2/S19: every form sets a type for every field, and web services refuse users without the capability.
 *
 * Template — list each moodleform and each external function in the data providers.
 * advanced_testcase fails on any unexpected debugging() call, so a field without setType()
 * ("Did you remember to call setType()...") fails the form test.
 *
 * @package    local_myplugin
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_myplugin\form\edit_override_form::class)]
final class forms_and_access_test extends \advanced_testcase {
    /**
     * Forms with example submitted values (include every field the form defines).
     *
     * @return array Test cases.
     */
    public static function form_provider(): array {
        return [
            'Override form' => [
                \local_myplugin\form\edit_override_form::class,
                ['userid' => 2, 'timeclose' => 0, 'maxattempts' => 1, 'enabletimeclose' => 0],
            ],
        ];
    }

    /**
     * Web service functions and the arguments for one valid call.
     *
     * @return array Test cases.
     */
    public static function external_provider(): array {
        return [
            'Cancel enrolment' => [
                \local_myplugin\external\cancel_enrolment::class,
                ['enrolments' => []],
            ],
        ];
    }

    /**
     * Submitting the form produces no setType() debugging.
     *
     * @param string $class Form class.
     * @param array $values Submitted values.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('form_provider')]
    public function test_form_fields_have_types(string $class, array $values): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $class::mock_submit($values);
        $form = new $class();
        $form->get_data();
        $this->assertDebuggingNotCalled();
    }

    /**
     * A user with no special role is refused before any work is done.
     *
     * @param string $class External function class.
     * @param array $args Arguments.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('external_provider')]
    public function test_external_function_requires_capability(string $class, array $args): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        // Either the capability or the context check must refuse; both throw a moodle_exception subclass.
        $this->expectException(\moodle_exception::class);
        $class::execute(...$args);
    }
}
