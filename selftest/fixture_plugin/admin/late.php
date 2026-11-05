<?php
require_once(__DIR__ . '/../../../config.php');
echo 'hello'; // EXPECT:S1-login-late
require_login();
