<?php
/**
 * Test Database Configuration
 * STRICT RULE: All automated test suites MUST connect to 'ppms_test'.
 * NEVER run tests against the primary 'ppms' production database.
 */

define('TEST_DB_SERVER', 'localhost');
define('TEST_DB_USERNAME', 'root');
define('TEST_DB_PASSWORD', '');
define('TEST_DB_NAME', 'ppms_test');
if (!defined('USE_TEST_DB')) {
    define('USE_TEST_DB', true);
}

$connection = mysqli_connect(TEST_DB_SERVER, TEST_DB_USERNAME, TEST_DB_PASSWORD, TEST_DB_NAME);

if (!$connection) {
    die("FATAL: Could not connect to TEST database ('" . TEST_DB_NAME . "'). " . mysqli_connect_error() . "\n" .
        "Please ensure 'ppms_test' is created using 'ppms-test.sql'.\n");
}

mysqli_set_charset($connection, "utf8mb4");
date_default_timezone_set('Asia/Karachi');
