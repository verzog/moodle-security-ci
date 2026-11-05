<?php
require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/clean:view', $context);
$PAGE->set_url(new moodle_url('/local/clean/index.php', ['id' => $id]));
$PAGE->requires->js_call_amd('local_clean/thing', 'init');

if (data_submitted() && confirm_sesskey()) {
    $DB->set_field('local_clean_items', 'name', 'x', ['courseid' => $course->id]);
    \local_clean\event\item_viewed::create(['context' => $context])->trigger();
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_clean/item', ['title' => format_string($course->fullname)]);
echo $OUTPUT->footer();
