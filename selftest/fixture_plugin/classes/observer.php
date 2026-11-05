<?php
namespace local_fixture;
/** Observer. */
class observer {
    /** Graded. */
    public static function graded(): void {
        $lock = \core\lock\lock_config::get_lock_factory('x')->get_lock('k', 10); // EXPECT:P4-lock-wait
        $lock2 = \core\lock\lock_config::get_lock_factory('x')->get_lock('k', 1);
    }
}
