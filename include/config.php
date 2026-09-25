<?php
if (!isset($connection) || !($connection instanceof mysqli)) {
    $db_name = (defined('USE_TEST_DB') && USE_TEST_DB) ? "ppms_test" : "ppms";
    $connection = mysqli_connect("127.0.0.1", "root", "", $db_name);
    if ($connection == false) {
        die("Could not connect to the database.");
    }
}
?>