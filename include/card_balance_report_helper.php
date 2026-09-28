<?php
/**
 * PPMS Card Sales Recovery & Outstanding Balance Report Helper Engine
 *
 * Provides executive financial aggregations for Card Sales settlements:
 * - Gross sales amount swiped on POS machines
 * - Net receivable expected from bank (after MDR fee)
 * - Amount received / deposited into station bank account ("How much get")
 * - Outstanding balance pending from bank ("How much balance")
 * - Filtered by Date Range, Card Machine, and Shift (Both / Morning / Evening).
 */

require_once __DIR__ . '/card_report_helper.php';

if (!function_exists('ensure_card_settlements_schema')) {
    /**
     * Self-healing migration ensuring required columns exist on tbl_card_sale_settlements.
     *
     * @param mysqli $connection
     */
    function ensure_card_settlements_schema($connection) {
        $chk_col = mysqli_query($connection, "SHOW COLUMNS FROM tbl_card_sale_settlements LIKE 'paid_amount'");
        if ($chk_col && mysqli_num_rows($chk_col) == 0) {
            mysqli_query($connection, "ALTER TABLE tbl_card_sale_settlements ADD COLUMN paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER net_amount");
            mysqli_query($connection, "ALTER TABLE tbl_card_sale_settlements ADD COLUMN payment_status ENUM('Unpaid', 'Partial', 'Paid') NOT NULL DEFAULT 'Unpaid' AFTER paid_amount, ADD INDEX idx_payment_status (payment_status)");
        }
    }
}

if (!function_exists('get_card_balance_report_data')) {
    /**
     * Computes consolidated Card Sales Recovery & Outstanding Balance report data.
     *
     * @param mysqli $connection
     * @param string $from_date (YYYY-MM-DD)
     * @param string $to_date (YYYY-MM-DD)
     * @param int $card_machine_id (0 = All Machines)
     * @param int $shift_id (0 = All Shifts / Both Morning & Evening)
     * @param string $status ('all', 'outstanding', 'paid', 'unpaid', 'partial')
     * @return array
     */
    function get_card_balance_report_data($connection, $from_date, $to_date, $card_machine_id = 0, $shift_id = 0, $status = 'all') {
        ensure_card_settlements_schema($connection);

        $from_date = trim($from_date ?: date('Y-m-01'));
        $to_date   = trim($to_date ?: date('Y-m-d'));
        $from_safe = mysqli_real_escape_string($connection, $from_date);
        $to_safe   = mysqli_real_escape_string($connection, $to_date);

        $card_machine_id = intval($card_machine_id);
        $shift_id        = intval($shift_id);
        $status          = strtolower(trim($status ?: 'all'));

        // Build WHERE clauses
        $where_clauses = [
            "s.settlement_date >= '$from_safe'",
            "s.settlement_date <= '$to_safe'",
            "(s.deleted_at IS NULL OR s.deleted_at = '0000-00-00 00:00:00')"
        ];

        if ($card_machine_id > 0) {
            $where_clauses[] = "s.card_machine_id = '$card_machine_id'";
        }

        if ($shift_id > 0) {
            $where_clauses[] = "s.shift_id = '$shift_id'";
        }

        if ($status === 'outstanding') {
            $where_clauses[] = "s.payment_status != 'Paid' AND (s.net_amount - s.paid_amount) > 0.00";
        } elseif ($status === 'paid') {
            $where_clauses[] = "s.payment_status = 'Paid'";
        } elseif ($status === 'partial') {
            $where_clauses[] = "s.payment_status = 'Partial'";
        } elseif ($status === 'unpaid') {
            $where_clauses[] = "s.payment_status = 'Unpaid'";
        }

        $where_sql = implode(' AND ', $where_clauses);

        // 1. Overall Executive Summary (Top KPI Cards)
        $sql_overall = "
            SELECT 
                COUNT(s.id) AS total_batches,
                COALESCE(SUM(s.no_of_cards), 0) AS total_swipes,
                COALESCE(SUM(s.amount), 0) AS total_gross_amount,
                COALESCE(SUM(s.service_charges), 0) AS total_service_charges,
                COALESCE(SUM(s.net_amount), 0) AS total_net_expected,
                COALESCE(SUM(s.paid_amount), 0) AS total_paid_amount,
                COALESCE(SUM(s.net_amount - s.paid_amount), 0) AS total_balance_due
            FROM tbl_card_sale_settlements s
            WHERE $where_sql
        ";
        $res_overall = mysqli_query($connection, $sql_overall);
        $overall = [
            'total_batches'         => 0,
            'total_swipes'          => 0,
            'total_gross_amount'    => 0.00,
            'total_service_charges' => 0.00,
            'total_net_expected'    => 0.00,
            'total_paid_amount'     => 0.00,
            'total_balance_due'     => 0.00,
            'recovery_rate_pct'     => 0.00
        ];

        if ($res_overall && ($r = mysqli_fetch_assoc($res_overall))) {
            $overall['total_batches']         = intval($r['total_batches']);
            $overall['total_swipes']          = intval($r['total_swipes']);
            $overall['total_gross_amount']    = round(floatval($r['total_gross_amount']), 2);
            $overall['total_service_charges'] = round(floatval($r['total_service_charges']), 2);
            $overall['total_net_expected']    = round(floatval($r['total_net_expected']), 2);
            $overall['total_paid_amount']     = round(floatval($r['total_paid_amount']), 2);
            $overall['total_balance_due']     = round(floatval($r['total_balance_due']), 2);

            if ($overall['total_net_expected'] > 0) {
                $overall['recovery_rate_pct'] = round(($overall['total_paid_amount'] / $overall['total_net_expected']) * 100, 2);
            }
        }

        // 2. POS Card Machine Recovery & Outstanding Summary
        $sql_machines = "
            SELECT 
                s.card_machine_id,
                COALESCE(cm.name, CONCAT('Machine #', s.card_machine_id)) AS machine_name,
                COALESCE(cm.charges_percentage, 0.0000) AS charges_percentage,
                COUNT(s.id) AS total_batches,
                COALESCE(SUM(s.no_of_cards), 0) AS total_swipes,
                COALESCE(SUM(s.amount), 0) AS total_gross_amount,
                COALESCE(SUM(s.service_charges), 0) AS total_service_charges,
                COALESCE(SUM(s.net_amount), 0) AS total_net_expected,
                COALESCE(SUM(s.paid_amount), 0) AS total_paid_amount,
                COALESCE(SUM(s.net_amount - s.paid_amount), 0) AS total_balance_due
            FROM tbl_card_sale_settlements s
            LEFT JOIN tbl_card_machines cm ON s.card_machine_id = cm.id
            WHERE $where_sql
            GROUP BY s.card_machine_id
            ORDER BY total_gross_amount DESC, machine_name ASC
        ";
        $res_machines = mysqli_query($connection, $sql_machines);
        $machine_matrix = [];

        if ($res_machines) {
            while ($rm = mysqli_fetch_assoc($res_machines)) {
                $expected = round(floatval($rm['total_net_expected']), 2);
                $paid     = round(floatval($rm['total_paid_amount']), 2);
                $due      = round(floatval($rm['total_balance_due']), 2);
                $rec_pct  = ($expected > 0) ? round(($paid / $expected) * 100, 2) : 0.00;

                $machine_status = 'Unpaid';
                if ($due <= 0.00 && $expected > 0) {
                    $machine_status = 'Paid';
                } elseif ($paid > 0.00 && $due > 0.00) {
                    $machine_status = 'Partial';
                }

                $machine_matrix[] = [
                    'card_machine_id'        => intval($rm['card_machine_id']),
                    'machine_name'           => $rm['machine_name'],
                    'charges_percentage'     => round(floatval($rm['charges_percentage']), 4),
                    'total_batches'          => intval($rm['total_batches']),
                    'total_swipes'           => intval($rm['total_swipes']),
                    'total_gross_amount'     => round(floatval($rm['total_gross_amount']), 2),
                    'total_service_charges'  => round(floatval($rm['total_service_charges']), 2),
                    'total_net_expected'     => $expected,
                    'total_paid_amount'      => $paid,
                    'total_balance_due'      => $due,
                    'recovery_rate_pct'      => $rec_pct,
                    'status'                 => $machine_status
                ];
            }
        }

        // 3. Daily Shift Settlement & Balance Rollup
        $sql_settlements = "
            SELECT 
                s.id,
                s.settlement_date,
                s.shift_id,
                COALESCE(sh.name, CONCAT('Shift #', s.shift_id)) AS shift_name,
                s.card_machine_id,
                COALESCE(cm.name, CONCAT('Machine #', s.card_machine_id)) AS machine_name,
                s.batch_no,
                s.no_of_cards,
                s.amount AS gross_amount,
                s.service_charges,
                s.net_amount AS net_expected,
                s.paid_amount,
                (s.net_amount - s.paid_amount) AS balance_due,
                s.payment_status
            FROM tbl_card_sale_settlements s
            LEFT JOIN tbl_shifts sh ON s.shift_id = sh.id
            LEFT JOIN tbl_card_machines cm ON s.card_machine_id = cm.id
            WHERE $where_sql
            ORDER BY s.settlement_date DESC, s.shift_id ASC, s.id DESC
        ";
        $res_settlements = mysqli_query($connection, $sql_settlements);
        $settlement_rows = [];

        if ($res_settlements) {
            while ($rs = mysqli_fetch_assoc($res_settlements)) {
                $exp = round(floatval($rs['net_expected']), 2);
                $pd  = round(floatval($rs['paid_amount']), 2);
                $bal = round(floatval($rs['balance_due']), 2);

                $settlement_rows[] = [
                    'id'                => intval($rs['id']),
                    'settlement_date'   => $rs['settlement_date'],
                    'shift_id'          => intval($rs['shift_id']),
                    'shift_name'        => $rs['shift_name'],
                    'card_machine_id'   => intval($rs['card_machine_id']),
                    'machine_name'      => $rs['machine_name'],
                    'batch_no'          => $rs['batch_no'],
                    'no_of_cards'       => intval($rs['no_of_cards']),
                    'gross_amount'      => round(floatval($rs['gross_amount']), 2),
                    'service_charges'   => round(floatval($rs['service_charges']), 2),
                    'net_expected'      => $exp,
                    'paid_amount'       => $pd,
                    'balance_due'       => $bal,
                    'payment_status'    => $rs['payment_status']
                ];
            }
        }

        return [
            'from_date'        => $from_date,
            'to_date'          => $to_date,
            'card_machine_id'  => $card_machine_id,
            'shift_id'         => $shift_id,
            'status'           => $status,
            'overall'          => $overall,
            'machine_matrix'   => $machine_matrix,
            'settlement_rows'  => $settlement_rows
        ];
    }
}
