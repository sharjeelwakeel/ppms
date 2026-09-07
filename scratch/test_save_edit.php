<?php
require 'include/config.php';
require_once 'include/nozzle_daily_sync.php';

echo "--- STARTING SIMULATION OF EDIT SAVE ---\n";

// Current active records before edit
$date_safe = '2026-09-07';
$current_shift_id = 1;
$del_shift_clause = " AND shift_id = '$current_shift_id'";

// Mock POST data that the browser will send now that inputs are readonly (not disabled)
$nozzles_arr     = [1, 1, 1, 1];
$slip_dates_arr  = ['2026-09-07', '2026-09-07', '2026-09-07', '2026-09-07'];
$slip_nos        = ['80', '90', '100', '101'];
$slip_types      = ['Permanent Slip', 'Balanced Slip', 'Temporary Slip', 'Permanent Slip'];
$vehicles_arr    = ['900', '900', '900', '900'];
$accounts_arr    = ['7', '7', '7', '7'];
$qtys_arr        = ['30.00', '26.00', '10.00', '0.00'];
$rates_arr       = ['400.00', '400.00', '400.00', '400.00'];
$amounts_arr     = ['12000.00', '10400.00', '4000.00', '0.00'];
$cash_rates      = ['400.00', '400.00', '400.00', '400.00'];
$issue_qtys      = ['50.00', '0.00', '10.00', '0.00'];
$bal1_arr        = ['20.00', '0.00', '0.00', '0.00'];
$bal2_arr        = ['0.00', '0.00', '0.00', '0.00'];
// The crucial array that previously desynchronized when disabled:
$wasoli_arr      = ['0', '0', '0', '10'];
$temp_slip_ids   = ['', '', '', '81'];
$temp_slip_nos   = ['', '', '', '100'];
$temp_slip_dates = ['', '', '', '2026-09-07'];
$temp_rates      = ['0', '0', '0', '400.00'];
$ref_slip_nos    = ['', '80', '', ''];
$ref_slip_dates  = ['', '2026-09-07', '', ''];

mysqli_begin_transaction($connection);
try {
    // 1. Revert nozzle meter readings
    $prev_q = mysqli_query($connection, "SELECT nozzle_id, SUM(quantity) AS prev_qty 
                                          FROM tbl_meter_reading_credit_sales 
                                          WHERE slip_date = '$date_safe' $del_shift_clause AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') 
                                          GROUP BY nozzle_id");
    if ($prev_q) {
        while ($prow = mysqli_fetch_assoc($prev_q)) {
            $p_noz = intval($prow['nozzle_id']);
            $p_qty = floatval($prow['prev_qty']);
            if ($p_noz > 0 && $p_qty > 0) {
                mysqli_query($connection, "UPDATE tbl_nozzles SET start_reading = GREATEST(start_reading - $p_qty, 0.00) WHERE id = '$p_noz'");
                sync_nozzle_daily_card_sale_delta($connection, $date_safe, $current_shift_id, $p_noz, -$p_qty);
            }
        }
    }

    // 2. Reset previously settled temporary slips
    $prev_ts_q = mysqli_query($connection, "SELECT temp_slip_id FROM tbl_meter_reading_credit_sales 
                                            WHERE slip_date = '$date_safe' $del_shift_clause AND temp_slip_id > 0 AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
    if ($prev_ts_q) {
        while ($ts_row = mysqli_fetch_assoc($prev_ts_q)) {
            $old_ts_id = intval($ts_row['temp_slip_id']);
            if ($old_ts_id > 0) {
                mysqli_query($connection, "UPDATE tbl_meter_reading_credit_sales SET is_returned = 0, returned_at = NULL, settled_in_slip_id = NULL WHERE id = '$old_ts_id'");
            }
        }
    }

    // 3. Soft-delete previous rows
    $del_sql = "UPDATE tbl_meter_reading_credit_sales SET deleted_at = NOW() 
                WHERE slip_date = '$date_safe' $del_shift_clause AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
    mysqli_query($connection, $del_sql);

    // 4. Re-insert updated rows (exact code from edit-credit-sale.php)
    $inserted_ids = [];
    for ($i = 0; $i < count($nozzles_arr); $i++) {
        $noz_id        = intval($nozzles_arr[$i]);
        $row_slip_date = !empty(trim($slip_dates_arr[$i] ?? '')) ? mysqli_real_escape_string($connection, trim($slip_dates_arr[$i])) : $date_safe;
        $slip_no       = mysqli_real_escape_string($connection, trim($slip_nos[$i]));
        $slip_type     = mysqli_real_escape_string($connection, trim($slip_types[$i] ?? 'Permanent Slip'));
        $veh_num       = mysqli_real_escape_string($connection, trim($vehicles_arr[$i] ?? ''));
        $acc_num       = mysqli_real_escape_string($connection, trim($accounts_arr[$i] ?? ''));
        $qty           = floatval($qtys_arr[$i] ?? 0);
        $rate          = floatval($rates_arr[$i] ?? 0);
        $cash_rate     = floatval($cash_rates[$i] ?? 0);
        $issue_qty     = floatval($issue_qtys[$i] ?? 0);
        $wasoli        = floatval($wasoli_arr[$i] ?? 0);
        $temp_id       = intval($temp_slip_ids[$i] ?? 0);
        $temp_no       = mysqli_real_escape_string($connection, trim($temp_slip_nos[$i] ?? ''));
        $temp_date_str = trim($temp_slip_dates[$i] ?? '');
        $temp_date_sql = !empty($temp_date_str) ? "'" . mysqli_real_escape_string($connection, $temp_date_str) . "'" : "NULL";
        $temp_rate     = floatval($temp_rates[$i] ?? 0);
        $ref_no        = mysqli_real_escape_string($connection, trim($ref_slip_nos[$i] ?? ''));
        $ref_date_str  = trim($ref_slip_dates[$i] ?? '');
        $ref_date_sql  = !empty($ref_date_str) ? "'" . mysqli_real_escape_string($connection, $ref_date_str) . "'" : "NULL";

        $amount        = round($qty * $rate, 2);
        $charge_amt    = 0.00;
        $bal1          = 0.00;
        $bal2          = 0.00;
        $is_ret        = 0;
        $ret_at        = "NULL";

        if ($slip_type === 'Permanent Slip') {
            if ((!empty($temp_id) || !empty($temp_no)) && $wasoli <= 0) {
                $ts_chk = null;
                if ($temp_id > 0) {
                    $ts_chk = mysqli_query($connection, "SELECT quantity, rate FROM tbl_meter_reading_credit_sales WHERE id = '$temp_id'");
                }
                if ((!$ts_chk || mysqli_num_rows($ts_chk) == 0) && !empty($temp_no)) {
                    $ts_no_safe = mysqli_real_escape_string($connection, $temp_no);
                    $ts_acc_safe = mysqli_real_escape_string($connection, $acc_num);
                    $ts_chk = mysqli_query($connection, "SELECT quantity, rate FROM tbl_meter_reading_credit_sales WHERE slip_no = '$ts_no_safe' AND slip_type = 'Temporary Slip' AND account_number = '$ts_acc_safe' ORDER BY id DESC LIMIT 1");
                }
                if ($ts_chk && $ts_row = mysqli_fetch_assoc($ts_chk)) {
                    $wasoli = floatval($ts_row['quantity']);
                    if ($temp_rate <= 0) {
                        $temp_rate = floatval($ts_row['rate']);
                    }
                }
            }

            $eff_issue = ($issue_qty > 0) ? $issue_qty : $qty;
            $bal1      = max(0.00, round($eff_issue - $qty, 2));
            $t_rate    = ($temp_rate > 0) ? $temp_rate : $rate;
            $charge_amt = round(($eff_issue * $rate) + ($wasoli * $t_rate), 2);
        } elseif ($slip_type === 'Balanced Slip') {
            $charge_amt = 0.00;
            $bal1       = floatval($bal1_arr[$i] ?? 0);
            $bal2       = floatval($bal2_arr[$i] ?? 0);
        } elseif ($slip_type === 'Temporary Slip') {
            $charge_amt = 0.00;
            $issue_qty  = $qty;
            $wasoli     = 0.00;
            $is_ret     = 0;
        }

        $ins_sql = "INSERT INTO tbl_meter_reading_credit_sales 
                    (meter_reading_id, nozzle_id, slip_date, shift_id, slip_no, slip_type, account_number, vehicle_number,
                     quantity, rate, amount, charge_amount, cash_rate, issue_quantity, balance_1, balance_2, wasoli,
                     temp_slip_id, temp_slip_no, temp_slip_date, temp_rate, ref_slip_no, ref_slip_date, is_returned, returned_at)
                    VALUES 
                    (0, '$noz_id', '$row_slip_date', '$current_shift_id', '$slip_no', '$slip_type', '$acc_num', '$veh_num',
                     '$qty', '$rate', '$amount', '$charge_amt', '$cash_rate', '$issue_qty', '$bal1', '$bal2', '$wasoli',
                     " . ($temp_id > 0 ? "'$temp_id'" : "NULL") . ", '$temp_no', $temp_date_sql, '$temp_rate', '$ref_no', $ref_date_sql, '$is_ret', $ret_at)";
        mysqli_query($connection, $ins_sql);
        $new_slip_id = mysqli_insert_id($connection);
        $inserted_ids[] = $new_slip_id;

        if ($slip_type === 'Permanent Slip' && (!empty($temp_id) || !empty($temp_no))) {
            $target_temp_id = $temp_id;
            $chk_act = mysqli_query($connection, "SELECT id FROM tbl_meter_reading_credit_sales WHERE id = '$temp_id' AND deleted_at IS NULL");
            if (!$chk_act || mysqli_num_rows($chk_act) == 0) {
                $ts_no_safe = mysqli_real_escape_string($connection, $temp_no);
                $ts_acc_safe = mysqli_real_escape_string($connection, $acc_num);
                $chk_by_no = mysqli_query($connection, "SELECT id FROM tbl_meter_reading_credit_sales WHERE slip_no = '$ts_no_safe' AND slip_type = 'Temporary Slip' AND account_number = '$ts_acc_safe' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
                if ($chk_by_no && $t_act = mysqli_fetch_assoc($chk_by_no)) {
                    $target_temp_id = intval($t_act['id']);
                    mysqli_query($connection, "UPDATE tbl_meter_reading_credit_sales SET temp_slip_id = '$target_temp_id' WHERE id = '$new_slip_id'");
                }
            }
            if ($target_temp_id > 0) {
                mysqli_query($connection, "UPDATE tbl_meter_reading_credit_sales 
                                           SET is_returned = 1, returned_at = NOW(), settled_in_slip_id = '$new_slip_id' 
                                           WHERE id = '$target_temp_id'");
            }
        }

        if ($noz_id > 0 && $qty > 0) {
            mysqli_query($connection, "UPDATE tbl_nozzles SET start_reading = start_reading + $qty WHERE id = '$noz_id'");
            sync_nozzle_daily_card_sale_delta($connection, $row_slip_date, $current_shift_id, $noz_id, $qty);
        }
    }

    mysqli_commit($connection);
    echo "SUCCESS: Saved " . count($inserted_ids) . " rows with IDs: " . implode(', ', $inserted_ids) . "\n";
} catch (Exception $e) {
    mysqli_rollback($connection);
    echo "ERROR: " . $e->getMessage() . "\n";
}

// Inspect the newly saved records
echo "\n--- INSPECTING SAVED ROWS ---\n";
$res_new = mysqli_query($connection, "SELECT id, slip_no, slip_type, quantity, wasoli, charge_amount, temp_slip_id, temp_slip_no, is_returned, settled_in_slip_id 
                                      FROM tbl_meter_reading_credit_sales 
                                      WHERE slip_date = '$date_safe' AND shift_id = '$current_shift_id' AND deleted_at IS NULL 
                                      ORDER BY id ASC");
while ($row = mysqli_fetch_assoc($res_new)) {
    echo "ID: {$row['id']} | Slip: {$row['slip_no']} | Type: {$row['slip_type']} | Qty: {$row['quantity']} | Wasoli: {$row['wasoli']} | ChargeAmt: {$row['charge_amount']} | TempID: {$row['temp_slip_id']} | TempNo: {$row['temp_slip_no']} | IsRet: {$row['is_returned']} | SettledIn: {$row['settled_in_slip_id']}\n";
}
