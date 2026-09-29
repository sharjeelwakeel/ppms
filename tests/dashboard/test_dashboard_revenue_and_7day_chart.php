<?php
/**
 * Test Suite: Dashboard Revenue Stats & 7-Day Net Sales Chart
 * PPMS (Petrol Pump Management System)
 *
 * Verifies:
 * 1. Today's meter reading revenue (PKR) and fuel volume (Litres).
 * 2. 7-Day chronological completeness and aggregation for Chart.js.
 * 3. Soft-deleted meter reading records exclusion invariant.
 * 4. Focused restock alert filtering (only deficit products returned).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../include/dashboard_helper.php';

test_header('TC-DSH-01', 'Dashboard Revenue Stats & 7-Day Net Sales Chart');

$today_date = date('Y-m-d');
$yesterday  = date('Y-m-d', strtotime('-1 day'));

$test_mr_ids = [];
$test_prod_ids = [];

try {
    // -----------------------------------------------------------------
    // Part 1: Seed Meter Readings for Today & Yesterday
    // -----------------------------------------------------------------
    // 1A. Today's Meter Reading: Grand Total = 45,000.00
    $ins1 = mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, grand_total, remarks, created_at)
        VALUES ('$today_date', 1, 45000.00, 'Test Reading Today', NOW())");
    assert_true($ins1, "Today test meter reading inserted");
    $mr_today_id = mysqli_insert_id($connection);
    $test_mr_ids[] = $mr_today_id;

    // Detail rows for today: 120 Ltr + 80 Ltr = 200 Ltr total
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, item_type, price, sale_reading, test_reading, net_sale, amount)
        VALUES ($mr_today_id, 1, 'Diesel', 225.00, 125.0, 5.0, 120.0, 27000.00)");
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, item_type, price, sale_reading, test_reading, net_sale, amount)
        VALUES ($mr_today_id, 2, 'Petrol', 225.00, 80.0, 0.0, 80.0, 18000.00)");

    // 1B. Yesterday's Meter Reading: Grand Total = 60,000.00, 250 Ltr
    $ins2 = mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, grand_total, remarks, created_at)
        VALUES ('$yesterday', 1, 60000.00, 'Test Reading Yesterday', NOW())");
    assert_true($ins2, "Yesterday test meter reading inserted");
    $mr_yest_id = mysqli_insert_id($connection);
    $test_mr_ids[] = $mr_yest_id;

    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, item_type, price, sale_reading, test_reading, net_sale, amount)
        VALUES ($mr_yest_id, 1, 'Diesel', 240.00, 255.0, 5.0, 250.0, 60000.00)");

    // -----------------------------------------------------------------
    // Part 2: Validate Today's Meter Revenue & Net Litres (get_today_meter_revenue)
    // -----------------------------------------------------------------
    $today_result = get_today_meter_revenue($connection, $today_date);
    assert_true($today_result['revenue'] >= 45000.00, "Today revenue includes seeded 45,000.00 (Actual: {$today_result['revenue']})");
    assert_true($today_result['litres'] >= 200.00, "Today volume includes seeded 200.00 Ltr (Actual: {$today_result['litres']})");
    assert_true($today_result['shifts_count'] >= 1, "Today closed shifts count is at least 1");

    // -----------------------------------------------------------------
    // Part 3: Validate 7-Day Net Sales Bar Chart Aggregation (get_seven_days_revenue_stats)
    // -----------------------------------------------------------------
    $seven_days = get_seven_days_revenue_stats($connection, $today_date);
    assert_eq(count($seven_days['days']), 7, "7-day series contains exactly 7 continuous days");
    assert_eq(count($seven_days['labels']), 7, "7-day series contains exactly 7 labels");
    assert_eq(count($seven_days['revenues']), 7, "7-day series contains exactly 7 revenue values");
    assert_eq(count($seven_days['litres']), 7, "7-day series contains exactly 7 volume values");

    // Verify chronological order (oldest to newest)
    $first_day = $seven_days['days'][0]['date'];
    $last_day  = $seven_days['days'][6]['date'];
    assert_eq($last_day, $today_date, "Last day in series is today's date ($today_date)");
    assert_true($first_day < $last_day, "Series is in strict chronological order ($first_day to $last_day)");

    // Verify 7-day cumulative total includes both test days (45,000 + 60,000 = 105,000 minimum)
    assert_true($seven_days['total_revenue'] >= 105000.00, "7-day cumulative revenue includes test records");
    assert_true($seven_days['total_litres'] >= 450.00, "7-day cumulative litres includes test records (200 + 250)");
    assert_true($seven_days['avg_daily_revenue'] > 0, "7-day average daily revenue is calculated accurately");

    // -----------------------------------------------------------------
    // Part 4: Soft-Deleted Records Exclusion Invariant
    // -----------------------------------------------------------------
    mysqli_query($connection, "UPDATE tbl_meter_readings SET deleted_at = NOW() WHERE id = $mr_yest_id");
    
    // Re-query 7-day stats after soft-deleting yesterday's reading
    $post_del_stats = get_seven_days_revenue_stats($connection, $today_date);

    // Find yesterday in post-delete stats
    $yest_found_rev = 0.0;
    foreach ($post_del_stats['days'] as $day_entry) {
        if ($day_entry['date'] === $yesterday) {
            $yest_found_rev = $day_entry['revenue'];
            break;
        }
    }
    assert_eq($yest_found_rev, 0.0, "Soft-deleted meter reading is strictly excluded from 7-day revenue chart");

    // -----------------------------------------------------------------
    // Part 5: Restock-Only Inventory Alert Evaluation
    // -----------------------------------------------------------------
    // Seed Product A: Deficit product (Reorder level 10, purchased 4, sold 0 -> Stock 4 <= 10)
    $p_a = mysqli_query($connection, "INSERT INTO tbl_lubricant_products (name, category_id, price, cash_rate, reorder_level)
        VALUES ('TEST-DEFICIT-OIL-1', 1, 1000.00, 1000.00, 10)");
    assert_true($p_a, "Product A inserted successfully");
    $pid_a = mysqli_insert_id($connection);
    $test_prod_ids[] = $pid_a;
    mysqli_query($connection, "INSERT INTO tbl_lubricant_purchases (product_id, quantity, purchase_price, date)
        VALUES ($pid_a, 4, 800.00, CURDATE())");

    // Seed Product B: Healthy product (Reorder level 5, purchased 20, sold 0 -> Stock 20 > 5)
    $p_b = mysqli_query($connection, "INSERT INTO tbl_lubricant_products (name, category_id, price, cash_rate, reorder_level)
        VALUES ('TEST-HEALTHY-OIL-2', 1, 1000.00, 1000.00, 5)");
    assert_true($p_b, "Product B inserted successfully");
    $pid_b = mysqli_insert_id($connection);
    $test_prod_ids[] = $pid_b;
    mysqli_query($connection, "INSERT INTO tbl_lubricant_purchases (product_id, quantity, purchase_price, date)
        VALUES ($pid_b, 20, 800.00, CURDATE())");

    // Evaluate restock list
    $restock_list = get_restock_needed_products($connection);
    
    // Verify Product A is present in restock list
    $found_a = false;
    $found_b = false;
    foreach ($restock_list as $item) {
        if ($item['id'] == $pid_a) {
            $found_a = true;
            assert_eq($item['current_stock'], 4.0, "Product A current stock evaluated at 4 units");
            assert_eq($item['deficit'], 6.0, "Product A deficit evaluated at 6 units (10 - 4)");
        }
        if ($item['id'] == $pid_b) {
            $found_b = true;
        }
    }

    assert_true($found_a, "Deficit product A is returned in restock alert list");
    assert_true(!$found_b, "Healthy product B is strictly excluded from restock alert list");

    // -----------------------------------------------------------------
    // Part 6: Local Hosting of Chart.js (Zero External CDN Dependency)
    // -----------------------------------------------------------------
    $local_chart_js = __DIR__ . '/../../include/js/chart.min.js';
    assert_true(file_exists($local_chart_js), "Local include/js/chart.min.js file exists");
    assert_true(filesize($local_chart_js) > 50000, "Local chart.min.js contains full production bundle (>50KB)");

    $dashboard_src = file_get_contents(__DIR__ . '/../../dashboard.php');
    assert_true(strpos($dashboard_src, 'include/js/chart.min.js') !== false, "dashboard.php references locally hosted include/js/chart.min.js");
    assert_true(strpos($dashboard_src, 'cdn.jsdelivr.net/npm/chart.js') === false, "dashboard.php has eliminated external Chart.js CDN dependency");

} finally {
    // Clean up test meter reading records
    if (!empty($test_mr_ids)) {
        $mr_id_list = implode(',', array_map('intval', $test_mr_ids));
        mysqli_query($connection, "DELETE FROM tbl_meter_reading_details WHERE meter_reading_id IN ($mr_id_list)");
        mysqli_query($connection, "DELETE FROM tbl_meter_readings WHERE id IN ($mr_id_list)");
    }

    // Clean up test products
    if (!empty($test_prod_ids)) {
        $p_id_list = implode(',', array_map('intval', $test_prod_ids));
        mysqli_query($connection, "DELETE FROM tbl_lubricant_purchases WHERE product_id IN ($p_id_list)");
        mysqli_query($connection, "DELETE FROM tbl_lubricant_products WHERE id IN ($p_id_list)");
    }
}

exit(print_suite_summary('TC-DSH-01'));
