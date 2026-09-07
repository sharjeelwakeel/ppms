<?php
require_once __DIR__ . '/../include/config.php';

// Fix record 80 (wasoli should be 0.00 for balanced slip)
mysqli_query($connection, "UPDATE tbl_meter_reading_credit_sales SET wasoli = 0.00 WHERE id = 80");

// Fix record 82 (wasoli should be 10.00, charge_amount = 4000.00, temp_slip_id = 81)
mysqli_query($connection, "UPDATE tbl_meter_reading_credit_sales SET wasoli = 10.00, charge_amount = 4000.00, temp_slip_id = 81 WHERE id = 82");

// Fix record 81 (temporary slip should be settled in 82)
mysqli_query($connection, "UPDATE tbl_meter_reading_credit_sales SET is_returned = 1, returned_at = NOW(), settled_in_slip_id = 82 WHERE id = 81");

echo "DB corrected successfully\n";
