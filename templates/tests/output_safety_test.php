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
 * S5/S10: hostile values stay inert through sanitisers, templates and external-data cleaning.
 *
 * Template — point each test at the plugin's real sanitiser, template and import path.
 * Keep the payload list shared; add to it whenever an audit or bug report finds a new bypass.
 *
 * @package    local_myplugin
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_myplugin\local\styles::class)]
final class output_safety_test extends \advanced_testcase {
    /**
     * Payloads known to defeat naive filters.
     *
     * @return array Test cases.
     */
    public static function payload_provider(): array {
        return [
            'Nested style close (single-pass strip bypass)' => ['<</style/style><script>alert(1)</script>'],
            'Upper-case close tag' => ['</STYLE><script>alert(1)</script>'],
            'Script close' => ['</script><script>alert(1)</script>'],
            'Image onerror' => ['<img src=x onerror=alert(1)>'],
            'Attribute breakout' => ['"><svg onload=alert(1)>'],
            'Entity-encoded markup' => ['&lt;img src=x onerror=alert(1)&gt;'],
        ];
    }

    /**
     * URL values that must never become a clickable href.
     *
     * @return array Test cases.
     */
    public static function bad_url_provider(): array {
        return [
            'javascript' => ['javascript:alert(1)'],
            'Mixed case' => ['JaVaScRiPt:alert(1)'],
            'Leading space' => [' javascript:alert(1)'],
            'Tab inside scheme' => ["java\tscript:alert(1)"],
            'data URL' => ['data:text/html,<script>alert(1)</script>'],
            'vbscript' => ['vbscript:msgbox(1)'],
        ];
    }

    /**
     * Admin-supplied CSS can never close the style element.
     *
     * @param string $payload Hostile value.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('payload_provider')]
    public function test_custom_css_cannot_break_out(string $payload): void {
        $this->resetAfterTest();
        set_config('customcss', 'body{color:red}' . $payload, 'local_myplugin');
        $css = local\styles::custom_css();
        $this->assertStringNotContainsString('<', $css);
    }

    /**
     * Templates escape every variable they receive.
     *
     * @param string $payload Hostile value.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('payload_provider')]
    public function test_template_escapes_payload(string $payload): void {
        global $OUTPUT;
        $html = $OUTPUT->render_from_template('local_myplugin/item', ['title' => $payload, 'url' => '#']);
        $this->assertDoesNotMatchRegularExpression('/<(script|img|svg)\b/i', $html);
    }

    /**
     * The URL helper only lets http, https, mailto and tel through.
     *
     * @param string $url Hostile URL.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bad_url_provider')]
    public function test_safe_url_rejects_bad_schemes(string $url): void {
        $this->assertSame('#', local\url_helper::safe_url($url));
    }

    /**
     * Profile fields from an external system are cleaned before they reach the user table.
     *
     * @param string $payload Hostile value.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('payload_provider')]
    public function test_external_names_are_cleaned(string $payload): void {
        global $DB;
        $this->resetAfterTest();
        $row = (object) ['memberid' => 'M100', 'firstname' => 'Ann' . $payload, 'lastname' => 'Lee', 'email' => 'a@example.com'];
        (new local\registration_sync())->create_account($row);
        $user = $DB->get_record('user', ['idnumber' => 'M100'], '*', MUST_EXIST);
        $this->assertSame(clean_param($user->firstname, PARAM_NOTAGS), $user->firstname);
    }
}
