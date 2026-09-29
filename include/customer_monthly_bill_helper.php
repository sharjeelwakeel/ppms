<?php
/**
 * Customer Overall Monthly Credit Bill Helper
 * PPMS (Petrol Pump Management System)
 * 
 * Provides unified data extraction for Fuel & Lubricant Credit Sales,
 * auto-migration for bill numbering persistence, outstanding dues filtering,
 * and English amount-to-words currency formatting.
 */

if (!defined('CUSTOMER_MONTHLY_BILL_HELPER_LOADED')) {
    define('CUSTOMER_MONTHLY_BILL_HELPER_LOADED', true);

    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/settings_helper.php';

    /**
     * Auto-migrate required tables and columns for monthly credit bills
     */
    function auto_migrate_monthly_bill_tables($connection) {
        if (!$connection) return;

        // 1. Ensure tbl_customer_monthly_bills exists
        $check_tbl = mysqli_query($connection, "SHOW TABLES LIKE 'tbl_customer_monthly_bills'");
        if ($check_tbl && mysqli_num_rows($check_tbl) == 0) {
            $create_sql = "CREATE TABLE IF NOT EXISTS `tbl_customer_monthly_bills` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `bill_no` VARCHAR(32) NOT NULL,
                `customer_id` INT(11) NOT NULL,
                `from_date` DATE NOT NULL,
                `to_date` DATE NOT NULL,
                `vehicle_number` VARCHAR(64) DEFAULT NULL,
                `attention_to` VARCHAR(255) DEFAULT NULL,
                `total_coupons` INT(11) NOT NULL DEFAULT 0,
                `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `fuel_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `lubricant_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `generated_by` INT(11) DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_cust_dates` (`customer_id`, `from_date`, `to_date`),
                KEY `idx_bill_no` (`bill_no`),
                KEY `idx_deleted_at` (`deleted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            mysqli_query($connection, $create_sql);
        } else {
            // Check for additional snapshot columns
            $chk_f = mysqli_query($connection, "SHOW COLUMNS FROM `tbl_customer_monthly_bills` LIKE 'fuel_amount'");
            if ($chk_f && mysqli_num_rows($chk_f) == 0) {
                mysqli_query($connection, "ALTER TABLE `tbl_customer_monthly_bills` ADD COLUMN `fuel_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `total_amount`");
            }
            $chk_l = mysqli_query($connection, "SHOW COLUMNS FROM `tbl_customer_monthly_bills` LIKE 'lubricant_amount'");
            if ($chk_l && mysqli_num_rows($chk_l) == 0) {
                mysqli_query($connection, "ALTER TABLE `tbl_customer_monthly_bills` ADD COLUMN `lubricant_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `fuel_amount`");
            }
            $chk_u = mysqli_query($connection, "SHOW COLUMNS FROM `tbl_customer_monthly_bills` LIKE 'generated_by'");
            if ($chk_u && mysqli_num_rows($chk_u) == 0) {
                mysqli_query($connection, "ALTER TABLE `tbl_customer_monthly_bills` ADD COLUMN `generated_by` INT(11) DEFAULT NULL AFTER `lubricant_amount`");
            }
            $chk_d = mysqli_query($connection, "SHOW COLUMNS FROM `tbl_customer_monthly_bills` LIKE 'deleted_at'");
            if ($chk_d && mysqli_num_rows($chk_d) == 0) {
                mysqli_query($connection, "ALTER TABLE `tbl_customer_monthly_bills` ADD COLUMN `deleted_at` DATETIME DEFAULT NULL AFTER `updated_at`, ADD INDEX `idx_deleted_at` (`deleted_at`)");
            }
        }

        // 2. Ensure tbl_customers has attention_line column
        $chk_cust = mysqli_query($connection, "SHOW COLUMNS FROM `tbl_customers` LIKE 'attention_line'");
        if ($chk_cust && mysqli_num_rows($chk_cust) == 0) {
            mysqli_query($connection, "ALTER TABLE `tbl_customers` ADD COLUMN `attention_line` VARCHAR(255) DEFAULT NULL AFTER `address`");
        }

        // 3. Ensure tbl_lubricant_sales.quantity supports decimals (e.g. 1.50 cans)
        $q_check = mysqli_query($connection, "SHOW COLUMNS FROM `tbl_lubricant_sales` LIKE 'quantity'");
        if ($q_check && $row = mysqli_fetch_assoc($q_check)) {
            if (stripos($row['Type'], 'int') !== false) {
                mysqli_query($connection, "ALTER TABLE `tbl_lubricant_sales` MODIFY COLUMN `quantity` DECIMAL(10,2) NOT NULL DEFAULT 0.00");
            }
        }
    }

    /**
     * Convert an integer or float amount into English words for Rupee checks/vouchers.
     * Example: 133886.61 => ( RUPEES ONE HUNDRED THIRTY-THREE THOUSAND EIGHT HUNDRED EIGHTY-SIX AND 61 / 100 Only )
     */
    function convert_number_to_rupees_words($amount) {
        $amount = round(floatval($amount), 2);
        if ($amount <= 0) {
            return "( RUPEES ZERO ONLY )";
        }

        $units = ["", "ONE", "TWO", "THREE", "FOUR", "FIVE", "SIX", "SEVEN", "EIGHT", "NINE", "TEN", 
                  "ELEVEN", "TWELVE", "THIRTEEN", "FOURTEEN", "FIFTEEN", "SIXTEEN", "SEVENTEEN", "EIGHTEEN", "NINETEEN"];
        $tens  = ["", "", "TWENTY", "THIRTY", "FORTY", "FIFTY", "SIXTY", "SEVENTY", "EIGHTY", "NINETY"];

        $convert_triplet = function($num) use ($units, $tens, &$convert_triplet) {
            $str = "";
            if ($num >= 100) {
                $str .= $units[intval($num / 100)] . " HUNDRED ";
                $num %= 100;
            }
            if ($num >= 20) {
                $str .= $tens[intval($num / 10)];
                if ($num % 10 > 0) {
                    $str .= "-" . $units[$num % 10];
                }
                $str .= " ";
            } elseif ($num > 0) {
                $str .= $units[$num] . " ";
            }
            return trim($str);
        };

        $rupees = intval(floor($amount));
        $paisa  = intval(round(($amount - $rupees) * 100));

        $words = "";

        if ($rupees >= 1000000000) { // Billions
            $billion = intval($rupees / 1000000000);
            $words .= $convert_triplet($billion) . " BILLION ";
            $rupees %= 1000000000;
        }

        if ($rupees >= 1000000) { // Millions
            $million = intval($rupees / 1000000);
            $words .= $convert_triplet($million) . " MILLION ";
            $rupees %= 1000000;
        }

        if ($rupees >= 1000) { // Thousands (e.g. 133 Thousand)
            $thousand = intval($rupees / 1000);
            $words .= $convert_triplet($thousand) . " THOUSAND ";
            $rupees %= 1000;
        }

        if ($rupees > 0) {
            $words .= $convert_triplet($rupees) . " ";
        }

        $words = trim($words);
        if (empty($words)) {
            $words = "ZERO";
        }

        if ($paisa > 0) {
            return "( RUPEES " . $words . " AND " . sprintf("%02d", $paisa) . " / 100 Only )";
        } else {
            return "( RUPEES " . $words . " ONLY )";
        }
    }

    /**
     * Get or assign sequential Bill Number
     */
    function get_or_create_monthly_bill_no($connection, $customer_id, $from_date, $to_date, $vehicle_number = '', $custom_bill_no = '', $attention_to = '') {
        auto_migrate_monthly_bill_tables($connection);

        $customer_id = intval($customer_id);
        $from_safe = mysqli_real_escape_string($connection, $from_date);
        $to_safe   = mysqli_real_escape_string($connection, $to_date);
        $v_safe    = mysqli_real_escape_string($connection, trim($vehicle_number));
        $att_safe  = mysqli_real_escape_string($connection, trim($attention_to));
        $custom_bill_no = trim($custom_bill_no);

        // Check for existing bill record
        $check_sql = "SELECT id, bill_no, attention_to FROM `tbl_customer_monthly_bills` 
                      WHERE `customer_id` = '$customer_id' 
                        AND `from_date` = '$from_safe' 
                        AND `to_date` = '$to_safe' 
                      LIMIT 1";
        $res = mysqli_query($connection, $check_sql);

        if ($res && mysqli_num_rows($res) > 0) {
            $row = mysqli_fetch_assoc($res);
            $existing_id = $row['id'];
            $existing_bill_no = $row['bill_no'];

            // Update if custom bill no or attention to was provided
            if (!empty($custom_bill_no) && $custom_bill_no !== $existing_bill_no) {
                $new_bill_safe = mysqli_real_escape_string($connection, $custom_bill_no);
                mysqli_query($connection, "UPDATE `tbl_customer_monthly_bills` SET `bill_no` = '$new_bill_safe', `attention_to` = '$att_safe' WHERE `id` = '$existing_id'");
                return $custom_bill_no;
            }
            if (!empty($att_safe) && $att_safe !== $row['attention_to']) {
                mysqli_query($connection, "UPDATE `tbl_customer_monthly_bills` SET `attention_to` = '$att_safe' WHERE `id` = '$existing_id'");
            }
            return $existing_bill_no;
        }

        // Determine next bill number
        if (!empty($custom_bill_no)) {
            $next_bill_no = $custom_bill_no;
        } else {
            $max_q = mysqli_query($connection, "SELECT MAX(CAST(bill_no AS UNSIGNED)) as max_no FROM `tbl_customer_monthly_bills` WHERE bill_no REGEXP '^[0-9]+$'");
            $max_val = 0;
            if ($max_q && $m_row = mysqli_fetch_assoc($max_q)) {
                $max_val = intval($m_row['max_no']);
            }
            $next_bill_no = ($max_val >= 64343) ? strval($max_val + 1) : "64343";
        }

        $ins_bill_safe = mysqli_real_escape_string($connection, $next_bill_no);
        $insert_sql = "INSERT INTO `tbl_customer_monthly_bills` 
            (`bill_no`, `customer_id`, `from_date`, `to_date`, `vehicle_number`, `attention_to`)
            VALUES ('$ins_bill_safe', '$customer_id', '$from_safe', '$to_safe', '$v_safe', '$att_safe')";
        mysqli_query($connection, $insert_sql);

        return $next_bill_no;
    }

    /**
     * Get list of customers who currently have outstanding dues (unpaid / partial vouchers)
     * Useful for filter dropdown badges and quick filtering.
     */
    function get_customers_with_dues_summary($connection) {
        $customers = [];

        // 1. Unpaid Fuel credit sales
        $fuel_sql = "SELECT account_number, 
                            COUNT(id) AS unpaid_fuel_count, 
                            SUM(charge_amount - paid_amount) AS unpaid_fuel_amount
                     FROM tbl_meter_reading_credit_sales
                     WHERE slip_type = 'Permanent Slip'
                       AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
                       AND (payment_status != 'Paid' OR (charge_amount - paid_amount) > 0.005)
                     GROUP BY account_number";
        $f_res = mysqli_query($connection, $fuel_sql);
        $fuel_map = [];
        if ($f_res) {
            while ($r = mysqli_fetch_assoc($f_res)) {
                $c_id = intval($r['account_number']);
                $fuel_map[$c_id] = [
                    'count'  => intval($r['unpaid_fuel_count']),
                    'amount' => floatval($r['unpaid_fuel_amount'])
                ];
            }
        }

        // 2. Unpaid Lubricants credit sales
        $lub_sql = "SELECT customer_id, 
                           COUNT(id) AS unpaid_lub_count, 
                           SUM(charge_amount - paid_amount) AS unpaid_lub_amount
                    FROM tbl_lubricant_sale_invoices
                    WHERE (payment_type = 'Credit' OR slip_type = 'Permanent Slip')
                      AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
                      AND (payment_status != 'Paid' OR (charge_amount - paid_amount) > 0.005)
                    GROUP BY customer_id";
        $l_res = mysqli_query($connection, $lub_sql);
        $lub_map = [];
        if ($l_res) {
            while ($r = mysqli_fetch_assoc($l_res)) {
                $c_id = intval($r['customer_id']);
                $lub_map[$c_id] = [
                    'count'  => intval($r['unpaid_lub_count']),
                    'amount' => floatval($r['unpaid_lub_amount'])
                ];
            }
        }

        // 3. Fetch all active customers
        $cust_res = mysqli_query($connection, "SELECT id, name, phone, fuel_rate, other_rate, address FROM tbl_customers WHERE deleted_at IS NULL ORDER BY name ASC");
        if ($cust_res) {
            while ($crow = mysqli_fetch_assoc($cust_res)) {
                $cid = intval($crow['id']);
                $f_due = $fuel_map[$cid]['amount'] ?? 0.0;
                $f_cnt = $fuel_map[$cid]['count'] ?? 0;
                $l_due = $lub_map[$cid]['amount'] ?? 0.0;
                $l_cnt = $lub_map[$cid]['count'] ?? 0;
                $total_due = $f_due + $l_due;
                $total_vouchers = $f_cnt + $l_cnt;

                $customers[] = [
                    'id'              => $cid,
                    'name'            => $crow['name'],
                    'phone'           => $crow['phone'] ?? '',
                    'address'         => $crow['address'] ?? '',
                    'fuel_rate'       => $crow['fuel_rate'],
                    'other_rate'      => $crow['other_rate'],
                    'total_due'       => $total_due,
                    'total_vouchers'  => $total_vouchers,
                    'has_outstanding' => ($total_due > 0.005)
                ];
            }
        }

        return $customers;
    }

    /**
     * Format fuel item description by prepending brand 'Action+' to the item name.
     * Takes whatever name comes from tbl_items and dynamically adds 'Action+ '
     * without restricting or overriding specific fuel types.
     *
     * @param string|null $raw_name
     * @return string
     */
    function format_fuel_item_description($raw_name) {
        $trimmed = trim(strval($raw_name));
        if ($trimmed === '') {
            return 'Action+ Fuel';
        }

        // Clean up and preserve existing 'Action+' prefix (case-insensitive)
        if (stripos($trimmed, 'action+') === 0) {
            $rest = trim(substr($trimmed, 7));
            return 'Action+ ' . (empty($rest) ? '' : ucwords($rest));
        }
        if (stripos($trimmed, 'action +') === 0) {
            $rest = trim(substr($trimmed, 8));
            return 'Action+ ' . (empty($rest) ? '' : ucwords($rest));
        }

        // Prepend 'Action+ ' to the item name coming from tbl_items
        return 'Action+ ' . ucwords($trimmed);
    }

    /**
     * Retrieve aggregated monthly bill data for a specific customer & date range.
     * 
     * @param mysqli $connection
     * @param int $customer_id
     * @param string $from_date
     * @param string $to_date
     * @param string $vehicle_number Optional filter
     * @param array $options ['status_filter' => 'outstanding' | 'all', 'bill_no' => '', 'attention_to' => '']
     * @return array
     */
    function get_customer_monthly_bill_data($connection, $customer_id, $from_date, $to_date, $vehicle_number = '', $options = []) {
        auto_migrate_monthly_bill_tables($connection);

        $customer_id = intval($customer_id);
        $from_safe   = mysqli_real_escape_string($connection, $from_date);
        $to_safe     = mysqli_real_escape_string($connection, $to_date);
        $v_filter    = trim($vehicle_number);
        $v_safe      = mysqli_real_escape_string($connection, $v_filter);

        $status_filter = $options['status_filter'] ?? 'outstanding'; // Default: only show what customer needs to pay
        $custom_bill_no = trim($options['bill_no'] ?? '');
        $attention_to   = trim($options['attention_to'] ?? '');

        // Fetch Customer Details
        $cust_q = mysqli_query($connection, "SELECT * FROM `tbl_customers` WHERE `id` = '$customer_id' LIMIT 1");
        $customer = ($cust_q && mysqli_num_rows($cust_q) > 0) ? mysqli_fetch_assoc($cust_q) : null;

        // Fetch Attention Line: prioritize customer profile attention_line
        if (empty($attention_to) && $customer && !empty($customer['attention_line'])) {
            $attention_to = $customer['attention_line'];
        }
        if (empty($attention_to)) {
            $prev_b = mysqli_query($connection, "SELECT attention_to FROM `tbl_customer_monthly_bills` WHERE `customer_id` = '$customer_id' AND `from_date` = '$from_safe' AND `to_date` = '$to_safe' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
            if ($prev_b && $pb_row = mysqli_fetch_assoc($prev_b)) {
                $attention_to = $pb_row['attention_to'];
            }
        }

        // Get or generate Bill No
        $bill_no = get_or_create_monthly_bill_no($connection, $customer_id, $from_date, $to_date, $vehicle_number, $custom_bill_no, $attention_to);

        // Fetch Station Settings
        $station = get_station_settings($connection);

        $transactions = [];

        // 1. Fetch Fuel Transactions (Permanent Slips)
        $fuel_where = [
            "mrcs.account_number = '$customer_id'",
            "mrcs.slip_type = 'Permanent Slip'",
            "(mrcs.deleted_at IS NULL OR mrcs.deleted_at = '0000-00-00 00:00:00')",
            "mrcs.slip_date BETWEEN '$from_safe' AND '$to_safe'"
        ];

        if (!empty($v_filter)) {
            $fuel_where[] = "mrcs.vehicle_number = '$v_safe'";
        }

        // Strict Outstanding filter: exclusively bill unpaid/partial vouchers where money is owed
        $fuel_where[] = "(mrcs.payment_status != 'Paid' OR (mrcs.charge_amount - mrcs.paid_amount) > 0.005)";

        $fuel_sql = "SELECT 
                        mrcs.id,
                        mrcs.slip_date,
                        mrcs.slip_no,
                        mrcs.vehicle_number,
                        mrcs.quantity,
                        mrcs.rate,
                        mrcs.amount,
                        mrcs.charge_amount,
                        mrcs.paid_amount,
                        mrcs.payment_status,
                        COALESCE(i.name, 'Diesel') AS product_name,
                        'Fuel' AS item_type,
                        'ltr' AS unit
                     FROM tbl_meter_reading_credit_sales mrcs
                     LEFT JOIN tbl_nozzles n ON mrcs.nozzle_id = n.id
                     LEFT JOIN tbl_items i ON n.item_id = i.id
                     WHERE " . implode(' AND ', $fuel_where) . "
                     ORDER BY mrcs.slip_date ASC, mrcs.slip_no ASC";

        $fuel_res = mysqli_query($connection, $fuel_sql);
        if ($fuel_res) {
            while ($row = mysqli_fetch_assoc($fuel_res)) {
                $charge = floatval($row['charge_amount'] > 0 ? $row['charge_amount'] : $row['amount']);
                $paid   = floatval($row['paid_amount']);
                $due    = max(0.0, $charge - $paid);

                // If balance is 0 or less, skip completely
                if ($due <= 0.005) {
                    continue;
                }

                // In an outstanding demand bill, the billed balance is the net unpaid amount owed
                $effective_amount = ($paid > 0) ? $due : $charge;

                $transactions[] = [
                    'source_id'      => intval($row['id']),
                    'date'           => $row['slip_date'],
                    'date_formatted' => date('d/m/Y', strtotime($row['slip_date'])),
                    'coupon'         => $row['slip_no'],
                    'vehicle'        => $row['vehicle_number'],
                    'description'    => format_fuel_item_description($row['product_name']),
                    'quantity'       => floatval($row['quantity']),
                    'rate'           => floatval($row['rate']),
                    'amount'         => $charge,
                    'paid_amount'    => $paid,
                    'balance_due'    => $due,
                    'billed_amount'  => $effective_amount,
                    'payment_status' => $row['payment_status'],
                    'item_type'      => 'Fuel',
                    'unit'           => 'ltr'
                ];
            }
        }

        // 2. Fetch Lubricants / Other Products Transactions
        $lub_where = [
            "lsi.customer_id = '$customer_id'",
            "(lsi.payment_type = 'Credit' OR lsi.slip_type = 'Permanent Slip')",
            "(lsi.deleted_at IS NULL OR lsi.deleted_at = '0000-00-00 00:00:00')",
            "lsi.date BETWEEN '$from_safe' AND '$to_safe'"
        ];

        if (!empty($v_filter)) {
            $lub_where[] = "lsi.vehicle_number = '$v_safe'";
        }

        // Strict Outstanding filter for lubricants
        $lub_where[] = "(lsi.payment_status != 'Paid' OR (lsi.charge_amount - lsi.paid_amount) > 0.005)";

        $lub_sql = "SELECT 
                        lsi.id AS invoice_id,
                        lsi.date AS slip_date,
                        COALESCE(NULLIF(lsi.slip_no, ''), lsi.invoice_no) AS coupon_no,
                        lsi.vehicle_number,
                        ls.quantity,
                        ls.rate,
                        ls.amount,
                        lsi.charge_amount,
                        lsi.paid_amount,
                        lsi.payment_status,
                        COALESCE(p.name, 'Lubricant') AS product_name,
                        COALESCE(cat.name, '') AS category_name,
                        'Lubricant' AS item_type,
                        'can' AS unit
                    FROM tbl_lubricant_sale_invoices lsi
                    JOIN tbl_lubricant_sales ls ON lsi.id = ls.invoice_id
                    LEFT JOIN tbl_lubricant_products p ON ls.product_id = p.id
                    LEFT JOIN tbl_product_categories cat ON p.category_id = cat.id
                    WHERE " . implode(' AND ', $lub_where) . "
                    ORDER BY lsi.date ASC, coupon_no ASC";

        $lub_res = mysqli_query($connection, $lub_sql);
        if ($lub_res) {
            while ($row = mysqli_fetch_assoc($lub_res)) {
                $charge = floatval($row['charge_amount'] > 0 ? $row['charge_amount'] : $row['amount']);
                $paid   = floatval($row['paid_amount']);
                $due    = max(0.0, $charge - $paid);

                // If balance is 0 or less, skip completely
                if ($due <= 0.005) {
                    continue;
                }

                $effective_amount = ($paid > 0) ? $due : floatval($row['amount']);

                // Prioritize Category Name if available (e.g. 'Deo 6000 4L'), fallback to Product Name
                $item_desc = !empty($row['category_name']) ? $row['category_name'] : $row['product_name'];

                $transactions[] = [
                    'source_id'      => intval($row['invoice_id']),
                    'date'           => $row['slip_date'],
                    'date_formatted' => date('d/m/Y', strtotime($row['slip_date'])),
                    'coupon'         => $row['coupon_no'],
                    'vehicle'        => $row['vehicle_number'],
                    'description'    => $item_desc,
                    'quantity'       => floatval($row['quantity']),
                    'rate'           => floatval($row['rate']),
                    'amount'         => floatval($row['amount']),
                    'paid_amount'    => $paid,
                    'balance_due'    => $due,
                    'billed_amount'  => $effective_amount,
                    'payment_status' => $row['payment_status'],
                    'item_type'      => 'Lubricant',
                    'unit'           => 'can'
                ];
            }
        }

        // Sort all unified transactions chronologically
        usort($transactions, function($a, $b) {
            $d = strcmp($a['date'], $b['date']);
            if ($d !== 0) return $d;
            return strcmp($a['coupon'], $b['coupon']);
        });

        // Assign S.No and aggregate categories
        $s_no = 1;
        $total_amount = 0.0;
        $category_summary = [];
        $unique_vehicles = [];

        foreach ($transactions as &$t) {
            $t['s_no'] = $s_no++;
            $total_amount = round($total_amount + $t['billed_amount'], 2);

            if (!empty($t['vehicle'])) {
                $unique_vehicles[$t['vehicle']] = true;
            }

            // Group into Categories: Diesel, Petrol, Others
            $cat_key = 'Others';
            $unit = '';
            $desc_lower = strtolower($t['description']);

            if (strpos($desc_lower, 'diesel') !== false) {
                $cat_key = 'Diesel';
                $unit = 'ltr';
            } elseif (strpos($desc_lower, 'petrol') !== false || strpos($desc_lower, 'super') !== false || strpos($desc_lower, 'gasoline') !== false) {
                $cat_key = 'Petrol';
                $unit = 'ltr';
            } else {
                $cat_key = 'Others';
                $unit = '';
            }

            if (!isset($category_summary[$cat_key])) {
                $category_summary[$cat_key] = [
                    'name'     => $cat_key,
                    'quantity' => 0.0,
                    'amount'   => 0.0,
                    'unit'     => $unit
                ];
            }
            if ($unit === 'ltr') {
                $category_summary[$cat_key]['quantity'] = round($category_summary[$cat_key]['quantity'] + $t['quantity'], 2);
            }
            $category_summary[$cat_key]['amount'] = round($category_summary[$cat_key]['amount'] + $t['billed_amount'], 2);
        }
        unset($t);

        // Sort categories so Diesel comes first, then Petrol, then Others
        $ordered_categories = [];
        foreach (['Diesel', 'Petrol', 'Others'] as $k) {
            if (isset($category_summary[$k])) {
                $ordered_categories[$k] = $category_summary[$k];
            }
        }
        foreach ($category_summary as $k => $v) {
            if (!isset($ordered_categories[$k])) {
                $ordered_categories[$k] = $v;
            }
        }

        // Format dates for letterhead
        $from_dt = new DateTime($from_date);
        $to_dt   = new DateTime($to_date);
        $date_range_label = "From " . $from_dt->format('d M, Y') . " To " . $to_dt->format('d M, Y');

        // Vehicle Display
        $vehicle_display = '';
        if (!empty($v_filter)) {
            $vehicle_display = $v_filter;
        } elseif (count($unique_vehicles) === 1) {
            $vehicle_display = key($unique_vehicles);
        } elseif (count($unique_vehicles) > 1) {
            $vehicle_display = implode(', ', array_keys($unique_vehicles));
        } else {
            $vehicle_display = 'N/A';
        }

        // Update record in tbl_customer_monthly_bills
        $coupons_cnt = count($transactions);
        $tot_amt_db  = round($total_amount, 2);
        $fuel_amt_db = round(($ordered_categories['Diesel']['amount'] ?? 0.0) + ($ordered_categories['Petrol']['amount'] ?? 0.0), 2);
        $lub_amt_db  = round($ordered_categories['Others']['amount'] ?? 0.0, 2);
        $gen_by_db   = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : (isset($_SESSION['account_id']) ? intval($_SESSION['account_id']) : 'NULL');
        $att_db_safe = mysqli_real_escape_string($connection, $attention_to);
        $bill_no_safe = mysqli_real_escape_string($connection, $bill_no);

        mysqli_query($connection, "UPDATE `tbl_customer_monthly_bills` 
                                   SET `total_coupons` = '$coupons_cnt', 
                                       `total_amount` = '$tot_amt_db',
                                       `fuel_amount` = '$fuel_amt_db',
                                       `lubricant_amount` = '$lub_amt_db',
                                       `attention_to` = '$att_db_safe',
                                       `generated_by` = " . ($gen_by_db !== 'NULL' ? "'$gen_by_db'" : "NULL") . " 
                                   WHERE `bill_no` = '$bill_no_safe' 
                                     AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");

        return [
            'station'          => $station,
            'customer'         => $customer,
            'customer_id'      => $customer_id,
            'attention_to'     => $attention_to,
            'bill_no'          => $bill_no,
            'vehicle_display'  => $vehicle_display,
            'from_date'        => $from_date,
            'to_date'          => $to_date,
            'date_range_label' => $date_range_label,
            'status_filter'    => $status_filter,
            'total_coupons'    => count($transactions),
            'total_amount'     => $total_amount,
            'amount_in_words'  => convert_number_to_rupees_words($total_amount),
            'transactions'     => $transactions,
            'category_summary' => $ordered_categories
        ];
    }

    /**
     * Retrieve list of generated bills with filtering for registry & audit trail
     */
    function get_generated_monthly_bills($connection, $filters = []) {
        auto_migrate_monthly_bill_tables($connection);

        $where = ["(b.deleted_at IS NULL OR b.deleted_at = '0000-00-00 00:00:00')"];

        if (!empty($filters['bill_no'])) {
            $b_safe = mysqli_real_escape_string($connection, trim($filters['bill_no']));
            $where[] = "b.bill_no LIKE '%$b_safe%'";
        }

        if (!empty($filters['customer_id'])) {
            $cid = intval($filters['customer_id']);
            $where[] = "b.customer_id = '$cid'";
        }

        if (!empty($filters['from_date'])) {
            $fd = mysqli_real_escape_string($connection, trim($filters['from_date']));
            $where[] = "b.to_date >= '$fd'";
        }

        if (!empty($filters['to_date'])) {
            $td = mysqli_real_escape_string($connection, trim($filters['to_date']));
            $where[] = "b.from_date <= '$td'";
        }

        if (!empty($filters['search'])) {
            $s_safe = mysqli_real_escape_string($connection, trim($filters['search']));
            $where[] = "(b.bill_no LIKE '%$s_safe%' OR c.name LIKE '%$s_safe%' OR b.attention_to LIKE '%$s_safe%')";
        }

        $sql = "SELECT 
                    b.*,
                    c.name AS customer_name,
                    c.phone AS customer_phone,
                    c.address AS customer_address,
                    u.username AS generated_by_user
                FROM tbl_customer_monthly_bills b
                JOIN tbl_customers c ON b.customer_id = c.id
                LEFT JOIN tbl_accounts u ON b.generated_by = u.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY CAST(b.bill_no AS UNSIGNED) DESC, b.id DESC";

        $res = mysqli_query($connection, $sql);
        $bills = [];
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $bills[] = $row;
            }
        }
        return $bills;
    }

    /**
     * Get a specific bill by bill number
     */
    function get_monthly_bill_by_number($connection, $bill_no) {
        auto_migrate_monthly_bill_tables($connection);
        $b_safe = mysqli_real_escape_string($connection, trim($bill_no));
        $sql = "SELECT b.*, c.name AS customer_name, c.attention_line 
                FROM tbl_customer_monthly_bills b
                JOIN tbl_customers c ON b.customer_id = c.id
                WHERE b.bill_no = '$b_safe' 
                  AND (b.deleted_at IS NULL OR b.deleted_at = '0000-00-00 00:00:00')
                LIMIT 1";
        $res = mysqli_query($connection, $sql);
        if ($res && $row = mysqli_fetch_assoc($res)) {
            return $row;
        }
        return null;
    }

    /**
     * Soft delete a monthly bill record
     */
    function soft_delete_monthly_bill($connection, $bill_id) {
        auto_migrate_monthly_bill_tables($connection);
        $id = intval($bill_id);
        if ($id <= 0) return false;
        $res = mysqli_query($connection, "UPDATE tbl_customer_monthly_bills SET deleted_at = NOW() WHERE id = '$id'");
        return $res ? true : false;
    }
}
