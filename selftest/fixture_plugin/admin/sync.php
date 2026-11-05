<?php
require_once(__DIR__ . '/../../../config.php'); // EXPECT:S1-no-login
$action = optional_param('action', '', PARAM_RAW); // EXPECT:S3-param-raw
$x = $_GET['x']; // EXPECT:S3-superglobal
if ($action === 'push' && confirm_sesskey()) { // EXPECT:S8-get-write
    try {
        email_to_user($u, $u, 's', 'b'); // EXPECT:P3-request-io
        \local_fixture\manager::push_all();
    } catch (\Exception $e) {
        $error = get_string('syncfailed', 'local_fixture', $e->getMessage()); // EXPECT:S5-php-exception
        $ok = get_string('syncfailed', 'local_fixture', s($e->getMessage()));
    }
    sleep(2); // EXPECT:P4-sleep
}
