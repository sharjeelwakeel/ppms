<?php
/**
 * Polymorphic Pricing & Price History Helper (PPMS)
 * Manages 1-to-Many price relationships across items, products, and inventory entities.
 * Supports single active price enforcement, audit trail, and automatic cache synchronization.
 */

if (!function_exists('init_prices_table')) {
    /**
     * Idempotently create tbl_prices table if missing and seed initial baseline prices
     */
    function init_prices_table($connection) {
        $check_tbl = mysqli_query($connection, "SHOW TABLES LIKE 'tbl_prices'");
        if (!$check_tbl || mysqli_num_rows($check_tbl) === 0) {
            $create_sql = "CREATE TABLE IF NOT EXISTS `tbl_prices` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `table_name` VARCHAR(64) NOT NULL,
                `table_id` INT(11) NOT NULL,
                `cash_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `credit_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `purchase_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `effective_date` DATE NOT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `notes` VARCHAR(255) DEFAULT NULL,
                `created_by` INT(11) DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
                `deleted_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_table_entity` (`table_name`, `table_id`),
                KEY `idx_active_lookup` (`table_name`, `table_id`, `is_active`),
                KEY `idx_effective_date` (`effective_date`),
                KEY `idx_deleted_at` (`deleted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
            mysqli_query($connection, $create_sql);
        }

        // Seed existing active items from tbl_items if missing from tbl_prices
        $seed_q = mysqli_query($connection, "
            SELECT i.id, i.cash_rate, i.credit_rate, i.purchase_rate, DATE(i.created_at) as cdate
            FROM tbl_items i
            LEFT JOIN tbl_prices p ON (p.table_name = 'tbl_items' AND p.table_id = i.id)
            WHERE (i.deleted_at IS NULL OR i.deleted_at = '0000-00-00 00:00:00')
              AND p.id IS NULL
        ");
        if ($seed_q && mysqli_num_rows($seed_q) > 0) {
            while ($item = mysqli_fetch_assoc($seed_q)) {
                $itemId     = intval($item['id']);
                $cRate      = floatval($item['cash_rate']);
                $crRate     = floatval($item['credit_rate']);
                $pRate      = floatval($item['purchase_rate']);
                $effDate    = !empty($item['cdate']) ? $item['cdate'] : date('Y-m-d');
                
                mysqli_query($connection, "
                    INSERT INTO tbl_prices 
                    (table_name, table_id, cash_rate, credit_rate, purchase_rate, effective_date, is_active, notes)
                    VALUES 
                    ('tbl_items', '$itemId', '$cRate', '$crRate', '$pRate', '$effDate', 1, 'Initial baseline rate')
                ");
            }
        }
    }
}

if (!function_exists('set_active_price')) {
    /**
     * Deactivate previous prices for the entity, insert new active price, and sync target table cache
     *
     * @param mysqli $connection
     * @param string $table_name e.g. 'tbl_items' or 'tbl_products'
     * @param int $table_id ID of item/product
     * @param float $cash_rate
     * @param float $credit_rate
     * @param float $purchase_rate
     * @param string $effective_date YYYY-MM-DD
     * @param string $notes Optional revision note
     * @param int $user_id User account ID
     * @return bool
     */
    function set_active_price($connection, $table_name, $table_id, $cash_rate, $credit_rate, $purchase_rate, $effective_date, $notes = '', $user_id = 0) {
        $table_name    = mysqli_real_escape_string($connection, trim($table_name));
        $table_id      = intval($table_id);
        $cash_rate     = floatval($cash_rate);
        $credit_rate   = floatval($credit_rate);
        $purchase_rate = floatval($purchase_rate);
        $effective_date = !empty($effective_date) ? mysqli_real_escape_string($connection, trim($effective_date)) : date('Y-m-d');
        $notes         = mysqli_real_escape_string($connection, trim($notes));
        $user_id       = intval($user_id);
        $user_sql      = ($user_id > 0) ? "'$user_id'" : "NULL";

        if (empty($table_name) || $table_id <= 0) {
            return false;
        }

        // 1. Mark existing active prices for this entity as historical (inactive)
        mysqli_query($connection, "
            UPDATE tbl_prices 
            SET is_active = 0 
            WHERE table_name = '$table_name' 
              AND table_id = '$table_id' 
              AND is_active = 1
        ");

        // 2. Insert new active price record
        $ins_sql = "
            INSERT INTO tbl_prices 
            (table_name, table_id, cash_rate, credit_rate, purchase_rate, effective_date, is_active, notes, created_by)
            VALUES 
            ('$table_name', '$table_id', '$cash_rate', '$credit_rate', '$purchase_rate', '$effective_date', 1, '$notes', $user_sql)
        ";
        $ok = mysqli_query($connection, $ins_sql);

        // 3. Synchronize target table cache for zero-breakage backward compatibility
        if ($ok) {
            if ($table_name === 'tbl_items') {
                mysqli_query($connection, "
                    UPDATE tbl_items 
                    SET cash_rate = '$cash_rate',
                        credit_rate = '$credit_rate',
                        purchase_rate = '$purchase_rate',
                        updated_at = NOW()
                    WHERE id = '$table_id'
                ");
            } elseif ($table_name === 'tbl_lubricant_products' || $table_name === 'tbl_products') {
                mysqli_query($connection, "
                    UPDATE tbl_lubricant_products 
                    SET price = '$cash_rate',
                        updated_at = NOW()
                    WHERE id = '$table_id'
                ");
            }
        }

        return $ok;
    }
}

if (!function_exists('get_price_history')) {
    /**
     * Retrieve chronological price history for an entity
     *
     * @param mysqli $connection
     * @param string $table_name
     * @param int $table_id
     * @return array
     */
    function get_price_history($connection, $table_name, $table_id) {
        $table_name = mysqli_real_escape_string($connection, trim($table_name));
        $table_id   = intval($table_id);
        $history    = [];

        $q = mysqli_query($connection, "
            SELECT p.*, a.username as created_by_name
            FROM tbl_prices p
            LEFT JOIN tbl_accounts a ON (p.created_by = a.id)
            WHERE p.table_name = '$table_name' 
              AND p.table_id = '$table_id'
              AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
            ORDER BY p.is_active DESC, p.effective_date DESC, p.id DESC
        ");

        if ($q) {
            while ($row = mysqli_fetch_assoc($q)) {
                $history[] = $row;
            }
        }

        return $history;
    }
}

if (!function_exists('soft_delete_price')) {
    /**
     * Soft delete a price record by setting deleted_at = NOW().
     * If the deleted price was active (is_active = 1), automatically promotes
     * the most recent remaining non-deleted price to active and synchronizes target table cache.
     *
     * @param mysqli $connection
     * @param int $price_id
     * @return bool
     */
    function soft_delete_price($connection, $price_id) {
        $price_id = intval($price_id);
        if ($price_id <= 0) {
            return false;
        }

        // 1. Fetch the target price record
        $chk_q = mysqli_query($connection, "
            SELECT * FROM tbl_prices 
            WHERE id = '$price_id' 
              AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
            LIMIT 1
        ");
        if (!$chk_q || mysqli_num_rows($chk_q) === 0) {
            return false;
        }
        $target = mysqli_fetch_assoc($chk_q);
        $was_active = (intval($target['is_active']) === 1);
        $table_name = $target['table_name'];
        $table_id   = intval($target['table_id']);

        // 2. Perform soft delete
        $del_sql = "UPDATE tbl_prices SET deleted_at = NOW(), is_active = 0 WHERE id = '$price_id'";
        $del_ok = mysqli_query($connection, $del_sql);
        if (!$del_ok) {
            return false;
        }

        // 3. If the deleted price was active, promote the most recent remaining non-deleted price
        if ($was_active) {
            $prev_q = mysqli_query($connection, "
                SELECT * FROM tbl_prices 
                WHERE table_name = '$table_name' 
                  AND table_id = '$table_id'
                  AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
                ORDER BY effective_date DESC, id DESC
                LIMIT 1
            ");

            if ($prev_q && mysqli_num_rows($prev_q) > 0) {
                $prev = mysqli_fetch_assoc($prev_q);
                $prev_id = intval($prev['id']);
                $new_cash = floatval($prev['cash_rate']);
                $new_credit = floatval($prev['credit_rate']);
                $new_purchase = floatval($prev['purchase_rate']);

                // Promote remaining price to active
                mysqli_query($connection, "UPDATE tbl_prices SET is_active = 1 WHERE id = '$prev_id'");

                // Sync parent cache
                if ($table_name === 'tbl_items') {
                    mysqli_query($connection, "
                        UPDATE tbl_items 
                        SET cash_rate = '$new_cash',
                            credit_rate = '$new_credit',
                            purchase_rate = '$new_purchase',
                            updated_at = NOW()
                        WHERE id = '$table_id'
                    ");
                } elseif ($table_name === 'tbl_lubricant_products' || $table_name === 'tbl_products') {
                    mysqli_query($connection, "
                        UPDATE tbl_lubricant_products 
                        SET price = '$new_cash',
                            updated_at = NOW()
                        WHERE id = '$table_id'
                    ");
                }
            }
        }

        return true;
    }
}

if (!function_exists('get_active_price')) {
    /**
     * Retrieve the currently active price record for an entity
     */
    function get_active_price($connection, $table_name, $table_id) {
        $table_name = mysqli_real_escape_string($connection, trim($table_name));
        $table_id   = intval($table_id);

        $q = mysqli_query($connection, "
            SELECT * FROM tbl_prices 
            WHERE table_name = '$table_name' 
              AND table_id = '$table_id' 
              AND is_active = 1
              AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
            LIMIT 1
        ");

        if ($q && $row = mysqli_fetch_assoc($q)) {
            return $row;
        }

        return null;
    }
}

if (!function_exists('get_price_for_date')) {
    /**
     * Retrieve the effective price record for an entity on a specific date.
     * If $target_date >= today: returns currently active price.
     * If $target_date < today: queries the price effective on or immediately prior to that date.
     *
     * @param mysqli $connection
     * @param string $table_name
     * @param int $table_id
     * @param string $target_date YYYY-MM-DD
     * @return array|null
     */
    function get_price_for_date($connection, $table_name, $table_id, $target_date = '') {
        $table_name  = mysqli_real_escape_string($connection, trim($table_name));
        $table_id    = intval($table_id);
        $today       = date('Y-m-d');
        $target_date = !empty($target_date) ? mysqli_real_escape_string($connection, trim($target_date)) : $today;

        if (empty($table_name) || $table_id <= 0) {
            return null;
        }

        // If today or in the future: return active price
        if ($target_date >= $today) {
            return get_active_price($connection, $table_name, $table_id);
        }

        // If backdated: find the price that was effective on or immediately before $target_date
        $q = mysqli_query($connection, "
            SELECT * FROM tbl_prices 
            WHERE table_name = '$table_name' 
              AND table_id = '$table_id'
              AND effective_date <= '$target_date'
              AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
            ORDER BY effective_date DESC, id DESC
            LIMIT 1
        ");

        if ($q && $row = mysqli_fetch_assoc($q)) {
            return $row;
        }

        // Fallback to active price if no earlier record exists
        return get_active_price($connection, $table_name, $table_id);
    }
}

