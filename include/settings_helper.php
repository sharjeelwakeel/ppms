<?php
/**
 * Settings Helper Service for PPMS
 * Provides self-healing table migration and centralized settings retrieval.
 */

if (!defined('SETTINGS_HELPER_LOADED')) {
    define('SETTINGS_HELPER_LOADED', true);

    /**
     * Auto-migrate tbl_settings if it does not exist
     */
    function auto_migrate_settings_table($connection) {
        if (!$connection) return;

        $check = mysqli_query($connection, "SHOW TABLES LIKE 'tbl_settings'");
        if ($check && mysqli_num_rows($check) == 0) {
            $create_sql = "CREATE TABLE IF NOT EXISTS `tbl_settings` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `pump_name` VARCHAR(255) NOT NULL DEFAULT 'PPMS Petrol Pump',
                `tagline` VARCHAR(255) DEFAULT 'Petrol Pump Management System',
                `phone` VARCHAR(100) DEFAULT '',
                `email` VARCHAR(100) DEFAULT '',
                `address` TEXT DEFAULT NULL,
                `city` VARCHAR(100) DEFAULT '',
                `ntn_no` VARCHAR(100) DEFAULT '',
                `license_no` VARCHAR(100) DEFAULT '',
                `receipt_footer` TEXT DEFAULT 'Thank you for your business! Fuel once sold will not be returned.',
                `logo_path` VARCHAR(255) DEFAULT '',
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            
            mysqli_query($connection, $create_sql);

            // Seed initial default record
            $seed_sql = "INSERT INTO `tbl_settings` 
                (`id`, `pump_name`, `tagline`, `phone`, `email`, `address`, `city`, `ntn_no`, `license_no`, `receipt_footer`, `logo_path`)
                VALUES (1, 'PPMS Petrol Pump', 'Authorized Petroleum & Lubricants Dealer', '+92 300 1234567', 'info@ppms.pk', 'Main Highway Road', 'City', '', '', 'Thank you for your business! Fuel once sold will not be returned.', '')
                ON DUPLICATE KEY UPDATE `id` = 1";
            mysqli_query($connection, $seed_sql);
        }
    }

    /**
     * Get system/station settings as an associative array
     * @param mysqli $connection
     * @return array
     */
    function get_station_settings($connection) {
        static $cached_settings = null;
        if ($cached_settings !== null) {
            return $cached_settings;
        }

        $defaults = [
            'id'             => 1,
            'pump_name'      => 'PPMS Petrol Pump',
            'tagline'        => 'Authorized Petroleum & Lubricants Dealer',
            'phone'          => '',
            'email'          => '',
            'address'        => '',
            'city'           => '',
            'ntn_no'         => '',
            'license_no'     => '',
            'receipt_footer' => 'Thank you for your business! Fuel once sold will not be returned.',
            'logo_path'      => ''
        ];

        if (!$connection) {
            return $defaults;
        }

        auto_migrate_settings_table($connection);

        $res = mysqli_query($connection, "SELECT * FROM tbl_settings WHERE id = 1 LIMIT 1");
        if ($res && ($row = mysqli_fetch_assoc($res))) {
            $cached_settings = array_merge($defaults, $row);
            return $cached_settings;
        }

        $cached_settings = $defaults;
        return $cached_settings;
    }
}
