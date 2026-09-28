<?php
/**
 * PPMS Card Machine Report Helper Functions & Business Logic Engine
 *
 * Provides executive aggregations for POS Card Machine settlements
 * across date ranges, card machines, and station shifts.
 * Focuses on high-level settlement totals (Sales, Swipes, Gross, Bank Fee, Net Revenue)
 * without individual transaction details.
 */

if (!function_exists('get_active_card_machines')) {
    /**
     * Fetches all active POS card machines.
     *
     * @param mysqli $connection
     * @return array
     */
    function get_active_card_machines($connection) {
        $machines = [];
        $sql = "SELECT id, name, charges_percentage, revenue_charge, contact_person_name 
                FROM tbl_card_machines 
                WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') 
                ORDER BY name ASC";
        $res = mysqli_query($connection, $sql);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $machines[] = $row;
            }
        }
        return $machines;
    }
}

if (!function_exists('get_active_shifts_list')) {
    /**
     * Fetches all active station shifts.
     *
     * @param mysqli $connection
     * @return array
     */
    function get_active_shifts_list($connection) {
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

if (!function_exists('get_card_report_data')) {
    /**
     * Computes consolidated Card Machine report data.
     *
     * @param mysqli $connection
     * @param string $from_date (YYYY-MM-DD)
     * @param string $to_date (YYYY-MM-DD)
     * @param int $card_machine_id (0 = All Machines)
     * @param int $shift_id (0 = All Shifts / Both Morning & Evening)
     * @return array
     */
    function get_card_report_data($connection, $from_date, $to_date, $card_machine_id = 0, $shift_id = 0) {
        $from_date = trim($from_date ?: date('Y-m-d'));
        $to_date   = trim($to_date ?: date('Y-m-d'));
        $from_safe = mysqli_real_escape_string($connection, $from_date);
        $to_safe   = mysqli_real_escape_string($connection, $to_date);

        $card_machine_id = intval($card_machine_id);
        $shift_id        = intval($shift_id);

        // Build filtering criteria
        $where_clauses = [
            "mrcs.sale_date >= '$from_safe'",
            "mrcs.sale_date <= '$to_safe'",
            "(mrcs.deleted_at IS NULL OR mrcs.deleted_at = '0000-00-00 00:00:00')"
        ];

        if ($card_machine_id > 0) {
            $where_clauses[] = "mrcs.card_machine_id = '$card_machine_id'";
        }

        if ($shift_id > 0) {
            $where_clauses[] = "mrcs.shift_id = '$shift_id'";
        }

        $where_sql = implode(' AND ', $where_clauses);

        // 1. Overall Executive Summary (Top KPI Cards)
        $sql_overall = "
            SELECT 
                COUNT(mrcs.id) AS total_swipes,
                COALESCE(SUM(mrcs.quantity), 0) AS total_volume,
                COALESCE(SUM(mrcs.amount), 0) AS total_gross_amount,
                COALESCE(SUM(mrcs.service_charges), 0) AS total_service_charges,
                COALESCE(SUM(CASE WHEN mrcs.net_amount > 0 THEN mrcs.net_amount ELSE (mrcs.amount - mrcs.service_charges) END), 0) AS total_net_revenue,
                COALESCE(SUM(mrcs.difference), 0) AS total_difference
            FROM tbl_meter_reading_card_sales mrcs
            WHERE $where_sql
        ";
        $res_overall = mysqli_query($connection, $sql_overall);
        $overall = [
            'total_swipes'          => 0,
            'total_volume'          => 0.00,
            'total_gross_amount'    => 0.00,
            'total_service_charges' => 0.00,
            'total_net_revenue'     => 0.00,
            'total_difference'      => 0.00,
            'effective_fee_percentage'    => 0.00,
            'effective_payout_percentage' => 0.00
        ];

        if ($res_overall && ($r = mysqli_fetch_assoc($res_overall))) {
            $overall['total_swipes']          = intval($r['total_swipes']);
            $overall['total_volume']          = round(floatval($r['total_volume']), 2);
            $overall['total_gross_amount']    = round(floatval($r['total_gross_amount']), 2);
            $overall['total_service_charges'] = round(floatval($r['total_service_charges']), 2);
            $overall['total_net_revenue']     = round(floatval($r['total_net_revenue']), 2);
            $overall['total_difference']      = round(floatval($r['total_difference']), 2);

            if ($overall['total_gross_amount'] > 0) {
                $overall['effective_fee_percentage']    = round(($overall['total_service_charges'] / $overall['total_gross_amount']) * 100, 2);
                $overall['effective_payout_percentage'] = round(($overall['total_net_revenue'] / $overall['total_gross_amount']) * 100, 2);
            }
        }

        // 2. POS Card Machine Summary Matrix
        $sql_machines = "
            SELECT 
                mrcs.card_machine_id,
                COALESCE(cm.name, CONCAT('Machine #', mrcs.card_machine_id)) AS machine_name,
                COALESCE(cm.charges_percentage, 0.0000) AS charges_percentage,
                COALESCE(cm.revenue_charge, 0.0000) AS revenue_charge,
                COUNT(mrcs.id) AS total_swipes,
                COALESCE(SUM(mrcs.quantity), 0) AS total_volume,
                COALESCE(SUM(mrcs.amount), 0) AS total_gross_amount,
                COALESCE(SUM(mrcs.service_charges), 0) AS total_service_charges,
                COALESCE(SUM(CASE WHEN mrcs.net_amount > 0 THEN mrcs.net_amount ELSE (mrcs.amount - mrcs.service_charges) END), 0) AS total_net_revenue,
                COALESCE(SUM(mrcs.difference), 0) AS total_difference
            FROM tbl_meter_reading_card_sales mrcs
            LEFT JOIN tbl_card_machines cm ON mrcs.card_machine_id = cm.id
            WHERE $where_sql
            GROUP BY mrcs.card_machine_id
            ORDER BY total_gross_amount DESC, machine_name ASC
        ";
        $res_machines = mysqli_query($connection, $sql_machines);
        $machine_matrix = [];

        if ($res_machines) {
            while ($rm = mysqli_fetch_assoc($res_machines)) {
                $gross = round(floatval($rm['total_gross_amount']), 2);
                $net   = round(floatval($rm['total_net_revenue']), 2);
                $fee   = round(floatval($rm['total_service_charges']), 2);
                $payout_pct = ($gross > 0) ? round(($net / $gross) * 100, 2) : 0.00;

                $machine_matrix[] = [
                    'card_machine_id'        => intval($rm['card_machine_id']),
                    'machine_name'           => $rm['machine_name'],
                    'charges_percentage'     => round(floatval($rm['charges_percentage']), 4),
                    'revenue_charge'         => round(floatval($rm['revenue_charge']), 4),
                    'total_swipes'           => intval($rm['total_swipes']),
                    'total_volume'           => round(floatval($rm['total_volume']), 2),
                    'total_gross_amount'     => $gross,
                    'total_service_charges'  => $fee,
                    'total_net_revenue'      => $net,
                    'total_difference'       => round(floatval($rm['total_difference']), 2),
                    'effective_payout_pct'   => $payout_pct
                ];
            }
        }

        // 3. Shift Settlement Summary (Date + Shift + Machine rollup - NO transaction details)
        $sql_shifts = "
            SELECT 
                mrcs.sale_date,
                mrcs.shift_id,
                COALESCE(sh.name, CONCAT('Shift #', mrcs.shift_id)) AS shift_name,
                mrcs.card_machine_id,
                COALESCE(cm.name, CONCAT('Machine #', mrcs.card_machine_id)) AS machine_name,
                COUNT(mrcs.id) AS total_swipes,
                COALESCE(SUM(mrcs.quantity), 0) AS total_volume,
                COALESCE(SUM(mrcs.amount), 0) AS total_gross_amount,
                COALESCE(SUM(mrcs.service_charges), 0) AS total_service_charges,
                COALESCE(SUM(CASE WHEN mrcs.net_amount > 0 THEN mrcs.net_amount ELSE (mrcs.amount - mrcs.service_charges) END), 0) AS total_net_revenue
            FROM tbl_meter_reading_card_sales mrcs
            LEFT JOIN tbl_shifts sh ON mrcs.shift_id = sh.id
            LEFT JOIN tbl_card_machines cm ON mrcs.card_machine_id = cm.id
            WHERE $where_sql
            GROUP BY mrcs.sale_date, mrcs.shift_id, mrcs.card_machine_id
            ORDER BY mrcs.sale_date DESC, mrcs.shift_id ASC, machine_name ASC
        ";
        $res_shifts = mysqli_query($connection, $sql_shifts);
        $shift_summary = [];

        if ($res_shifts) {
            while ($rs = mysqli_fetch_assoc($res_shifts)) {
                $shift_summary[] = [
                    'sale_date'             => $rs['sale_date'],
                    'shift_id'              => intval($rs['shift_id']),
                    'shift_name'            => $rs['shift_name'],
                    'card_machine_id'       => intval($rs['card_machine_id']),
                    'machine_name'          => $rs['machine_name'],
                    'total_swipes'          => intval($rs['total_swipes']),
                    'total_volume'          => round(floatval($rs['total_volume']), 2),
                    'total_gross_amount'    => round(floatval($rs['total_gross_amount']), 2),
                    'total_service_charges' => round(floatval($rs['total_service_charges']), 2),
                    'total_net_revenue'     => round(floatval($rs['total_net_revenue']), 2)
                ];
            }
        }

        // Return unified payload
        return [
            'from_date'        => $from_date,
            'to_date'          => $to_date,
            'card_machine_id'  => $card_machine_id,
            'shift_id'         => $shift_id,
            'overall'          => $overall,
            'machine_matrix'   => $machine_matrix,
            'shift_summary'    => $shift_summary
        ];
    }
}
