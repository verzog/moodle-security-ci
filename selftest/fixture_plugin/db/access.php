<?php
defined('MOODLE_INTERNAL') || die();
$capabilities = [
    'local/fixture:cancel' => [ // EXPECT:S14-write-no-risk
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [],
    ],
    'local/fixture:configure' => [ // EXPECT:S14-risky-archetype
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => ['manager' => CAP_ALLOW],
    ],
    'local/fixture:viewall' => [ // EXPECT:S14-personal
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => ['teacher' => CAP_ALLOW],
    ],
    'local/fixture:deleteall' => [
        // Security-lint: ignore S14 (managers need this by default; reviewed 06/11/2026).
        'riskbitmask' => RISK_DATALOSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => ['manager' => CAP_ALLOW],
    ],
];
