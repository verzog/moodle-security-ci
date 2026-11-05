<?php
namespace local_fixture\local;

/** Text inside plain strings is not code. */
class strings {
    /** @var string[] Names a sniff searches for. */
    const NAMES = ['$_GET', '$_POST', 'PARAM_RAW', 'sleep(', "var_dump("];

    /**
     * Interpolated strings are code.
     * @return string
     */
    public function greet(): string {
        return "Hello {$_GET['name']}"; // EXPECT:S3-superglobal
    }
}
