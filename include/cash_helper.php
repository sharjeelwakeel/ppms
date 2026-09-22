<?php
/**
 * PPMS Cash Sales Helper Functions
 * Provides schema initialization, active nozzle/shift lookups, and cash sale calculations.
 */

if (!function_exists('init_cash_sales_schema')) {
    /**
     * Self-healing migration for tbl_meter_reading_cash_sales
     */
    function init_cash_sales_schema($connection) {
        $sql = "CREATE TABLE IF NOT EXISTS `tbl_meter_reading_cash_sales` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `meter_reading_id` INT(11) NOT NULL DEFAULT 0,
            `sale_date` DATE NOT NULL,
            `shift_id` INT(11) NOT NULL DEFAULT 0,
            `staff_id` INT(11) NOT NULL DEFAULT 0,
            `nozzle_id` INT(11) NOT NULL,
            `item_id` INT(11) NOT NULL,
            `rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `notes` VARCHAR(255) DEFAULT NULL,
            `created_by` INT(11) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_sale_date` (`sale_date`),
            KEY `idx_shift_id` (`shift_id`),
            KEY `idx_nozzle_id` (`nozzle_id`),
            KEY `idx_item_id` (`item_id`),
            KEY `idx_deleted_at` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
        
        mysqli_query($connection, $sql);
    }
}

if (!function_exists('get_active_nozzles_with_items')) {
    /**
     * Fetches all active nozzles with their linked fuel item and active cash rate
     * @param mysqli $connection
     * @return array
     */
    function get_active_nozzles_with_items($connection) {
        $nozzles = [];
        $sql = "SELECT n.id, n.name, n.item_id, n.tank_id, n.start_reading,
                       i.name AS item_name, i.cash_rate, i.credit_rate, i.unit
                FROM tbl_nozzles n
                LEFT JOIN tbl_items i ON n.item_id = i.id
                WHERE (n.deleted_at IS NULL OR n.deleted_at = '0000-00-00 00:00:00')
                  AND n.status = 'Active'
                ORDER BY n.name ASC";
        $res = mysqli_query($connection, $sql);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $nozzles[] = $row;
            }
        }
        return $nozzles;
    }
}

if (!function_exists('get_active_shifts')) {
    /**
     * Fetches all active shifts
     * @param mysqli $connection
     * @return array
     */
    function get_active_shifts($connection) {
        $shifts = [];
        $sql = "SELECT id, name 
                FROM tbl_shifts 
                WHERE status = 'Active' 
                  AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') 
                ORDER BY id ASC";
        $res = mysqli_query($connection, $sql);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $shifts[] = $row;
            }
        }
        return $shifts;
    }
}

if (!function_exists('get_fuel_items')) {
    /**
     * Fetches all active fuel items
     * @param mysqli $connection
     * @return array
     */
    function get_fuel_items($connection) {
        $items = [];
        $sql = "SELECT id, name, cash_rate, credit_rate, unit 
                FROM tbl_items 
                WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
                ORDER BY name ASC";
        $res = mysqli_query($connection, $sql);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $items[] = $row;
            }
        }
        return $items;
    }
}
