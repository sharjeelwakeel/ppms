<?php
/**
 * Staff Salary Payment Helper Service
 * PPMS (Petrol Pump Management System)
 * 
 * Provides centralized logic for attendance-based cash salary calculation,
 * payment recording, voucher numbering, duplicate prevention, and history auditing.
 */

if (!defined('SALARY_PAYMENT_HELPER_LOADED')) {
    define('SALARY_PAYMENT_HELPER_LOADED', true);

    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/settings_helper.php';

    /**
     * Auto-migrate tbl_staff_salary_payments if not exists
     */
    function auto_migrate_salary_payment_tables($connection) {
        if (!$connection) return;

        $check_tbl = mysqli_query($connection, "SHOW TABLES LIKE 'tbl_staff_salary_payments'");
        if ($check_tbl && mysqli_num_rows($check_tbl) == 0) {
            $create_sql = "CREATE TABLE IF NOT EXISTS `tbl_staff_salary_payments` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `voucher_no` VARCHAR(64) NOT NULL,
              `staff_id` INT(11) NOT NULL,
              `salary_month` INT(2) NOT NULL,
              `salary_year` INT(4) NOT NULL,
              `days_worked` INT(11) NOT NULL DEFAULT 0,
              `daily_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
              `paid_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              `payment_date` DATE NOT NULL,
              `payment_mode` VARCHAR(32) NOT NULL DEFAULT 'Cash',
              `payment_status` ENUM('Paid') NOT NULL DEFAULT 'Paid',
              `remarks` VARCHAR(255) DEFAULT NULL,
              `paid_by` INT(11) DEFAULT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `deleted_at` DATETIME DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_staff_period` (`staff_id`, `salary_year`, `salary_month`),
              KEY `idx_voucher` (`voucher_no`),
              KEY `idx_payment_date` (`payment_date`),
              KEY `idx_deleted_at` (`deleted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            mysqli_query($connection, $create_sql);
        }

        // Ensure tbl_staff has weekly_off column
        $check_woff = mysqli_query($connection, "SHOW COLUMNS FROM `tbl_staff` LIKE 'weekly_off'");
        if ($check_woff && mysqli_num_rows($check_woff) == 0) {
            mysqli_query($connection, "ALTER TABLE `tbl_staff` ADD COLUMN `weekly_off` VARCHAR(16) NOT NULL DEFAULT 'Friday' AFTER `shift_id`");
        }

        // Ensure tbl_staff_attendance supports 'Late' and 'Holiday' status
        $check_att = mysqli_query($connection, "SHOW COLUMNS FROM `tbl_staff_attendance` LIKE 'status'");
        if ($check_att && $r_att = mysqli_fetch_assoc($check_att)) {
            if (isset($r_att['Type']) && (strpos($r_att['Type'], "'Late'") === false || strpos($r_att['Type'], "'Holiday'") === false)) {
                mysqli_query($connection, "ALTER TABLE `tbl_staff_attendance` MODIFY COLUMN `status` ENUM('Present','Absent','Late','Leave','Holiday') NOT NULL DEFAULT 'Present'");
            }
        }

        // Ensure tbl_settings has global_monthly_paid_leaves column
        $check_gpl = mysqli_query($connection, "SHOW TABLES LIKE 'tbl_settings'");
        if ($check_gpl && mysqli_num_rows($check_gpl) > 0) {
            $col_gpl = mysqli_query($connection, "SHOW COLUMNS FROM `tbl_settings` LIKE 'global_monthly_paid_leaves'");
            if ($col_gpl && mysqli_num_rows($col_gpl) == 0) {
                mysqli_query($connection, "ALTER TABLE `tbl_settings` ADD COLUMN `global_monthly_paid_leaves` INT(11) NOT NULL DEFAULT 2");
            }
        }
    }

    /**
     * Get global monthly paid leaves allowed for all staff (Default: 2)
     */
    function get_global_paid_leaves($connection) {
        auto_migrate_salary_payment_tables($connection);
        $q = mysqli_query($connection, "SELECT global_monthly_paid_leaves FROM tbl_settings WHERE id = 1 LIMIT 1");
        if ($q && ($row = mysqli_fetch_assoc($q))) {
            return intval($row['global_monthly_paid_leaves'] ?? 2);
        }
        return 2;
    }

    /**
     * Set global monthly paid leaves allowed for all staff
     */
    function set_global_paid_leaves($connection, $leaves) {
        auto_migrate_salary_payment_tables($connection);
        $leaves = max(0, intval($leaves));
        $res = mysqli_query($connection, "UPDATE tbl_settings SET global_monthly_paid_leaves = '$leaves' WHERE id = 1");
        return ($res !== false);
    }

    /**
     * Convert currency amount to uppercase English words for salary vouchers
     */
    function convert_salary_amount_to_words($amount) {
        $amount = round(floatval($amount), 2);
        if ($amount <= 0) {
            return "( RUPEES ZERO ONLY )";
        }

        $units = ["", "ONE", "TWO", "THREE", "FOUR", "FIVE", "SIX", "SEVEN", "EIGHT", "NINE", "TEN", 
                  "ELEVEN", "TWELVE", "THIRTEEN", "FOURTEEN", "FIFTEEN", "SIXTEEN", "SEVENTEEN", "EIGHTEEN", "NINETEEN"];
        $tens  = ["", "", "TWENTY", "THIRTY", "FORTY", "FIFTY", "SIXTY", "SEVENTY", "EIGHTY", "NINETY"];

        $convert_triplet = function($num) use ($units, $tens) {
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

        if ($rupees >= 1000000000) {
            $billion = intval($rupees / 1000000000);
            $words .= $convert_triplet($billion) . " BILLION ";
            $rupees %= 1000000000;
        }

        if ($rupees >= 1000000) {
            $million = intval($rupees / 1000000);
            $words .= $convert_triplet($million) . " MILLION ";
            $rupees %= 1000000;
        }

        if ($rupees >= 1000) {
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
     * Fetch calculated salaries for all staff (or specific staff) for a month/year
     * along with their payment history status.
     * 
     * @param mysqli $connection
     * @param int $month
     * @param int $year
     * @param int $staff_id Optional filter
     * @return array
     */
    function get_staff_monthly_salary_records($connection, $month, $year, $staff_id = 0) {
        auto_migrate_salary_payment_tables($connection);

        $month = intval($month);
        $year  = intval($year);
        $month_str = sprintf('%04d-%02d', $year, $month);

        $where_staff = "";
        if ($staff_id > 0) {
            $s_id = intval($staff_id);
            $where_staff = "AND s.id = '$s_id'";
        }

        $global_allowed_leaves = get_global_paid_leaves($connection);

        $sql = "SELECT 
                    s.id AS staff_id,
                    s.first_name,
                    s.last_name,
                    s.phone,
                    s.salary AS daily_rate,
                    s.weekly_off,
                    r.name AS role_name,
                    COALESCE(SUM(CASE WHEN sa.status = 'Present' THEN 1 ELSE 0 END), 0) AS count_present,
                    COALESCE(SUM(CASE WHEN sa.status = 'Late' THEN 1 ELSE 0 END), 0) AS count_late,
                    COALESCE(SUM(CASE WHEN sa.status = 'Holiday' THEN 1 ELSE 0 END), 0) AS count_holiday,
                    COALESCE(SUM(CASE WHEN sa.status = 'Leave' THEN 1 ELSE 0 END), 0) AS count_leave,
                    COALESCE(SUM(CASE WHEN sa.status = 'Absent' THEN 1 ELSE 0 END), 0) AS count_absent,
                    pay.id AS payment_id,
                    pay.voucher_no,
                    pay.payment_date,
                    pay.payment_mode,
                    pay.paid_amount,
                    pay.remarks AS payment_remarks,
                    pay.created_at AS payment_created_at,
                    u.username AS paid_by_user
                FROM tbl_staff s
                LEFT JOIN tbl_staff_roles r ON s.role_id = r.id
                LEFT JOIN tbl_staff_attendance sa ON s.id = sa.staff_id AND sa.date LIKE '$month_str-%'
                LEFT JOIN tbl_staff_salary_payments pay ON (
                    s.id = pay.staff_id 
                    AND pay.salary_month = '$month' 
                    AND pay.salary_year = '$year'
                    AND (pay.deleted_at IS NULL OR pay.deleted_at = '0000-00-00 00:00:00')
                )
                LEFT JOIN tbl_accounts u ON pay.paid_by = u.id
                WHERE (s.deleted_at IS NULL OR s.deleted_at = '0000-00-00 00:00:00')
                $where_staff
                GROUP BY s.id
                ORDER BY s.id DESC";

        $res = mysqli_query($connection, $sql);
        $records = [];

        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $daily_rate     = floatval($row['daily_rate']);
                $count_present  = intval($row['count_present']);
                $count_late     = intval($row['count_late']);
                $count_holiday  = intval($row['count_holiday']);
                $count_leave    = intval($row['count_leave']);
                $count_absent   = intval($row['count_absent']);

                // Option A: Paid leaves same for all staff (global policy)
                $paid_leaves    = min($count_leave, $global_allowed_leaves);
                $unpaid_leaves  = max(0, $count_leave - $paid_leaves);

                // Total paid days = Present + Late + Weekly Holiday + Paid Leaves
                $days_worked    = $count_present + $count_late + $count_holiday + $paid_leaves;
                $unpaid_days    = $count_absent + $unpaid_leaves;

                // Calculated Salary based on days worked
                $calculated_salary = round($days_worked * $daily_rate, 2);

                $is_paid = (!empty($row['payment_id']));
                $paid_amount = $is_paid ? floatval($row['paid_amount']) : 0.0;

                $records[] = [
                    'staff_id'          => intval($row['staff_id']),
                    'name'              => trim($row['first_name'] . ' ' . $row['last_name']),
                    'phone'             => $row['phone'] ?? '',
                    'role_name'         => $row['role_name'] ?? 'Staff',
                    'weekly_off'        => !empty($row['weekly_off']) ? $row['weekly_off'] : 'Friday',
                    'daily_rate'        => $daily_rate,
                    'count_present'     => $count_present,
                    'count_late'        => $count_late,
                    'count_holiday'     => $count_holiday,
                    'count_leave'       => $count_leave,
                    'count_absent'      => $count_absent,
                    'allowed_leaves'    => $global_allowed_leaves,
                    'paid_leaves'       => $paid_leaves,
                    'unpaid_leaves'     => $unpaid_leaves,
                    'days_worked'       => $days_worked,
                    'unpaid_days'       => $unpaid_days,
                    'calculated_salary' => $calculated_salary,
                    'is_paid'           => $is_paid,
                    'payment_id'        => intval($row['payment_id'] ?? 0),
                    'voucher_no'        => $row['voucher_no'] ?? '',
                    'payment_date'      => $row['payment_date'] ?? '',
                    'payment_mode'      => $row['payment_mode'] ?? 'Cash',
                    'paid_amount'       => $paid_amount,
                    'payment_remarks'   => $row['payment_remarks'] ?? '',
                    'paid_by_user'      => $row['paid_by_user'] ?? '',
                    'payment_status'    => $is_paid ? 'Paid' : 'Unpaid'
                ];
            }
        }

        return $records;
    }

    /**
     * Process and record a cash salary payment for a staff member for a specific month
     * 
     * @param mysqli $connection
     * @param int $staff_id
     * @param int $month
     * @param int $year
     * @param string $payment_date
     * @param string $remarks
     * @param int $paid_by_user_id
     * @return array
     */
    function process_cash_salary_payment($connection, $staff_id, $month, $year, $payment_date, $remarks = '', $paid_by_user_id = 0) {
        auto_migrate_salary_payment_tables($connection);

        $staff_id = intval($staff_id);
        $month    = intval($month);
        $year     = intval($year);
        $paid_by  = intval($paid_by_user_id);
        $remarks_safe = mysqli_real_escape_string($connection, trim($remarks));

        if ($staff_id <= 0 || $month < 1 || $month > 12 || $year < 2020) {
            return ['status' => 'error', 'message' => 'Invalid parameters provided for salary disbursement.'];
        }

        if (empty($payment_date) || strtotime($payment_date) === false) {
            $payment_date = date('Y-m-d');
        }
        $payment_date_safe = mysqli_real_escape_string($connection, $payment_date);

        // Atomic transaction to ensure integrity and prevent race conditions
        mysqli_begin_transaction($connection);

        try {
            // 1. Check if already paid for this month/year (Row locking)
            $chk_sql = "SELECT id, voucher_no, paid_amount, payment_date 
                        FROM tbl_staff_salary_payments 
                        WHERE staff_id = '$staff_id' 
                          AND salary_month = '$month' 
                          AND salary_year = '$year' 
                          AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') 
                        LIMIT 1 FOR UPDATE";
            $chk_res = mysqli_query($connection, $chk_sql);
            if ($chk_res && mysqli_num_rows($chk_res) > 0) {
                $existing = mysqli_fetch_assoc($chk_res);
                mysqli_rollback($connection);
                return [
                    'status'  => 'error', 
                    'message' => "Salary for this month has already been disbursed under Voucher #{$existing['voucher_no']} on " . date('d/m/Y', strtotime($existing['payment_date'])) . "."
                ];
            }

            // 2. Fetch staff record & attendance calculation
            $records = get_staff_monthly_salary_records($connection, $month, $year, $staff_id);
            if (empty($records)) {
                mysqli_rollback($connection);
                return ['status' => 'error', 'message' => 'Staff record not found or inactive.'];
            }
            $target = $records[0];

            $days_worked       = intval($target['days_worked']);
            $daily_rate        = floatval($target['daily_rate']);
            $calculated_salary = round(floatval($target['calculated_salary']), 2);

            // 3. Generate sequential Voucher Number (Format: SAL-YYYYMM-XXXX)
            $prefix = sprintf('SAL-%04d%02d-', $year, $month);
            $q_max = mysqli_query($connection, "SELECT voucher_no FROM tbl_staff_salary_payments WHERE voucher_no LIKE '$prefix%' ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $next_seq = 1;
            if ($q_max && $r_max = mysqli_fetch_assoc($q_max)) {
                $last_num = intval(substr($r_max['voucher_no'], strlen($prefix)));
                $next_seq = $last_num + 1;
            }
            $voucher_no = $prefix . sprintf('%04d', $next_seq);
            $voucher_safe = mysqli_real_escape_string($connection, $voucher_no);

            // 4. Insert into tbl_staff_salary_payments
            $insert_sql = "INSERT INTO tbl_staff_salary_payments 
                (voucher_no, staff_id, salary_month, salary_year, days_worked, daily_rate, paid_amount, payment_date, payment_mode, payment_status, remarks, paid_by, created_at)
                VALUES 
                ('$voucher_safe', '$staff_id', '$month', '$year', '$days_worked', '$daily_rate', '$calculated_salary', '$payment_date_safe', 'Cash', 'Paid', '$remarks_safe', '$paid_by', NOW())";

            if (!mysqli_query($connection, $insert_sql)) {
                throw new Exception("Database error inserting salary payment: " . mysqli_error($connection));
            }
            $payment_id = mysqli_insert_id($connection);

            mysqli_commit($connection);

            return [
                'status'            => 'success',
                'message'           => "Cash salary of Rs. " . number_format($calculated_salary, 2) . " paid successfully to {$target['name']} under Voucher #$voucher_no.",
                'payment_id'        => $payment_id,
                'voucher_no'        => $voucher_no,
                'staff_name'        => $target['name'],
                'days_worked'       => $days_worked,
                'daily_rate'        => $daily_rate,
                'paid_amount'       => $calculated_salary,
                'payment_date'      => $payment_date,
                'payment_date_fmt'  => date('d/m/Y', strtotime($payment_date))
            ];

        } catch (Exception $e) {
            mysqli_rollback($connection);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Soft delete / revert a salary payment
     */
    function revert_salary_payment($connection, $payment_id) {
        $payment_id = intval($payment_id);
        if ($payment_id <= 0) {
            return ['status' => 'error', 'message' => 'Invalid payment ID.'];
        }

        $res = mysqli_query($connection, "UPDATE tbl_staff_salary_payments SET deleted_at = NOW() WHERE id = '$payment_id' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
        if ($res && mysqli_affected_rows($connection) > 0) {
            return ['status' => 'success', 'message' => 'Salary payment record reverted successfully. Status reset to Unpaid.'];
        }
        return ['status' => 'error', 'message' => 'Payment record not found or already reverted.'];
    }

    /**
     * Retrieve complete salary disbursement history with optional filters
     */
    function get_salary_payment_history($connection, $filter_month = 0, $filter_year = 0, $filter_staff_id = 0) {
        auto_migrate_salary_payment_tables($connection);

        $where = ["(pay.deleted_at IS NULL OR pay.deleted_at = '0000-00-00 00:00:00')"];

        if ($filter_month > 0) {
            $m = intval($filter_month);
            $where[] = "pay.salary_month = '$m'";
        }
        if ($filter_year > 0) {
            $y = intval($filter_year);
            $where[] = "pay.salary_year = '$y'";
        }
        if ($filter_staff_id > 0) {
            $s = intval($filter_staff_id);
            $where[] = "pay.staff_id = '$s'";
        }

        $sql = "SELECT 
                    pay.*,
                    s.first_name,
                    s.last_name,
                    s.phone,
                    r.name AS role_name,
                    u.username AS paid_by_user
                FROM tbl_staff_salary_payments pay
                JOIN tbl_staff s ON pay.staff_id = s.id
                LEFT JOIN tbl_staff_roles r ON s.role_id = r.id
                LEFT JOIN tbl_accounts u ON pay.paid_by = u.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY pay.payment_date DESC, pay.id DESC";

        $res = mysqli_query($connection, $sql);
        $history = [];

        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $history[] = [
                    'id'               => intval($row['id']),
                    'voucher_no'       => $row['voucher_no'],
                    'staff_id'         => intval($row['staff_id']),
                    'staff_name'       => trim($row['first_name'] . ' ' . $row['last_name']),
                    'phone'            => $row['phone'] ?? '',
                    'role_name'        => $row['role_name'] ?? 'Staff',
                    'salary_month'     => intval($row['salary_month']),
                    'salary_year'      => intval($row['salary_year']),
                    'days_worked'      => intval($row['days_worked']),
                    'daily_rate'       => floatval($row['daily_rate']),
                    'paid_amount'      => floatval($row['paid_amount']),
                    'payment_date'     => $row['payment_date'],
                    'payment_date_fmt' => date('d/m/Y', strtotime($row['payment_date'])),
                    'payment_mode'     => $row['payment_mode'],
                    'remarks'          => $row['remarks'] ?? '',
                    'paid_by_user'     => $row['paid_by_user'] ?? 'Admin',
                    'created_at'       => $row['created_at']
                ];
            }
        }

        return $history;
    }
}
