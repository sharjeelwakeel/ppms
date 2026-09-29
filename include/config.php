<?php
if (!isset($connection) || !($connection instanceof mysqli)) {
    // Enforce hardware license & retrieve decrypted DB credentials for production
    $db_pass = "";
    if (!defined('USE_TEST_DB') || !USE_TEST_DB) {
        require_once __DIR__ . '/license_gate.php';
        $db_pass = enforce_ppms_license_and_get_db_password();
    }

    $db_name = (defined('USE_TEST_DB') && USE_TEST_DB) ? "ppms_test" : "ppms";
    $connection = mysqli_connect("127.0.0.1", "root", $db_pass, $db_name);
    if ($connection == false) {
        die("Could not connect to the database. Access denied or invalid credentials.");
    }
}
?>