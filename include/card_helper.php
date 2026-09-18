<?php
/**
 * PPMS Card Sales Helper Functions
 * Provides machine + date + shift dependent sequential POS batch allocation.
 */

if (!function_exists('get_shift_machine_batches')) {
    /**
     * Resolves the sequential batch numbers for all active card machines for a given Date and Shift.
     *
     * Rules:
     * 1. Machine + Date + Shift binding: Within the same (sale_date, shift_id), all entries for a specific machine
     *    share the exact same batch number.
     * 2. If a machine already has records for this (sale_date, shift_id), its existing batch_no is preserved.
     * 3. If a machine is new in this shift, it gets the next sequential batch continuing from the maximum previous batch.
     * 4. Next shift/day increments from the highest batch number recorded across previous shifts.
     *
     * @param mysqli $connection
     * @param string $sale_date (YYYY-MM-DD)
     * @param int $shift_id
     * @return array [machine_id => ['machine_id' => int, 'machine_name' => string, 'batch_no' => string, 'is_existing' => bool]]
     */
    function get_shift_machine_batches($connection, $sale_date, $shift_id) {
        $shiftId  = intval($shift_id);
        $dateSafe = mysqli_real_escape_string($connection, trim($sale_date));

        // Fetch all active card machines ordered by id ASC
        $q_machs = mysqli_query($connection, "SELECT id, name FROM tbl_card_machines WHERE deleted_at IS NULL ORDER BY id ASC");
        $machines = [];
        if ($q_machs) {
            while ($m = mysqli_fetch_assoc($q_machs)) {
                $machines[] = $m;
            }
        }

        $batches = [];
        $existing_batches = [];
        $max_assigned_in_current_shift = 0;

        // Step 1: Check existing saved records for THIS (sale_date, shift_id)
        $q_curr = mysqli_query($connection, "SELECT card_machine_id, batch_no 
                                             FROM tbl_meter_reading_card_sales 
                                             WHERE sale_date = '$dateSafe' 
                                               AND shift_id = '$shiftId' 
                                               AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
                                               AND batch_no IS NOT NULL 
                                               AND TRIM(batch_no) != ''
                                             ORDER BY id ASC");

        if ($q_curr) {
            while ($r = mysqli_fetch_assoc($q_curr)) {
                $mId = intval($r['card_machine_id']);
                $bNo = trim($r['batch_no']);
                if ($bNo !== '' && !isset($existing_batches[$mId])) {
                    $existing_batches[$mId] = $bNo;
                    if (is_numeric($bNo) && intval($bNo) > $max_assigned_in_current_shift) {
                        $max_assigned_in_current_shift = intval($bNo);
                    }
                }
            }
        }

        // Step 2: Find maximum batch recorded across ALL machines prior to this (sale_date, shift_id)
        $q_prev = mysqli_query($connection, "SELECT MAX(CAST(batch_no AS UNSIGNED)) AS max_prev_batch
                                             FROM tbl_meter_reading_card_sales 
                                             WHERE (
                                                 sale_date < '$dateSafe' 
                                                 OR (sale_date = '$dateSafe' AND shift_id < '$shiftId')
                                             )
                                             AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
                                             AND batch_no IS NOT NULL 
                                             AND TRIM(batch_no) != ''
                                             AND batch_no REGEXP '^[0-9]+$'");

        $max_prev = 0;
        if ($q_prev && ($r_prev = mysqli_fetch_assoc($q_prev)) && !empty($r_prev['max_prev_batch'])) {
            $max_prev = intval($r_prev['max_prev_batch']);
        } else {
            // Fallback: Check overall latest numeric batch across all records in table
            $q_all = mysqli_query($connection, "SELECT MAX(CAST(batch_no AS UNSIGNED)) AS max_any_batch
                                                FROM tbl_meter_reading_card_sales 
                                                WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
                                                  AND batch_no IS NOT NULL 
                                                  AND TRIM(batch_no) != ''
                                                  AND batch_no REGEXP '^[0-9]+$'");
            if ($q_all && ($r_all = mysqli_fetch_assoc($q_all)) && !empty($r_all['max_any_batch'])) {
                $max_prev = intval($r_all['max_any_batch']);
            }
        }

        $current_counter = max($max_prev, $max_assigned_in_current_shift);

        // Step 3: Assign batch numbers to each machine
        foreach ($machines as $m) {
            $mId = intval($m['id']);
            if (isset($existing_batches[$mId])) {
                $batches[$mId] = [
                    'machine_id'   => $mId,
                    'machine_name' => $m['name'],
                    'batch_no'     => $existing_batches[$mId],
                    'is_existing'  => true
                ];
            } else {
                $current_counter++;
                $batches[$mId] = [
                    'machine_id'   => $mId,
                    'machine_name' => $m['name'],
                    'batch_no'     => strval($current_counter),
                    'is_existing'  => false
                ];
            }
        }

        return $batches;
    }
}

if (!function_exists('resolve_machine_batch_no')) {
    /**
     * Resolves the Batch Number for a single Card Machine on a specific Date and Shift.
     *
     * @param mysqli $connection
     * @param int $machine_id
     * @param string $sale_date
     * @param int $shift_id
     * @return string
     */
    function resolve_machine_batch_no($connection, $machine_id, $sale_date, $shift_id) {
        $batches = get_shift_machine_batches($connection, $sale_date, $shift_id);
        $mId = intval($machine_id);
        if (isset($batches[$mId]['batch_no'])) {
            return $batches[$mId]['batch_no'];
        }
        return '1';
    }
}
