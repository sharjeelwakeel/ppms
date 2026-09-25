<?php
/**
 * Master Test Suite Runner
 * Executes all modular test suites against 'ppms_test'.
 *
 * Usage:
 *   php tests/run_all_tests.php
 *   php tests/run_all_tests.php --suite=cash-reading-sale
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/test_helper.php';

// Parse CLI options
$options = getopt('', ['suite:']);
$target_suite = $options['suite'] ?? null;

echo "============================================================\n";
echo "   PPMS AUTOMATED TEST RUNNER (ISOLATED: ppms_test)\n";
echo "============================================================\n";
echo "Active Database : " . TEST_DB_NAME . "\n";
echo "PHP Version     : " . PHP_VERSION . "\n";
echo "Date / Time     : " . date('Y-m-d H:i:s') . "\n";
echo "============================================================\n";

$base_dir = __DIR__;
$suites_to_run = [];

if (!empty($target_suite)) {
    $suite_path = $base_dir . '/' . $target_suite;
    if (is_dir($suite_path)) {
        $suites_to_run[$target_suite] = $suite_path;
    } else {
        die("ERROR: Suite folder '{$target_suite}' not found.\n");
    }
} else {
    // Scan all subdirectories in tests/
    foreach (scandir($base_dir) as $item) {
        if ($item !== '.' && $item !== '..' && is_dir($base_dir . '/' . $item)) {
            $suites_to_run[$item] = $base_dir . '/' . $item;
        }
    }
}

$total_suites_count = count($suites_to_run);
echo "Discovered Suites: {$total_suites_count}\n";

$master_passed = 0;
$master_failed = 0;

foreach ($suites_to_run as $suite_name => $suite_path) {
    echo "\n>>> RUNNING SUITE: [{$suite_name}] <<<\n";
    $test_files = glob($suite_path . '/test_*.php');
    sort($test_files);

    if (empty($test_files)) {
        echo "  (No test scripts found in {$suite_name})\n";
        continue;
    }

    foreach ($test_files as $file) {
        $filename = basename($file);
        echo "Running: {$filename} ...\n";
        
        // Execute each test in an isolated process to prevent global pollution
        $cmd = escapeshellcmd("d:\\xampp\\php\\php.exe") . " " . escapeshellarg($file);
        $output = [];
        $exit_code = 0;
        exec($cmd, $output, $exit_code);

        echo implode("\n", $output) . "\n";

        if ($exit_code === 0) {
            $master_passed++;
        } else {
            $master_failed++;
        }
    }
}

echo "\n============================================================\n";
echo "              MASTER TEST SUITE SUMMARY\n";
echo "============================================================\n";
echo "Total Test Files Executed : " . ($master_passed + $master_failed) . "\n";
echo "Successful Test Scripts   : {$master_passed}\n";
echo "Failed Test Scripts       : {$master_failed}\n";
echo "============================================================\n";

if ($master_failed > 0) {
    echo "STATUS: FAIL - Some test scripts failed.\n\n";
    exit(1);
} else {
    echo "STATUS: SUCCESS - All test scripts passed perfectly!\n\n";
    exit(0);
}
