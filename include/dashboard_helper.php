<?php
/**
 * PPMS Dashboard Helper Services
 * 
 * Provides centralized business queries for:
 * 1. Today's fuel revenue and net litres pumped from dispenser meter readings.
 * 2. 7-day chronological net sales and fuel volume aggregation for Chart.js.
 * 3. Real-time deficit inventory evaluation for restock alerts.
 */

if (!function_exists('get_today_meter_revenue')) {
    /**
     * Fetch today's total revenue, net litres sold, and closed shifts count from meter readings.
     *
     * @param mysqli $connection
     * @param string|null $today_date Optional override (Y-m-d)
     * @return array
     */
    function get_today_meter_revenue($connection, $today_date = null) {
        $today = $today_date ? mysqli_real_escape_string($connection, $today_date) : date('Y-m-d');

        // 1. Total revenue and shifts count from master readings
        $sql_rev = "SELECT 
                        COUNT(id) AS shifts_count,
                        COALESCE(SUM(grand_total), 0) AS total_revenue
                    FROM tbl_meter_readings
                    WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
                      AND date = '$today'";
        $res_rev = mysqli_query($connection, $sql_rev);
        $row_rev = $res_rev ? mysqli_fetch_assoc($res_rev) : ['shifts_count' => 0, 'total_revenue' => 0];

        // 2. Total net litres from detail rows of active meter readings
        $sql_ltr = "SELECT 
                        COALESCE(SUM(mrd.net_sale), 0) AS total_litres
                    FROM tbl_meter_reading_details mrd
                    JOIN tbl_meter_readings mr ON mrd.meter_reading_id = mr.id
                    WHERE (mr.deleted_at IS NULL OR mr.deleted_at = '0000-00-00 00:00:00')
                      AND mr.date = '$today'";
        $res_ltr = mysqli_query($connection, $sql_ltr);
        $row_ltr = $res_ltr ? mysqli_fetch_assoc($res_ltr) : ['total_litres' => 0];

        return [
            'date'         => $today,
            'revenue'      => round(floatval($row_rev['total_revenue'] ?? 0), 2),
            'litres'       => round(floatval($row_ltr['total_litres'] ?? 0), 2),
            'shifts_count' => intval($row_rev['shifts_count'] ?? 0)
        ];
    }
}

if (!function_exists('get_seven_days_revenue_stats')) {
    /**
     * Build an exact 7-consecutive-day chronological series of revenue (PKR) and litres.
     * Zero-fills any days with no recorded readings.
     *
     * @param mysqli $connection
     * @param string|null $end_date Optional end date (Y-m-d), defaults to today
     * @return array
     */
    function get_seven_days_revenue_stats($connection, $end_date = null) {
        $end_dt = $end_date ? new DateTime($end_date) : new DateTime();
        $start_dt = clone $end_dt;
        $start_dt->modify('-6 days');

        $start_str = $start_dt->format('Y-m-d');
        $end_str   = $end_dt->format('Y-m-d');

        // Build continuous 7-day chronological baseline map
        $days_map = [];
        $cur = clone $start_dt;
        for ($i = 0; $i < 7; $i++) {
            $d_key = $cur->format('Y-m-d');
            $days_map[$d_key] = [
                'date'         => $d_key,
                'label'        => $cur->format('d M'),
                'day_name'     => $cur->format('D'),
                'revenue'      => 0.0,
                'litres'       => 0.0,
                'shifts_count' => 0
            ];
            $cur->modify('+1 day');
        }

        // Query 1: Revenue & closed shifts by date
        $sql_rev = "SELECT 
                        date,
                        COUNT(id) AS shifts_count,
                        COALESCE(SUM(grand_total), 0) AS daily_revenue
                    FROM tbl_meter_readings
                    WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
                      AND date BETWEEN '$start_str' AND '$end_str'
                    GROUP BY date";
        $res_rev = mysqli_query($connection, $sql_rev);
        if ($res_rev) {
            while ($r = mysqli_fetch_assoc($res_rev)) {
                $d = $r['date'];
                if (isset($days_map[$d])) {
                    $days_map[$d]['revenue']      = round(floatval($r['daily_revenue']), 2);
                    $days_map[$d]['shifts_count'] = intval($r['shifts_count']);
                }
            }
        }

        // Query 2: Net litres by date
        $sql_ltr = "SELECT 
                        mr.date,
                        COALESCE(SUM(mrd.net_sale), 0) AS daily_litres
                    FROM tbl_meter_reading_details mrd
                    JOIN tbl_meter_readings mr ON mrd.meter_reading_id = mr.id
                    WHERE (mr.deleted_at IS NULL OR mr.deleted_at = '0000-00-00 00:00:00')
                      AND mr.date BETWEEN '$start_str' AND '$end_str'
                    GROUP BY mr.date";
        $res_ltr = mysqli_query($connection, $sql_ltr);
        if ($res_ltr) {
            while ($r = mysqli_fetch_assoc($res_ltr)) {
                $d = $r['date'];
                if (isset($days_map[$d])) {
                    $days_map[$d]['litres'] = round(floatval($r['daily_litres']), 2);
                }
            }
        }

        // Prepare chart payload arrays and aggregate metrics
        $labels   = [];
        $revenues = [];
        $litres   = [];
        $total_rev = 0.0;
        $total_ltr = 0.0;
        $peak_day  = ['date' => '', 'label' => 'None', 'revenue' => 0.0, 'litres' => 0.0];

        foreach ($days_map as $d => $info) {
            $labels[]   = $info['label'];
            $revenues[] = $info['revenue'];
            $litres[]   = $info['litres'];
            $total_rev  += $info['revenue'];
            $total_ltr  += $info['litres'];

            if ($info['revenue'] >= $peak_day['revenue']) {
                $peak_day = [
                    'date'    => $d,
                    'label'   => $info['label'],
                    'revenue' => $info['revenue'],
                    'litres'  => $info['litres']
                ];
            }
        }

        $avg_daily_rev = round($total_rev / 7, 2);

        return [
            'start_date'        => $start_str,
            'end_date'          => $end_str,
            'days'              => array_values($days_map),
            'labels'            => $labels,
            'revenues'          => $revenues,
            'litres'            => $litres,
            'total_revenue'     => round($total_rev, 2),
            'total_litres'      => round($total_ltr, 2),
            'avg_daily_revenue' => $avg_daily_rev,
            'peak_day'          => $peak_day
        ];
    }
}

if (!function_exists('get_seven_days_fuel_payment_breakdown')) {
    /**
     * Build an exact 7-consecutive-day chronological series of fuel sales
     * split by payment method: Cash, Credit, and Card sales.
     * Zero-fills any days with no recorded sales.
     *
     * @param mysqli $connection
     * @param string|null $end_date Optional end date (Y-m-d), defaults to today
     * @return array
     */
    function get_seven_days_fuel_payment_breakdown($connection, $end_date = null) {
        $end_dt = $end_date ? new DateTime($end_date) : new DateTime();
        $start_dt = clone $end_dt;
        $start_dt->modify('-6 days');

        $start_str = $start_dt->format('Y-m-d');
        $end_str   = $end_dt->format('Y-m-d');

        // Build continuous 7-day chronological baseline map
        $days_map = [];
        $cur = clone $start_dt;
        for ($i = 0; $i < 7; $i++) {
            $d_key = $cur->format('Y-m-d');
            $days_map[$d_key] = [
                'date'          => $d_key,
                'label'         => $cur->format('d M'),
                'day_name'      => $cur->format('D'),
                'cash_amount'   => 0.0,
                'cash_litres'   => 0.0,
                'credit_amount' => 0.0,
                'credit_litres' => 0.0,
                'card_amount'   => 0.0,
                'card_litres'   => 0.0,
                'total_amount'  => 0.0
            ];
            $cur->modify('+1 day');
        }

        // 1. Cash fuel sales
        $sql_cash = "SELECT 
                        cs.sale_date,
                        COALESCE(SUM(cs.amount), 0) AS total_amount,
                        COALESCE(SUM(cs.quantity), 0) AS total_litres
                     FROM tbl_meter_reading_cash_sales cs
                     WHERE (cs.deleted_at IS NULL OR cs.deleted_at = '0000-00-00 00:00:00')
                       AND cs.sale_date BETWEEN '$start_str' AND '$end_str'
                     GROUP BY cs.sale_date";
        $res_cash = mysqli_query($connection, $sql_cash);
        if ($res_cash) {
            while ($r = mysqli_fetch_assoc($res_cash)) {
                $d = $r['sale_date'];
                if (isset($days_map[$d])) {
                    $days_map[$d]['cash_amount'] = round(floatval($r['total_amount']), 2);
                    $days_map[$d]['cash_litres'] = round(floatval($r['total_litres']), 2);
                }
            }
        }

        // 2. Credit fuel sales
        $sql_credit = "SELECT 
                          COALESCE(cr.sale_date, cr.slip_date) AS bill_date,
                          COALESCE(SUM(cr.amount), 0) AS total_amount,
                          COALESCE(SUM(CASE WHEN cr.issue_quantity > 0 THEN cr.issue_quantity ELSE cr.quantity END), 0) AS total_litres
                       FROM tbl_meter_reading_credit_sales cr
                       WHERE (cr.deleted_at IS NULL OR cr.deleted_at = '0000-00-00 00:00:00')
                         AND (cr.sale_date BETWEEN '$start_str' AND '$end_str' OR (cr.sale_date IS NULL AND cr.slip_date BETWEEN '$start_str' AND '$end_str'))
                       GROUP BY bill_date";
        $res_credit = mysqli_query($connection, $sql_credit);
        if ($res_credit) {
            while ($r = mysqli_fetch_assoc($res_credit)) {
                $d = $r['bill_date'];
                if (isset($days_map[$d])) {
                    $days_map[$d]['credit_amount'] = round(floatval($r['total_amount']), 2);
                    $days_map[$d]['credit_litres'] = round(floatval($r['total_litres']), 2);
                }
            }
        }

        // 3. Card fuel sales
        $sql_card = "SELECT 
                        cd.sale_date,
                        COALESCE(SUM(cd.amount), 0) AS total_amount,
                        COALESCE(SUM(cd.quantity), 0) AS total_litres
                     FROM tbl_meter_reading_card_sales cd
                     WHERE (cd.deleted_at IS NULL OR cd.deleted_at = '0000-00-00 00:00:00')
                       AND cd.sale_date BETWEEN '$start_str' AND '$end_str'
                     GROUP BY cd.sale_date";
        $res_card = mysqli_query($connection, $sql_card);
        if ($res_card) {
            while ($r = mysqli_fetch_assoc($res_card)) {
                $d = $r['sale_date'];
                if (isset($days_map[$d])) {
                    $days_map[$d]['card_amount'] = round(floatval($r['total_amount']), 2);
                    $days_map[$d]['card_litres'] = round(floatval($r['total_litres']), 2);
                }
            }
        }

        // Prepare chart payload arrays and aggregate metrics
        $labels        = [];
        $cash_series   = [];
        $credit_series = [];
        $card_series   = [];
        $tot_cash      = 0.0;
        $tot_credit    = 0.0;
        $tot_card      = 0.0;

        foreach ($days_map as $d => &$info) {
            $day_tot = round($info['cash_amount'] + $info['credit_amount'] + $info['card_amount'], 2);
            $info['total_amount'] = $day_tot;

            $labels[]        = $info['label'];
            $cash_series[]   = $info['cash_amount'];
            $credit_series[] = $info['credit_amount'];
            $card_series[]   = $info['card_amount'];

            $tot_cash   += $info['cash_amount'];
            $tot_credit += $info['credit_amount'];
            $tot_card   += $info['card_amount'];
        }
        unset($info);

        $grand_tot = round($tot_cash + $tot_credit + $tot_card, 2);

        return [
            'start_date'        => $start_str,
            'end_date'          => $end_str,
            'days'              => array_values($days_map),
            'labels'            => $labels,
            'cash_series'       => $cash_series,
            'credit_series'     => $credit_series,
            'card_series'       => $card_series,
            'total_cash'        => round($tot_cash, 2),
            'total_credit'      => round($tot_credit, 2),
            'total_card'        => round($tot_card, 2),
            'grand_total'       => $grand_tot,
            'cash_percentage'   => $grand_tot > 0 ? round(($tot_cash / $grand_tot) * 100, 1) : 0,
            'credit_percentage' => $grand_tot > 0 ? round(($tot_credit / $grand_tot) * 100, 1) : 0,
            'card_percentage'   => $grand_tot > 0 ? round(($tot_card / $grand_tot) * 100, 1) : 0,
        ];
    }
}

if (!function_exists('get_restock_needed_products')) {
    /**
     * Retrieve ONLY products whose stock has dropped to or below their reorder level.
     *
     * @param mysqli $connection
     * @return array
     */
    function get_restock_needed_products($connection) {
        $sql = "
            SELECT p.id, p.name, COALESCE(NULLIF(p.cash_rate, 0), p.price, 0) AS price, 
                   COALESCE(p.reorder_level, 0) AS reorder_level,
                   COALESCE((SELECT SUM(quantity) FROM tbl_lubricant_purchases WHERE product_id = p.id AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')), 0) AS total_purchased,
                   COALESCE((SELECT SUM(quantity) FROM tbl_lubricant_sales WHERE product_id = p.id AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')), 0) AS total_sold
            FROM tbl_lubricant_products p
            WHERE (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
            ORDER BY p.name ASC
        ";
        $res = mysqli_query($connection, $sql);
        $restock_items = [];

        if ($res) {
            while ($p = mysqli_fetch_assoc($res)) {
                $current_stock = floatval($p['total_purchased']) - floatval($p['total_sold']);
                $reorder_level = floatval($p['reorder_level']);
                
                // Low Stock condition: current_stock <= reorder_level (or <= 0)
                if (($reorder_level > 0 && $current_stock <= $reorder_level) || ($current_stock <= 0)) {
                    $p['current_stock'] = $current_stock;
                    $p['deficit']       = max(0, $reorder_level - $current_stock);
                    $restock_items[]    = $p;
                }
            }
        }

        // Sort by deficit descending (highest urgency first)
        usort($restock_items, function($a, $b) {
            return $b['deficit'] <=> $a['deficit'];
        });

        return $restock_items;
    }
}

if (!function_exists('get_dashboard_multi_period_charts_data')) {
    /**
     * Build unified multi-period analytics data for all 4 dashboard charts
     * across three horizons: 'days' (Days), 'weeks' (Weeks), and 'months' (Months).
     *
     * @param mysqli $connection
     * @param string|null $ref_date Optional reference date (Y-m-d), defaults to today
     * @return array
     */
    function get_dashboard_multi_period_charts_data($connection, $ref_date = null) {
        $today_dt = $ref_date ? new DateTime($ref_date) : new DateTime();
        $today_str = $today_dt->format('Y-m-d');

        // 1. Setup Horizons
        // Days (7 calendar days)
        $d_start = clone $today_dt;
        $d_start->modify('-6 days');
        $days_bins = [];
        $cur = clone $d_start;
        for ($i = 0; $i < 7; $i++) {
            $k = $cur->format('Y-m-d');
            $days_bins[$k] = ['label' => $cur->format('d M'), 'start' => $k, 'end' => $k];
            $cur->modify('+1 day');
        }

        // Weeks (4 calendar weeks)
        $weeks_bins = [];
        for ($w = 4; $w >= 1; $w--) {
            $w_end = clone $today_dt;
            $off_end = ($w - 1) * 7;
            $off_start = ($w * 7) - 1;
            $w_end->modify("-{$off_end} days");
            $w_start = clone $today_dt;
            $w_start->modify("-{$off_start} days");
            $k = 'week_' . (5 - $w);
            $weeks_bins[$k] = [
                'label' => 'Week ' . (5 - $w),
                'start' => $w_start->format('Y-m-d'),
                'end'   => $w_end->format('Y-m-d')
            ];
        }

        // Months (12 calendar months safe from end-of-month overflow)
        $first_of_month = new DateTime($today_dt->format('Y-m-01'));
        $months_bins = [];
        for ($m = 11; $m >= 0; $m--) {
            $m_cur = clone $first_of_month;
            $m_cur->modify("-{$m} months");
            $k = $m_cur->format('Y-m');
            $m_start = $m_cur->format('Y-m-01');
            $m_end   = $m_cur->format('Y-m-t');
            if ($m === 0 && $m_end > $today_str) {
                $m_end = $today_str;
            }
            $months_bins[$k] = [
                'label' => $m_cur->format('M Y'),
                'start' => $m_start,
                'end'   => $m_end
            ];
        }

        $horizons_cfg = [
            'days'   => $days_bins,
            'weeks'  => $weeks_bins,
            'months' => $months_bins
        ];

        // Earliest date across all horizons
        $earliest_date = reset($months_bins)['start'];

        // Query 1: Meter reading revenue
        $rev_by_date = [];
        $res1 = mysqli_query($connection, "SELECT date, COALESCE(SUM(grand_total), 0) AS rev FROM tbl_meter_readings WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') AND date >= '$earliest_date' GROUP BY date");
        if ($res1) {
            while ($r = mysqli_fetch_assoc($res1)) $rev_by_date[$r['date']] = floatval($r['rev']);
        }

        // Query 2: Net litres
        $ltr_by_date = [];
        $res2 = mysqli_query($connection, "SELECT mr.date, COALESCE(SUM(mrd.net_sale), 0) AS ltr FROM tbl_meter_reading_details mrd JOIN tbl_meter_readings mr ON mrd.meter_reading_id = mr.id WHERE (mr.deleted_at IS NULL OR mr.deleted_at = '0000-00-00 00:00:00') AND mr.date >= '$earliest_date' GROUP BY mr.date");
        if ($res2) {
            while ($r = mysqli_fetch_assoc($res2)) $ltr_by_date[$r['date']] = floatval($r['ltr']);
        }

        // Query 3: Cash sales
        $cash_by_date = [];
        $res3 = mysqli_query($connection, "SELECT sale_date, COALESCE(SUM(amount), 0) AS amt FROM tbl_meter_reading_cash_sales WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') AND sale_date >= '$earliest_date' GROUP BY sale_date");
        if ($res3) {
            while ($r = mysqli_fetch_assoc($res3)) $cash_by_date[$r['sale_date']] = floatval($r['amt']);
        }

        // Query 4: Credit sales
        $credit_by_date = [];
        $res4 = mysqli_query($connection, "SELECT COALESCE(sale_date, slip_date) as b_date, COALESCE(SUM(amount), 0) AS amt FROM tbl_meter_reading_credit_sales WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') AND (sale_date >= '$earliest_date' OR (sale_date IS NULL AND slip_date >= '$earliest_date')) GROUP BY b_date");
        if ($res4) {
            while ($r = mysqli_fetch_assoc($res4)) $credit_by_date[$r['b_date']] = floatval($r['amt']);
        }

        // Query 5: Card sales
        $card_by_date = [];
        $res5 = mysqli_query($connection, "SELECT sale_date, COALESCE(SUM(amount), 0) AS amt FROM tbl_meter_reading_card_sales WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') AND sale_date >= '$earliest_date' GROUP BY sale_date");
        if ($res5) {
            while ($r = mysqli_fetch_assoc($res5)) $card_by_date[$r['sale_date']] = floatval($r['amt']);
        }

        // Query 6: Expenses by date and type
        $exp_records = [];
        $res6 = mysqli_query($connection, "SELECT e.expense_date, COALESCE(t.name, 'Miscellaneous') as type_name, SUM(e.amount) as amt FROM tbl_expenses e LEFT JOIN tbl_expense_types t ON e.expense_type_id = t.id WHERE (e.deleted_at IS NULL OR e.deleted_at = '0000-00-00 00:00:00') AND e.expense_date >= '$earliest_date' GROUP BY e.expense_date, type_name");
        if ($res6) {
            while ($r = mysqli_fetch_assoc($res6)) $exp_records[] = $r;
        }

        // Query 7: Product sales by date and product
        $prod_records = [];
        $res7 = mysqli_query($connection, "SELECT s.date, COALESCE(p.name, 'Product') as prod_name, SUM(s.amount) as amt, SUM(s.quantity) as qty FROM tbl_lubricant_sales s LEFT JOIN tbl_lubricant_products p ON s.product_id = p.id WHERE (s.deleted_at IS NULL OR s.deleted_at = '0000-00-00 00:00:00') AND s.date >= '$earliest_date' GROUP BY s.date, prod_name");
        if ($res7) {
            while ($r = mysqli_fetch_assoc($res7)) $prod_records[] = $r;
        }

        // Compile each horizon
        $output = [];
        foreach ($horizons_cfg as $h_key => $bins) {
            $labels       = [];
            $fuel_rev     = [];
            $fuel_ltr     = [];
            $cash_ser     = [];
            $credit_ser   = [];
            $card_ser     = [];
            $expense_ser  = [];
            $prod_rev_ser = [];
            $prod_qty_ser = [];

            $tot_fuel_rev = 0.0;
            $tot_fuel_ltr = 0.0;
            $tot_cash     = 0.0;
            $tot_credit   = 0.0;
            $tot_card     = 0.0;
            $tot_expense  = 0.0;
            $tot_prod_rev = 0.0;
            $tot_prod_qty = 0.0;

            $exp_cat_totals = [];
            $prod_totals    = [];

            foreach ($bins as $bin_key => $bin_info) {
                $labels[] = $bin_info['label'];
                $b_start = $bin_info['start'];
                $b_end   = $bin_info['end'];

                // Sum fuel revenue & litres
                $b_rev = 0.0;
                $b_ltr = 0.0;
                foreach ($rev_by_date as $d => $amt) {
                    if ($d >= $b_start && $d <= $b_end) $b_rev += $amt;
                }
                foreach ($ltr_by_date as $d => $qty) {
                    if ($d >= $b_start && $d <= $b_end) $b_ltr += $qty;
                }
                $fuel_rev[] = round($b_rev, 2);
                $fuel_ltr[] = round($b_ltr, 2);
                $tot_fuel_rev += $b_rev;
                $tot_fuel_ltr += $b_ltr;

                // Sum cash, credit, card
                $b_cash = 0.0;
                $b_cred = 0.0;
                $b_card = 0.0;
                foreach ($cash_by_date as $d => $amt) {
                    if ($d >= $b_start && $d <= $b_end) $b_cash += $amt;
                }
                foreach ($credit_by_date as $d => $amt) {
                    if ($d >= $b_start && $d <= $b_end) $b_cred += $amt;
                }
                foreach ($card_by_date as $d => $amt) {
                    if ($d >= $b_start && $d <= $b_end) $b_card += $amt;
                }
                $cash_ser[]   = round($b_cash, 2);
                $credit_ser[] = round($b_cred, 2);
                $card_ser[]   = round($b_card, 2);
                $tot_cash   += $b_cash;
                $tot_credit += $b_cred;
                $tot_card   += $b_card;

                // Sum expenses
                $b_exp = 0.0;
                foreach ($exp_records as $er) {
                    if ($er['expense_date'] >= $b_start && $er['expense_date'] <= $b_end) {
                        $b_exp += floatval($er['amt']);
                        $cat = $er['type_name'];
                        $exp_cat_totals[$cat] = ($exp_cat_totals[$cat] ?? 0.0) + floatval($er['amt']);
                    }
                }
                $expense_ser[] = round($b_exp, 2);
                $tot_expense  += $b_exp;

                // Sum product sales
                $b_prev = 0.0;
                $b_pqty = 0.0;
                foreach ($prod_records as $pr) {
                    if ($pr['date'] >= $b_start && $pr['date'] <= $b_end) {
                        $b_prev += floatval($pr['amt']);
                        $b_pqty += floatval($pr['qty']);
                        $pname = $pr['prod_name'];
                        $prod_totals[$pname] = ($prod_totals[$pname] ?? 0.0) + floatval($pr['amt']);
                    }
                }
                $prod_rev_ser[] = round($b_prev, 2);
                $prod_qty_ser[] = round($b_pqty, 2);
                $tot_prod_rev  += $b_prev;
                $tot_prod_qty  += $b_pqty;
            }

            $payment_grand = $tot_cash + $tot_credit + $tot_card;

            $output[$h_key] = [
                'labels'              => $labels,
                'fuel_revenue'        => $fuel_rev,
                'fuel_litres'         => $fuel_ltr,
                'total_fuel_revenue'  => round($tot_fuel_rev, 2),
                'total_fuel_litres'   => round($tot_fuel_ltr, 2),
                'cash_series'         => $cash_ser,
                'credit_series'       => $credit_ser,
                'card_series'         => $card_ser,
                'total_cash'          => round($tot_cash, 2),
                'total_credit'        => round($tot_credit, 2),
                'total_card'          => round($tot_card, 2),
                'total_payment'       => round($payment_grand, 2),
                'cash_percentage'     => $payment_grand > 0 ? round(($tot_cash / $payment_grand) * 100, 1) : 0,
                'credit_percentage'   => $payment_grand > 0 ? round(($tot_credit / $payment_grand) * 100, 1) : 0,
                'card_percentage'     => $payment_grand > 0 ? round(($tot_card / $payment_grand) * 100, 1) : 0,
                'expense_series'      => $expense_ser,
                'total_expenses'      => round($tot_expense, 2),
                'top_expense_cat'     => !empty($exp_cat_totals) ? array_keys($exp_cat_totals, max($exp_cat_totals))[0] : 'None',
                'product_sales_series'=> $prod_rev_ser,
                'product_qty_series'  => $prod_qty_ser,
                'total_product_sales' => round($tot_prod_rev, 2),
                'total_product_qty'   => round($tot_prod_qty, 2),
                'top_product_name'    => !empty($prod_totals) ? array_keys($prod_totals, max($prod_totals))[0] : 'None',
            ];
        }

        return $output;
    }
}
