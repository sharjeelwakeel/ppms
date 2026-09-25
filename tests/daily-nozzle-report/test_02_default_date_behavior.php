<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/nozzle_report_helper.php';

test_header('TC-NOZ-02', 'Default Date Behavior (Single Date Today)');

try {
    $today = date('Y-m-d');

    // Call report with empty date string
    $data = get_daily_nozzle_report_data($connection, 1, '', 0);

    assert_eq($data['report_date'], $today, "Report date must default strictly to today's date ($today) when omitted");
    assert_true(!empty($data['selected_nozzle']), "Nozzle #1 should be loaded");

} finally {
    // No mutation
}

exit(print_suite_summary('TC-NOZ-02'));
