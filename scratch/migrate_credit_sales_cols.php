<?php
require_once __DIR__ . '/../include/config.php';

$cols = [
    'temp_slip_id'       => "INT(11) DEFAULT NULL AFTER wasoli",
    'temp_slip_no'       => "VARCHAR(64) DEFAULT NULL AFTER temp_slip_id",
    'temp_slip_date'     => "DATE DEFAULT NULL AFTER temp_slip_no",
    'temp_rate'          => "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER temp_slip_date",
    'ref_slip_no'        => "VARCHAR(128) DEFAULT NULL AFTER temp_rate",
    'ref_slip_date'      => "DATE DEFAULT NULL AFTER ref_slip_no",
    'settled_in_slip_id' => "INT(11) DEFAULT NULL AFTER ref_slip_date"
];

foreach ($cols as $col => $def) {
    $chk = mysqli_query($connection, "SHOW COLUMNS FROM tbl_meter_reading_credit_sales LIKE '$col'");
    if ($chk && mysqli_num_rows($chk) == 0) {
        $alter = mysqli_query($connection, "ALTER TABLE tbl_meter_reading_credit_sales ADD COLUMN $col $def");
        if ($alter) {
            echo "[ADDED] $col\n";
        } else {
            echo "[ERROR] $col: " . mysqli_error($connection) . "\n";
        }
    } else {
        echo "[EXISTS] $col\n";
    }
}
