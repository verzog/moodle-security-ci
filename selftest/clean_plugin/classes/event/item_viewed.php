<?php
namespace local_clean\event;
/** Event. */
class item_viewed extends \core\event\base {
    /** Init. */
    protected function init() {
        $this->data['crud'] = 'r';
    }
}
