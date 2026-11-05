<?php
define('LOCAL_FIXTURE_MAX', 5); // EXPECT:S18-no-guard

/**
 * Old-style callback.
 */
function local_fixture_before_footer() { // EXPECT:Q8-deprecated
    return '';
}

/**
 * Serve files.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 */
function local_fixture_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    $file = get_file_storage()->get_file($context->id, 'local_fixture', $filearea, 0, '/', end($args));
    send_stored_file($file, 0, 0, true, $options);
}
