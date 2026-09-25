<?php
/**
 * Daily Nozzle Report Data Aggregator & Business Logic Helper
 *
 * Computes single-date nozzle performance metrics across physical meter readings,
 * cash sales, credit vouchers, card POS transactions, and nozzle expenses.
 */

if (!function_exists('get_daily_nozzle_report_data')) {
    function get_daily_nozzle_report_data($connection, $nozzleId, $reportDate, $shiftId = 0) {
        $nozzleId = intval($nozzleId);
        $shiftId  = intval($shiftId);
        $reportDate = trim($reportDate ?: date('Y-m-d'));
        $date_safe = mysqli_real_escape_string($connection, $reportDate);

        // Fetch selected nozzle info
        $selected_nozzle = null;
        if ($nozzleId > 0) {
            $noz_q = mysqli_query($connection, "
                SELECT n.id, n.name, n.tank_id, n.item_id, n.start_reading,
                       i.name AS item_name, t.tank_name
                FROM tbl_nozzles n
                LEFT JOIN tbl_items i ON n.item_id = i.id
                LEFT JOIN tbl_tanks t ON n.tank_id = t.id
                WHERE n.id = '$nozzleId'
                  AND (n.deleted_at IS NULL OR n.deleted_at = '0000-00-00 00:00:00')
                LIMIT 1
            ");
            if ($noz_q && mysqli_num_rows($noz_q) > 0) {
                $selected_nozzle = mysqli_fetch_assoc($noz_q);
            }
        }

        // Initialize accumulators
        $meter_rows = [];
        $total_meter_litres  = 0.00;
        $total_meter_revenue = 0.00;
        $total_test_litres   = 0.00;
        $min_opening_meter   = null;
        $max_closing_meter   = null;

        $cash_rows = [];
        $total_cash_litres = 0.00;
        $total_cash_amount = 0.00;

        $credit_rows = [];
        $total_credit_issued_litres = 0.00;
        $total_credit_quota_litres  = 0.00;
        $total_credit_amount        = 0.00;

        $card_rows = [];
        $total_card_litres   = 0.00;
        $total_card_amount   = 0.00;
        $total_card_charges  = 0.00;
        $total_card_net      = 0.00;

        $expense_rows = [];
        $total_nozzle_expenses = 0.00;

        if ($nozzleId > 0 && $selected_nozzle) {
            $shift_filter_mr = ($shiftId > 0) ? " AND mr.shift_id = '$shiftId'" : "";
            $shift_filter_cs = ($shiftId > 0) ? " AND cs.shift_id = '$shiftId'" : "";
            $shift_filter_cr = ($shiftId > 0) ? " AND cr.shift_id = '$shiftId'" : "";
            $shift_filter_cd = ($shiftId > 0) ? " AND cd.shift_id = '$shiftId'" : "";

            // 1. Fetch Meter Readings
            $sql_mr = "
                SELECT mrd.*, mr.date, mr.shift_id, sh.name AS shift_name,
                       CONCAT(st.first_name, ' ', st.last_name) AS staff_name
                FROM tbl_meter_reading_details mrd
                JOIN tbl_meter_readings mr ON mrd.meter_reading_id = mr.id
                LEFT JOIN tbl_shifts sh ON mr.shift_id = sh.id
                LEFT JOIN tbl_staff st ON mrd.staff_id = st.id
                WHERE mrd.nozzle_id = '$nozzleId'
                  AND mr.date = '$date_safe'
                  $shift_filter_mr
                  AND (mr.deleted_at IS NULL OR mr.deleted_at = '0000-00-00 00:00:00')
                ORDER BY mr.shift_id ASC, mr.id ASC
            ";
            $res_mr = mysqli_query($connection, $sql_mr);
            if ($res_mr) {
                while ($row = mysqli_fetch_assoc($res_mr)) {
                    $meter_rows[] = $row;
                    $net_qty = floatval($row['net_sale']);
                    $amt     = floatval($row['amount']);
                    $tst     = floatval($row['test_reading']);
                    $last_m  = floatval($row['last_reading']);
                    $curr_m  = floatval($row['current_reading']);

                    $total_meter_litres  += $net_qty;
                    $total_meter_revenue += $amt;
                    $total_test_litres   += $tst;

                    if ($min_opening_meter === null || $last_m < $min_opening_meter) {
                        $min_opening_meter = $last_m;
                    }
                    if ($max_closing_meter === null || $curr_m > $max_closing_meter) {
                        $max_closing_meter = $curr_m;
                    }
                }
            }

            // 2. Fetch Cash Sales
            $sql_cs = "
                SELECT cs.*, sh.name AS shift_name
                FROM tbl_meter_reading_cash_sales cs
                LEFT JOIN tbl_shifts sh ON cs.shift_id = sh.id
                WHERE cs.nozzle_id = '$nozzleId'
                  AND cs.sale_date = '$date_safe'
                  $shift_filter_cs
                  AND (cs.deleted_at IS NULL OR cs.deleted_at = '0000-00-00 00:00:00')
                ORDER BY cs.shift_id ASC, cs.id ASC
            ";
            $res_cs = mysqli_query($connection, $sql_cs);
            if ($res_cs) {
                while ($row = mysqli_fetch_assoc($res_cs)) {
                    $cash_rows[] = $row;
                    $total_cash_litres += floatval($row['quantity']);
                    $total_cash_amount += floatval($row['amount']);
                }
            }

            // 3. Fetch Credit Sales
            $sql_cr = "
                SELECT cr.*, sh.name AS shift_name, c.name AS customer_name
                FROM tbl_meter_reading_credit_sales cr
                LEFT JOIN tbl_shifts sh ON cr.shift_id = sh.id
                LEFT JOIN tbl_customers c ON cr.account_number = c.id
                WHERE cr.nozzle_id = '$nozzleId'
                  AND (cr.sale_date = '$date_safe' 
                       OR (cr.sale_date IS NULL AND cr.slip_date = '$date_safe'))
                  $shift_filter_cr
                  AND (cr.deleted_at IS NULL OR cr.deleted_at = '0000-00-00 00:00:00')
                ORDER BY cr.shift_id ASC, cr.id ASC
            ";
            $res_cr = mysqli_query($connection, $sql_cr);
            if ($res_cr) {
                while ($row = mysqli_fetch_assoc($res_cr)) {
                    $credit_rows[] = $row;
                    $iqty = floatval($row['issue_quantity'] > 0 ? $row['issue_quantity'] : $row['quantity']);
                    $bqty = floatval($row['quantity']);
                    $camt = floatval($row['amount']);

                    $total_credit_issued_litres += $iqty;
                    $total_credit_quota_litres  += $bqty;
                    $total_credit_amount        += $camt;
                }
            }

            // 4. Fetch Card Sales
            $sql_cd = "
                SELECT cd.*, sh.name AS shift_name, cm.name AS machine_name
                FROM tbl_meter_reading_card_sales cd
                LEFT JOIN tbl_shifts sh ON cd.shift_id = sh.id
                LEFT JOIN tbl_card_machines cm ON cd.card_machine_id = cm.id
                WHERE cd.nozzle_id = '$nozzleId'
                  AND cd.sale_date = '$date_safe'
                  $shift_filter_cd
                  AND (cd.deleted_at IS NULL OR cd.deleted_at = '0000-00-00 00:00:00')
                ORDER BY cd.shift_id ASC, cd.id ASC
            ";
            $res_cd = mysqli_query($connection, $sql_cd);
            if ($res_cd) {
                while ($row = mysqli_fetch_assoc($res_cd)) {
                    $card_rows[] = $row;
                    $total_card_litres  += floatval($row['quantity']);
                    $total_card_amount  += floatval($row['amount']);
                    $total_card_charges += floatval($row['service_charges']);
                    $total_card_net     += floatval($row['net_amount'] > 0 ? $row['net_amount'] : $row['amount']);
                }
            }

            // 5. Fetch Expenses
            $sql_exp = "
                SELECT e.*, et.name AS expense_type_name, a.name AS creator_name
                FROM tbl_expenses e
                LEFT JOIN tbl_expense_types et ON e.expense_type_id = et.id
                LEFT JOIN tbl_accounts a ON e.created_by = a.id
                WHERE e.nozzle_id = '$nozzleId'
                  AND e.expense_date = '$date_safe'
                  AND (e.deleted_at IS NULL OR e.deleted_at = '0000-00-00 00:00:00')
                ORDER BY e.id ASC
            ";
            $res_exp = mysqli_query($connection, $sql_exp);
            if ($res_exp) {
                while ($row = mysqli_fetch_assoc($res_exp)) {
                    $expense_rows[] = $row;
                    $total_nozzle_expenses += floatval($row['amount']);
                }
            }
        }

        // Financial and volumetric reconciliations
        $total_settled_litres = $total_cash_litres + $total_credit_issued_litres + $total_card_litres;
        $total_settled_amount = $total_cash_amount + $total_credit_amount + $total_card_amount;

        $volume_variance    = round($total_settled_litres - $total_meter_litres, 2);
        $financial_variance = round($total_settled_amount - $total_meter_revenue, 2);
        $net_nozzle_yield   = round($total_meter_revenue - $total_nozzle_expenses, 2);

        return [
            'is_station_summary'        => false,
            'selected_nozzle'           => $selected_nozzle,
            'report_date'               => $reportDate,
            'shift_id'                  => $shiftId,
            'meter_rows'                => $meter_rows,
            'total_meter_litres'        => $total_meter_litres,
            'total_meter_revenue'       => $total_meter_revenue,
            'total_test_litres'         => $total_test_litres,
            'min_opening_meter'         => $min_opening_meter,
            'max_closing_meter'         => $max_closing_meter,
            'cash_rows'                 => $cash_rows,
            'total_cash_litres'         => $total_cash_litres,
            'total_cash_amount'         => $total_cash_amount,
            'credit_rows'               => $credit_rows,
            'total_credit_issued_litres'=> $total_credit_issued_litres,
            'total_credit_quota_litres' => $total_credit_quota_litres,
            'total_credit_amount'       => $total_credit_amount,
            'card_rows'                 => $card_rows,
            'total_card_litres'         => $total_card_litres,
            'total_card_amount'         => $total_card_amount,
            'total_card_charges'        => $total_card_charges,
            'total_card_net'            => $total_card_net,
            'expense_rows'              => $expense_rows,
            'total_nozzle_expenses'     => $total_nozzle_expenses,
            'total_settled_litres'      => $total_settled_litres,
            'total_settled_amount'      => $total_settled_amount,
            'volume_variance'           => $volume_variance,
            'financial_variance'        => $financial_variance,
            'net_nozzle_yield'          => $net_nozzle_yield,
        ];
    }
}

if (!function_exists('get_daily_station_report_data')) {
    function get_daily_station_report_data($connection, $reportDate, $shiftId = 0) {
        $shiftId  = intval($shiftId);
        $reportDate = trim($reportDate ?: date('Y-m-d'));
        $date_safe = mysqli_real_escape_string($connection, $reportDate);

        // Fetch all active nozzles
        $nozzles_q = mysqli_query($connection, "
            SELECT n.id, n.name, n.tank_id, n.item_id, n.start_reading,
                   i.name AS item_name, t.tank_name
            FROM tbl_nozzles n
            LEFT JOIN tbl_items i ON n.item_id = i.id
            LEFT JOIN tbl_tanks t ON n.tank_id = t.id
            WHERE (n.deleted_at IS NULL OR n.deleted_at = '0000-00-00 00:00:00')
              AND n.status = 'Active'
            ORDER BY n.id ASC
        ");
        $nozzles = [];
        $nozzle_map = [];
        if ($nozzles_q) {
            while ($nrow = mysqli_fetch_assoc($nozzles_q)) {
                $nid = intval($nrow['id']);
                $nozzles[] = $nrow;
                $nozzle_map[$nid] = [
                    'nozzle_id'           => $nid,
                    'nozzle_name'         => $nrow['name'],
                    'item_name'           => $nrow['item_name'] ?? 'Fuel',
                    'tank_name'           => $nrow['tank_name'] ?? 'N/A',
                    'start_reading'       => floatval($nrow['start_reading']),
                    'min_opening_meter'   => null,
                    'max_closing_meter'   => null,
                    'meter_litres'        => 0.00,
                    'meter_revenue'       => 0.00,
                    'test_litres'         => 0.00,
                    'cash_litres'         => 0.00,
                    'cash_amount'         => 0.00,
                    'credit_issued_litres'=> 0.00,
                    'credit_quota_litres' => 0.00,
                    'credit_amount'       => 0.00,
                    'card_litres'         => 0.00,
                    'card_amount'         => 0.00,
                    'card_charges'        => 0.00,
                    'card_net'            => 0.00,
                    'expenses'            => 0.00,
                    'settled_litres'      => 0.00,
                    'settled_amount'      => 0.00,
                    'volume_variance'     => 0.00,
                    'financial_variance'  => 0.00,
                    'status'              => 'Balanced'
                ];
            }
        }

        $shift_filter_mr = ($shiftId > 0) ? " AND mr.shift_id = '$shiftId'" : "";
        $shift_filter_cs = ($shiftId > 0) ? " AND cs.shift_id = '$shiftId'" : "";
        $shift_filter_cr = ($shiftId > 0) ? " AND cr.shift_id = '$shiftId'" : "";
        $shift_filter_cd = ($shiftId > 0) ? " AND cd.shift_id = '$shiftId'" : "";

        // 1. Single-pass Meter Readings
        $sql_mr = "
            SELECT mrd.nozzle_id,
                   SUM(mrd.net_sale) AS total_meter_litres,
                   SUM(mrd.amount) AS total_meter_revenue,
                   SUM(mrd.test_reading) AS total_test_litres,
                   MIN(mrd.last_reading) AS min_opening,
                   MAX(mrd.current_reading) AS max_closing
            FROM tbl_meter_reading_details mrd
            JOIN tbl_meter_readings mr ON mrd.meter_reading_id = mr.id
            WHERE mr.date = '$date_safe'
              $shift_filter_mr
              AND (mr.deleted_at IS NULL OR mr.deleted_at = '0000-00-00 00:00:00')
            GROUP BY mrd.nozzle_id
        ";
        $res_mr = mysqli_query($connection, $sql_mr);
        if ($res_mr) {
            while ($r = mysqli_fetch_assoc($res_mr)) {
                $nid = intval($r['nozzle_id']);
                if (isset($nozzle_map[$nid])) {
                    $nozzle_map[$nid]['meter_litres']      = floatval($r['total_meter_litres']);
                    $nozzle_map[$nid]['meter_revenue']     = floatval($r['total_meter_revenue']);
                    $nozzle_map[$nid]['test_litres']       = floatval($r['total_test_litres']);
                    $nozzle_map[$nid]['min_opening_meter'] = floatval($r['min_opening']);
                    $nozzle_map[$nid]['max_closing_meter'] = floatval($r['max_closing']);
                }
            }
        }

        // 2. Single-pass Cash Sales
        $sql_cs = "
            SELECT cs.nozzle_id,
                   SUM(cs.quantity) AS total_cash_litres,
                   SUM(cs.amount) AS total_cash_amount
            FROM tbl_meter_reading_cash_sales cs
            WHERE cs.sale_date = '$date_safe'
              $shift_filter_cs
              AND (cs.deleted_at IS NULL OR cs.deleted_at = '0000-00-00 00:00:00')
            GROUP BY cs.nozzle_id
        ";
        $res_cs = mysqli_query($connection, $sql_cs);
        if ($res_cs) {
            while ($r = mysqli_fetch_assoc($res_cs)) {
                $nid = intval($r['nozzle_id']);
                if (isset($nozzle_map[$nid])) {
                    $nozzle_map[$nid]['cash_litres'] = floatval($r['total_cash_litres']);
                    $nozzle_map[$nid]['cash_amount'] = floatval($r['total_cash_amount']);
                }
            }
        }

        // 3. Single-pass Credit Sales
        $sql_cr = "
            SELECT cr.nozzle_id,
                   SUM(CASE WHEN cr.issue_quantity > 0 THEN cr.issue_quantity ELSE cr.quantity END) AS total_issued_litres,
                   SUM(cr.quantity) AS total_quota_litres,
                   SUM(cr.amount) AS total_credit_amount
            FROM tbl_meter_reading_credit_sales cr
            WHERE (cr.sale_date = '$date_safe' OR (cr.sale_date IS NULL AND cr.slip_date = '$date_safe'))
              $shift_filter_cr
              AND (cr.deleted_at IS NULL OR cr.deleted_at = '0000-00-00 00:00:00')
            GROUP BY cr.nozzle_id
        ";
        $res_cr = mysqli_query($connection, $sql_cr);
        if ($res_cr) {
            while ($r = mysqli_fetch_assoc($res_cr)) {
                $nid = intval($r['nozzle_id']);
                if (isset($nozzle_map[$nid])) {
                    $nozzle_map[$nid]['credit_issued_litres'] = floatval($r['total_issued_litres']);
                    $nozzle_map[$nid]['credit_quota_litres']  = floatval($r['total_quota_litres']);
                    $nozzle_map[$nid]['credit_amount']        = floatval($r['total_credit_amount']);
                }
            }
        }

        // 4. Single-pass Card Sales
        $sql_cd = "
            SELECT cd.nozzle_id,
                   SUM(cd.quantity) AS total_card_litres,
                   SUM(cd.amount) AS total_card_amount,
                   SUM(cd.service_charges) AS total_card_charges,
                   SUM(CASE WHEN cd.net_amount > 0 THEN cd.net_amount ELSE cd.amount END) AS total_card_net
            FROM tbl_meter_reading_card_sales cd
            WHERE cd.sale_date = '$date_safe'
              $shift_filter_cd
              AND (cd.deleted_at IS NULL OR cd.deleted_at = '0000-00-00 00:00:00')
            GROUP BY cd.nozzle_id
        ";
        $res_cd = mysqli_query($connection, $sql_cd);
        if ($res_cd) {
            while ($r = mysqli_fetch_assoc($res_cd)) {
                $nid = intval($r['nozzle_id']);
                if (isset($nozzle_map[$nid])) {
                    $nozzle_map[$nid]['card_litres']  = floatval($r['total_card_litres']);
                    $nozzle_map[$nid]['card_amount']  = floatval($r['total_card_amount']);
                    $nozzle_map[$nid]['card_charges'] = floatval($r['total_card_charges']);
                    $nozzle_map[$nid]['card_net']     = floatval($r['total_card_net']);
                }
            }
        }

        // 5. Single-pass Expenses
        $sql_exp = "
            SELECT e.nozzle_id, SUM(e.amount) AS total_expenses
            FROM tbl_expenses e
            WHERE e.expense_date = '$date_safe'
              AND (e.deleted_at IS NULL OR e.deleted_at = '0000-00-00 00:00:00')
            GROUP BY e.nozzle_id
        ";
        $res_exp = mysqli_query($connection, $sql_exp);
        $station_general_expenses = 0.00;
        if ($res_exp) {
            while ($r = mysqli_fetch_assoc($res_exp)) {
                $nid = intval($r['nozzle_id'] ?? 0);
                $amt = floatval($r['total_expenses']);
                if ($nid > 0 && isset($nozzle_map[$nid])) {
                    $nozzle_map[$nid]['expenses'] = $amt;
                } else {
                    $station_general_expenses += $amt;
                }
            }
        }

        // Compute row-level variances and station totals
        $station_meter_litres    = 0.00;
        $station_meter_revenue   = 0.00;
        $station_test_litres     = 0.00;
        $station_cash_litres     = 0.00;
        $station_cash_amount     = 0.00;
        $station_credit_litres   = 0.00;
        $station_credit_amount   = 0.00;
        $station_card_litres     = 0.00;
        $station_card_amount     = 0.00;
        $station_card_charges    = 0.00;
        $station_card_net        = 0.00;
        $station_nozzle_expenses = 0.00;

        $product_summaries = [];

        foreach ($nozzle_map as $nid => &$row) {
            $row['settled_litres'] = round($row['cash_litres'] + $row['credit_issued_litres'] + $row['card_litres'], 2);
            $row['settled_amount'] = round($row['cash_amount'] + $row['credit_amount'] + $row['card_amount'], 2);
            $row['volume_variance'] = round($row['settled_litres'] - $row['meter_litres'], 2);
            $row['financial_variance'] = round($row['settled_amount'] - $row['meter_revenue'], 2);

            if (abs($row['financial_variance']) < 0.01) {
                $row['status'] = 'Balanced';
            } elseif ($row['financial_variance'] < -0.01) {
                $row['status'] = 'Shortage';
            } else {
                $row['status'] = 'Surplus';
            }

            // Accumulate station totals
            $station_meter_litres    += $row['meter_litres'];
            $station_meter_revenue   += $row['meter_revenue'];
            $station_test_litres     += $row['test_litres'];
            $station_cash_litres     += $row['cash_litres'];
            $station_cash_amount     += $row['cash_amount'];
            $station_credit_litres   += $row['credit_issued_litres'];
            $station_credit_amount   += $row['credit_amount'];
            $station_card_litres     += $row['card_litres'];
            $station_card_amount     += $row['card_amount'];
            $station_card_charges    += $row['card_charges'];
            $station_card_net        += $row['card_net'];
            $station_nozzle_expenses += $row['expenses'];

            // Product rollup
            $pname = $row['item_name'];
            if (!isset($product_summaries[$pname])) {
                $product_summaries[$pname] = [
                    'fuel_name'    => $pname,
                    'nozzle_count' => 0,
                    'meter_litres' => 0.00,
                    'revenue'      => 0.00
                ];
            }
            $product_summaries[$pname]['nozzle_count']++;
            $product_summaries[$pname]['meter_litres'] += $row['meter_litres'];
            $product_summaries[$pname]['revenue']      += $row['meter_revenue'];
        }
        unset($row);

        $station_total_expenses     = round($station_nozzle_expenses + $station_general_expenses, 2);
        $station_settled_litres     = round($station_cash_litres + $station_credit_litres + $station_card_litres, 2);
        $station_settled_amount     = round($station_cash_amount + $station_credit_amount + $station_card_amount, 2);
        $station_volume_variance    = round($station_settled_litres - $station_meter_litres, 2);
        $station_financial_variance = round($station_settled_amount - $station_meter_revenue, 2);
        $station_net_yield          = round($station_meter_revenue - $station_total_expenses, 2);

        $station_status = 'Balanced';
        if ($station_financial_variance < -0.01) {
            $station_status = 'Shortage';
        } elseif ($station_financial_variance > 0.01) {
            $station_status = 'Surplus';
        }

        // Fetch detailed rows for tabs (Physical Meters, Cash, Credit, Card, Expenses) across all nozzles
        $meter_rows = [];
        $sql_all_mr = "
            SELECT mrd.*, mr.date, mr.shift_id, sh.name AS shift_name,
                   n.name AS nozzle_name, i.name AS item_name,
                   CONCAT(st.first_name, ' ', st.last_name) AS staff_name
            FROM tbl_meter_reading_details mrd
            JOIN tbl_meter_readings mr ON mrd.meter_reading_id = mr.id
            LEFT JOIN tbl_nozzles n ON mrd.nozzle_id = n.id
            LEFT JOIN tbl_items i ON n.item_id = i.id
            LEFT JOIN tbl_shifts sh ON mr.shift_id = sh.id
            LEFT JOIN tbl_staff st ON mrd.staff_id = st.id
            WHERE mr.date = '$date_safe'
              $shift_filter_mr
              AND (mr.deleted_at IS NULL OR mr.deleted_at = '0000-00-00 00:00:00')
            ORDER BY mrd.nozzle_id ASC, mr.shift_id ASC
        ";
        $res_all_mr = mysqli_query($connection, $sql_all_mr);
        if ($res_all_mr) {
            while ($r = mysqli_fetch_assoc($res_all_mr)) {
                $meter_rows[] = $r;
            }
        }

        $cash_rows = [];
        $sql_all_cs = "
            SELECT cs.*, sh.name AS shift_name, n.name AS nozzle_name, i.name AS item_name
            FROM tbl_meter_reading_cash_sales cs
            LEFT JOIN tbl_nozzles n ON cs.nozzle_id = n.id
            LEFT JOIN tbl_items i ON n.item_id = i.id
            LEFT JOIN tbl_shifts sh ON cs.shift_id = sh.id
            WHERE cs.sale_date = '$date_safe'
              $shift_filter_cs
              AND (cs.deleted_at IS NULL OR cs.deleted_at = '0000-00-00 00:00:00')
            ORDER BY cs.nozzle_id ASC, cs.shift_id ASC
        ";
        $res_all_cs = mysqli_query($connection, $sql_all_cs);
        if ($res_all_cs) {
            while ($r = mysqli_fetch_assoc($res_all_cs)) {
                $cash_rows[] = $r;
            }
        }

        $credit_rows = [];
        $sql_all_cr = "
            SELECT cr.*, sh.name AS shift_name, c.name AS customer_name,
                   n.name AS nozzle_name, i.name AS item_name
            FROM tbl_meter_reading_credit_sales cr
            LEFT JOIN tbl_nozzles n ON cr.nozzle_id = n.id
            LEFT JOIN tbl_items i ON n.item_id = i.id
            LEFT JOIN tbl_shifts sh ON cr.shift_id = sh.id
            LEFT JOIN tbl_customers c ON cr.account_number = c.id
            WHERE (cr.sale_date = '$date_safe' OR (cr.sale_date IS NULL AND cr.slip_date = '$date_safe'))
              $shift_filter_cr
              AND (cr.deleted_at IS NULL OR cr.deleted_at = '0000-00-00 00:00:00')
            ORDER BY cr.nozzle_id ASC, cr.shift_id ASC
        ";
        $res_all_cr = mysqli_query($connection, $sql_all_cr);
        if ($res_all_cr) {
            while ($r = mysqli_fetch_assoc($res_all_cr)) {
                $credit_rows[] = $r;
            }
        }

        $card_rows = [];
        $sql_all_cd = "
            SELECT cd.*, sh.name AS shift_name, cm.name AS machine_name,
                   n.name AS nozzle_name, i.name AS item_name
            FROM tbl_meter_reading_card_sales cd
            LEFT JOIN tbl_nozzles n ON cd.nozzle_id = n.id
            LEFT JOIN tbl_items i ON n.item_id = i.id
            LEFT JOIN tbl_shifts sh ON cd.shift_id = sh.id
            LEFT JOIN tbl_card_machines cm ON cd.card_machine_id = cm.id
            WHERE cd.sale_date = '$date_safe'
              $shift_filter_cd
              AND (cd.deleted_at IS NULL OR cd.deleted_at = '0000-00-00 00:00:00')
            ORDER BY cd.nozzle_id ASC, cd.shift_id ASC
        ";
        $res_all_cd = mysqli_query($connection, $sql_all_cd);
        if ($res_all_cd) {
            while ($r = mysqli_fetch_assoc($res_all_cd)) {
                $card_rows[] = $r;
            }
        }

        $expense_rows = [];
        $sql_all_exp = "
            SELECT e.*, et.name AS expense_type_name, a.name AS creator_name,
                   n.name AS nozzle_name
            FROM tbl_expenses e
            LEFT JOIN tbl_nozzles n ON e.nozzle_id = n.id
            LEFT JOIN tbl_expense_types et ON e.expense_type_id = et.id
            LEFT JOIN tbl_accounts a ON e.created_by = a.id
            WHERE e.expense_date = '$date_safe'
              AND (e.deleted_at IS NULL OR e.deleted_at = '0000-00-00 00:00:00')
            ORDER BY e.nozzle_id ASC, e.id ASC
        ";
        $res_all_exp = mysqli_query($connection, $sql_all_exp);
        if ($res_all_exp) {
            while ($r = mysqli_fetch_assoc($res_all_exp)) {
                $expense_rows[] = $r;
            }
        }

        return [
            'is_station_summary'         => true,
            'selected_nozzle'            => null,
            'report_date'                => $reportDate,
            'shift_id'                   => $shiftId,
            'nozzle_matrix'              => array_values($nozzle_map),
            'product_summaries'          => array_values($product_summaries),
            'station_meter_litres'       => $station_meter_litres,
            'station_meter_revenue'      => $station_meter_revenue,
            'station_test_litres'        => $station_test_litres,
            'station_cash_litres'        => $station_cash_litres,
            'station_cash_amount'        => $station_cash_amount,
            'station_credit_litres'      => $station_credit_litres,
            'station_credit_amount'      => $station_credit_amount,
            'station_card_litres'        => $station_card_litres,
            'station_card_amount'        => $station_card_amount,
            'station_card_charges'       => $station_card_charges,
            'station_card_net'           => $station_card_net,
            'station_nozzle_expenses'    => $station_nozzle_expenses,
            'station_general_expenses'   => $station_general_expenses,
            'station_total_expenses'     => $station_total_expenses,
            'station_settled_litres'     => $station_settled_litres,
            'station_settled_amount'     => $station_settled_amount,
            'station_volume_variance'    => $station_volume_variance,
            'station_financial_variance' => $station_financial_variance,
            'station_net_yield'          => $station_net_yield,
            'station_status'             => $station_status,

            // Unified template compatibility keys
            'total_meter_litres'         => $station_meter_litres,
            'total_meter_revenue'        => $station_meter_revenue,
            'total_test_litres'          => $station_test_litres,
            'total_cash_litres'          => $station_cash_litres,
            'total_cash_amount'          => $station_cash_amount,
            'total_credit_issued_litres' => $station_credit_litres,
            'total_credit_quota_litres'  => $station_credit_litres,
            'total_credit_amount'        => $station_credit_amount,
            'total_card_litres'          => $station_card_litres,
            'total_card_amount'          => $station_card_amount,
            'total_card_charges'         => $station_card_charges,
            'total_card_net'             => $station_card_net,
            'total_nozzle_expenses'      => $station_total_expenses,
            'total_settled_litres'       => $station_settled_litres,
            'total_settled_amount'       => $station_settled_amount,
            'volume_variance'            => $station_volume_variance,
            'financial_variance'         => $station_financial_variance,
            'net_nozzle_yield'           => $station_net_yield,

            'meter_rows'                 => $meter_rows,
            'cash_rows'                  => $cash_rows,
            'credit_rows'                => $credit_rows,
            'card_rows'                  => $card_rows,
            'expense_rows'               => $expense_rows
        ];
    }
}
