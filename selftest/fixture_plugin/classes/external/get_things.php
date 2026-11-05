<?php
namespace local_fixture\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;

/** Web service with gaps. */
class get_things extends external_api {
    /**
     * Parameters.
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(['id' => new external_value(PARAM_INT, 'Id')]);
    }

    /**
     * Execute.
     * @param int $id
     * @return array
     */
    public static function execute(int $id): array { // EXPECT:S19-external
        self::validate_parameters(self::execute_parameters(), ['id' => $id]);
        return [];
    }
}
