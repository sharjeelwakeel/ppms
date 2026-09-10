<?php
/**
 * Daily Nozzle Meter Readings Synchronization Helper (Decommissioned)
 * Retained as safe no-op stubs for backward compatibility.
 */

if (!function_exists('sync_nozzle_daily_meter_reading')) {
    function sync_nozzle_daily_meter_reading($connection, $date, $shift_id, $nozzle_id, $last_reading, $current_reading, $net_sale) {
        return true;
    }
}

if (!function_exists('sync_nozzle_daily_card_sale_delta')) {
    function sync_nozzle_daily_card_sale_delta($connection, $date, $shift_id, $nozzle_id, $delta_qty) {
        return true;
    }
}

if (!function_exists('sync_nozzle_daily_dip_reading')) {
    function sync_nozzle_daily_dip_reading($connection, $date, $shift_id, $nozzle_id, $tank_id, $current_reading, $prev_reading) {
        return true;
    }
}
