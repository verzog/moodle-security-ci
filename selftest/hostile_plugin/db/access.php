<?php
// Self-test fixture: security-lint must read this file without executing it.
// If it were included, the next line would write the canary file named by run.sh.
file_put_contents((string) getenv('SECURITY_LINT_CANARY'), 'executed');
$capabilities = [
    'local/hostile:manage' => [
        'riskbitmask' => hostile_risk(),
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
    ],
];
