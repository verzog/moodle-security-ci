<?php
namespace local_fixture\task;
/** Task. */
class sync_task extends \core\task\adhoc_task { // EXPECT:S17-task-not-erased
    /** Run. */
    public function execute() {
    }
}
