<?php
/**
 * PPMS Cash Sales Automation Helper
 * Provides reactive bidirectional synchronization between Meter Readings, Credit Sales, Card Sales, and Cash Sales.
 * 
 * Formula: Cash Litres = max(0, Net Meter Litres - (Credit Litres + Card Litres))
 *           Cash Amount = Cash Litres * Fuel Rate
 */

if (!function_exists('init_cash_automation_schema')) {
    function init_cash_automation_schema($connection) {
        // Ensure is_manual_override exists in tbl_meter_reading_cash_sales
        $chk = mysqli_query($connection, "SHOW COLUMNS FROM tbl_meter_reading_cash_sales LIKE 'is_manual_override'");
        if ($chk && mysqli_num_rows($chk) == 0) {
            mysqli_query($connection, "ALTER TABLE tbl_meter_reading_cash_sales ADD COLUMN is_manual_override TINYINT(1) NOT NULL DEFAULT 0 AFTER meter_reading_id");
        }
    }
}

if (!function_exists('sync_shift_cash_sales')) {
    /**
     * Recalculates and synchronizes cash sales for all nozzles in a given date and shift
     * if an active meter reading is available.
     *
     * @param mysqli $connection
     * @param string $date (YYYY-MM-DD)
     * @param int $shift_id
     * @return bool True if synchronized, false if no meter reading exists
     */
    function sync_shift_cash_sales($connection, $date, $shift_id) {
        init_cash_automation_schema($connection);

        $date_safe = mysqli_real_escape_string($connection, trim($date));
        $shift_id  = intval($shift_id);

        if (empty($date_safe) || $shift_id <= 0) {
            return false;
        }

        // 1. Check if an active meter reading exists for this date and shift
        $mr_sql = "SELECT id, grand_total FROM tbl_meter_readings 
                   WHERE date = '$date_safe' AND shift_id = '$shift_id' 
                     AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
                   ORDER BY id DESC LIMIT 1";
        $mr_res = mysqli_query($connection, $mr_sql);
        if (!$mr_res || mysqli_num_rows($mr_res) == 0) {
            // No meter reading exists yet for this shift; no action required
            return false;
        }

        $mr_row = mysqli_fetch_assoc($mr_res);
        $mr_id  = intval($mr_row['id']);

        // 2. Fetch all nozzle detail rows for this meter reading
        $det_sql = "SELECT d.id AS detail_id, d.nozzle_id, d.net_sale, d.price, 
                           n.item_id, n.name AS nozzle_name, i.name AS item_name, i.cash_rate
                    FROM tbl_meter_reading_details d
                    LEFT JOIN tbl_nozzles n ON d.nozzle_id = n.id
                    LEFT JOIN tbl_items i ON n.item_id = i.id
                    WHERE d.meter_reading_id = '$mr_id'";
        $det_res = mysqli_query($connection, $det_sql);
        if (!$det_res || mysqli_num_rows($det_res) == 0) {
            return false;
        }

        while ($drow = mysqli_fetch_assoc($det_res)) {
            $noz_id   = intval($drow['nozzle_id']);
            $net_sale = floatval($drow['net_sale']);
            $price    = floatval($drow['price']);
            $item_id  = intval($drow['item_id']);

            if ($price <= 0) {
                $price = floatval($drow['cash_rate'] ?? 0);
            }

            // 3. Sum active credit sales for this nozzle, date, and shift
            $cr_sql = "SELECT SUM(CASE WHEN issue_quantity > 0 THEN issue_quantity ELSE quantity END) AS credit_litres
                       FROM tbl_meter_reading_credit_sales
                       WHERE nozzle_id = '$noz_id'
                         AND (sale_date = '$date_safe' OR (sale_date IS NULL AND slip_date = '$date_safe'))
                         AND shift_id = '$shift_id'
                         AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
            $cr_res = mysqli_query($connection, $cr_sql);
            $credit_litres = 0.00;
            if ($cr_res && $cr_row = mysqli_fetch_assoc($cr_res)) {
                $credit_litres = floatval($cr_row['credit_litres'] ?? 0);
            }

            // 4. Sum active card sales for this nozzle, date, and shift
            $cd_sql = "SELECT SUM(quantity) AS card_litres
                       FROM tbl_meter_reading_card_sales
                       WHERE nozzle_id = '$noz_id'
                         AND sale_date = '$date_safe'
                         AND shift_id = '$shift_id'
                         AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
            $cd_res = mysqli_query($connection, $cd_sql);
            $card_litres = 0.00;
            if ($cd_res && $cd_row = mysqli_fetch_assoc($cd_res)) {
                $card_litres = floatval($cd_row['card_litres'] ?? 0);
            }

            // 5. Calculate remaining cash litres and cash amount
            $cash_litres = round($net_sale - ($credit_litres + $card_litres), 2);
            $warning_note = '';
            if ($cash_litres < 0) {
                $over_amt = abs($cash_litres);
                $warning_note = " (Warning: Credit {$credit_litres}L + Card {$card_litres}L exceeded Meter {$net_sale}L by {$over_amt}L)";
                $cash_litres = 0.00;
            }
            $cash_amount = round($cash_litres * $price, 2);

            // 6. Check if cash sale record already exists for this (date, shift, nozzle)
            $cs_check_sql = "SELECT id, is_manual_override, meter_reading_id 
                             FROM tbl_meter_reading_cash_sales
                             WHERE sale_date = '$date_safe' 
                               AND shift_id = '$shift_id' 
                               AND nozzle_id = '$noz_id'
                               AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
                             LIMIT 1";
            $cs_check_res = mysqli_query($connection, $cs_check_sql);

            if ($cs_check_res && mysqli_num_rows($cs_check_res) > 0) {
                $cs_existing = mysqli_fetch_assoc($cs_check_res);
                $existing_id = intval($cs_existing['id']);
                $is_manual   = intval($cs_existing['is_manual_override'] ?? 0);

                // If not locked by user manual override, update automatically
                if ($is_manual === 0) {
                    $note_text = mysqli_real_escape_string($connection, "Auto-calculated from Meter Reading #{$mr_id}{$warning_note}");
                    $upd_sql = "UPDATE tbl_meter_reading_cash_sales 
                                SET quantity         = '$cash_litres',
                                    amount           = '$cash_amount',
                                    rate             = '$price',
                                    item_id          = '$item_id',
                                    meter_reading_id = '$mr_id',
                                    notes            = '$note_text',
                                    updated_at       = NOW()
                                WHERE id = '$existing_id'";
                    mysqli_query($connection, $upd_sql);
                }
            } else {
                // Insert new auto-calculated cash record
                $note_text = mysqli_real_escape_string($connection, "Auto-calculated from Meter Reading #{$mr_id}{$warning_note}");
                $ins_sql = "INSERT INTO tbl_meter_reading_cash_sales
                            (meter_reading_id, sale_date, shift_id, nozzle_id, item_id, 
                             rate, amount, quantity, notes, is_manual_override, created_at)
                            VALUES
                            ('$mr_id', '$date_safe', '$shift_id', '$noz_id', '$item_id',
                             '$price', '$cash_amount', '$cash_litres', '$note_text', 0, NOW())";
                mysqli_query($connection, $ins_sql);
            }
        }

        return true;
    }
}

if (!function_exists('check_existing_cash_sale_for_shift')) {
    /**
     * Checks if a cash sale record exists for a specific nozzle, date, and shift.
     * Used by AJAX endpoint to detect existing readings and prompt user to edit.
     *
     * @param mysqli $connection
     * @param string $date
     * @param int $shift_id
     * @param int $nozzle_id
     * @return array|null
     */
    function check_existing_cash_sale_for_shift($connection, $date, $shift_id, $nozzle_id) {
        $date_safe = mysqli_real_escape_string($connection, trim($date));
        $shift_id  = intval($shift_id);
        $nozzle_id = intval($nozzle_id);

        if (empty($date_safe) || $shift_id <= 0 || $nozzle_id <= 0) {
            return null;
        }

        $sql = "SELECT cs.id, cs.sale_date, cs.shift_id, cs.nozzle_id, cs.quantity, cs.amount, cs.rate,
                       cs.notes, cs.is_manual_override, cs.meter_reading_id,
                       n.name AS nozzle_name, i.name AS item_name, sh.name AS shift_name
                FROM tbl_meter_reading_cash_sales cs
                LEFT JOIN tbl_nozzles n ON cs.nozzle_id = n.id
                LEFT JOIN tbl_items i ON cs.item_id = i.id
                LEFT JOIN tbl_shifts sh ON cs.shift_id = sh.id
                WHERE cs.sale_date = '$date_safe'
                  AND cs.shift_id = '$shift_id'
                  AND cs.nozzle_id = '$nozzle_id'
                  AND (cs.deleted_at IS NULL OR cs.deleted_at = '0000-00-00 00:00:00')
                LIMIT 1";
        $res = mysqli_query($connection, $sql);
        if ($res && mysqli_num_rows($res) > 0) {
            return mysqli_fetch_assoc($res);
        }
        return null;
    }
}

if (!function_exists('recalculate_meter_reading_from_sales')) {
    /**
     * Recalculates the meter reading's current_reading for a nozzle based on the sum of all sales
     * (Cash + Credit + Card), updates tbl_meter_reading_details, tbl_meter_readings.grand_total,
     * tbl_nozzles.start_reading, and appends a timestamped audit note into tbl_meter_readings.remarks.
     *
     * @param mysqli $connection
     * @param string $date (YYYY-MM-DD)
     * @param int $shift_id
     * @param int $nozzle_id
     * @param string $reason (e.g. "Cash Sale update", "Credit Sale slip edit")
     * @return bool
     */
    function recalculate_meter_reading_from_sales($connection, $date, $shift_id, $nozzle_id, $reason = 'Sales channel update') {
        $date_safe = mysqli_real_escape_string($connection, trim($date));
        $shift_id  = intval($shift_id);
        $nozzle_id = intval($nozzle_id);

        if (empty($date_safe) || $shift_id <= 0 || $nozzle_id <= 0) {
            return false;
        }

        // 1. Locate active meter reading header
        $mr_q = mysqli_query($connection, "
            SELECT id, remarks, grand_total 
            FROM tbl_meter_readings 
            WHERE date = '$date_safe' AND shift_id = '$shift_id' 
              AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
            ORDER BY id DESC LIMIT 1
        ");
        if (!$mr_q || mysqli_num_rows($mr_q) == 0) {
            return false;
        }
        $mr_row = mysqli_fetch_assoc($mr_q);
        $mr_id  = intval($mr_row['id']);

        // 2. Locate detail row for this nozzle in this meter reading
        $det_q = mysqli_query($connection, "
            SELECT mrd.*, n.name AS nozzle_name 
            FROM tbl_meter_reading_details mrd
            LEFT JOIN tbl_nozzles n ON mrd.nozzle_id = n.id
            WHERE mrd.meter_reading_id = '$mr_id' AND mrd.nozzle_id = '$nozzle_id'
            LIMIT 1
        ");
        if (!$det_q || mysqli_num_rows($det_q) == 0) {
            return false;
        }
        $det_row = mysqli_fetch_assoc($det_q);
        $det_id  = intval($det_row['id']);
        $nozzle_name = $det_row['nozzle_name'] ?? ('Nozzle #' . $nozzle_id);

        $last_reading        = floatval($det_row['last_reading']);
        $old_current_reading = floatval($det_row['current_reading']);
        $test_reading        = floatval($det_row['test_reading']);
        $price               = floatval($det_row['price']);

        // 3. Sum active Cash Sales for this nozzle on this date & shift
        $cs_q = mysqli_query($connection, "
            SELECT SUM(quantity) AS total_cash_qty 
            FROM tbl_meter_reading_cash_sales 
            WHERE nozzle_id = '$nozzle_id' AND sale_date = '$date_safe' AND shift_id = '$shift_id' 
              AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
        ");
        $cash_qty = 0.00;
        if ($cs_q && $cs_r = mysqli_fetch_assoc($cs_q)) {
            $cash_qty = floatval($cs_r['total_cash_qty'] ?? 0);
        }

        // 4. Sum active Credit Sales for this nozzle on this date & shift
        $cr_q = mysqli_query($connection, "
            SELECT SUM(CASE WHEN issue_quantity > 0 THEN issue_quantity ELSE quantity END) AS total_credit_qty 
            FROM tbl_meter_reading_credit_sales 
            WHERE nozzle_id = '$nozzle_id' 
              AND (sale_date = '$date_safe' OR (sale_date IS NULL AND slip_date = '$date_safe'))
              AND shift_id = '$shift_id' 
              AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
        ");
        $credit_qty = 0.00;
        if ($cr_q && $cr_r = mysqli_fetch_assoc($cr_q)) {
            $credit_qty = floatval($cr_r['total_credit_qty'] ?? 0);
        }

        // 5. Sum active Card Sales for this nozzle on this date & shift
        $cd_q = mysqli_query($connection, "
            SELECT SUM(quantity) AS total_card_qty 
            FROM tbl_meter_reading_card_sales 
            WHERE nozzle_id = '$nozzle_id' AND sale_date = '$date_safe' AND shift_id = '$shift_id' 
              AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
        ");
        $card_qty = 0.00;
        if ($cd_q && $cd_r = mysqli_fetch_assoc($cd_q)) {
            $card_qty = floatval($cd_r['total_card_qty'] ?? 0);
        }

        // 6. Recalculate fuel quantities and new current reading
        $total_sales_litres  = round($cash_qty + $credit_qty + $card_qty, 2);
        $new_current_reading = round($last_reading + $test_reading + $total_sales_litres, 2);
        $new_sale_reading    = round($new_current_reading - $last_reading, 2);
        $new_net_sale        = round($new_sale_reading - $test_reading, 2);
        $new_amount          = round($new_net_sale * $price, 2);

        // Check if there is an actual difference
        $diff = round($new_current_reading - $old_current_reading, 2);
        if (abs($diff) >= 0.01) {
            // Update tbl_meter_reading_details
            $upd_det = "UPDATE tbl_meter_reading_details 
                        SET current_reading = '$new_current_reading',
                            sale_reading    = '$new_sale_reading',
                            net_sale        = '$new_net_sale',
                            amount          = '$new_amount',
                            updated_at      = NOW()
                        WHERE id = '$det_id'";
            mysqli_query($connection, $upd_det);

            // Recalculate grand_total for tbl_meter_readings
            $sum_q = mysqli_query($connection, "SELECT SUM(amount) AS total_amt FROM tbl_meter_reading_details WHERE meter_reading_id = '$mr_id'");
            $grand_total = 0.00;
            if ($sum_q && $sum_r = mysqli_fetch_assoc($sum_q)) {
                $grand_total = floatval($sum_r['total_amt'] ?? 0);
            }

            // Prepare audit remark
            $now_str = date('d-m-Y H:i');
            $diff_str = ($diff >= 0 ? '+' : '') . number_format($diff, 2) . ' Ltr';
            $old_fmt = number_format($old_current_reading, 2);
            $new_fmt = number_format($new_current_reading, 2);
            $audit_entry = "[{$now_str}] {$nozzle_name}: Current reading recalculated from {$old_fmt} to {$new_fmt} ({$diff_str}) due to {$reason}.";

            $existing_remarks = trim($mr_row['remarks'] ?? '');
            $new_remarks = !empty($existing_remarks) ? ($existing_remarks . "\n" . $audit_entry) : $audit_entry;
            $new_remarks_safe = mysqli_real_escape_string($connection, $new_remarks);

            mysqli_query($connection, "
                UPDATE tbl_meter_readings 
                SET grand_total = '$grand_total',
                    remarks     = '$new_remarks_safe',
                    updated_at  = NOW()
                WHERE id = '$mr_id'
            ");

            // Synchronize tbl_nozzles.start_reading if this is the latest meter reading
            $latest_check = mysqli_query($connection, "
                SELECT mr.id 
                FROM tbl_meter_readings mr 
                JOIN tbl_meter_reading_details mrd ON mr.id = mrd.meter_reading_id 
                WHERE mrd.nozzle_id = '$nozzle_id' 
                  AND (mr.deleted_at IS NULL OR mr.deleted_at = '0000-00-00 00:00:00')
                ORDER BY mr.date DESC, mr.shift_id DESC, mr.id DESC 
                LIMIT 1
            ");
            if ($latest_check && $latest_row = mysqli_fetch_assoc($latest_check)) {
                if (intval($latest_row['id']) === $mr_id) {
                    mysqli_query($connection, "UPDATE tbl_nozzles SET start_reading = '$new_current_reading' WHERE id = '$nozzle_id'");
                }
            }
        }

        return true;
    }
}

