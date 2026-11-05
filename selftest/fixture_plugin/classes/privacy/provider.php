<?php
namespace local_fixture\privacy;
/** Provider. */
class provider implements \core_privacy\local\metadata\provider {
    /** Erase. */
    public static function erase(): void {
        $classes = [\local_fixture\task\push_task::class];
    }
}
