<?php
namespace local_clean\privacy;
/** Provider. */
class provider implements \core_privacy\local\metadata\null_provider {
    /**
     * Reason.
     * @return string
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
