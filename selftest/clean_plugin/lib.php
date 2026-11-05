<?php
defined('MOODLE_INTERNAL') || die();

define('LOCAL_CLEAN_MAX', 5);

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
function local_clean_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    require_login($course);
    $file = get_file_storage()->get_file($context->id, 'local_clean', $filearea, 0, '/', end($args));
    send_stored_file($file, 0, 0, true, $options);
}
