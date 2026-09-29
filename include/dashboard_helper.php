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
