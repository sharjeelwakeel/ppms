<?php
/**
 * Test Suite: 100% Local / Offline Asset Hosting (Zero External CDN Dependency)
 * 
 * Verifies that all required vendor CSS, JS, and webfonts are hosted locally
 * within the include/ directory and that zero external CDN links remain in any
 * application page.
 */

require_once __DIR__ . '/../test_helper.php';

echo "=== Running Offline Local Asset & Zero CDN Test Suite ===\n\n";

$base_dir = realpath(__DIR__ . '/../../');

// 1. Verify existence and non-empty sizes of all local vendor assets
$required_assets = [
    'CSS - Bootstrap' => 'include/css/bootstrap.min.css',
    'CSS - FontAwesome' => 'include/css/all.min.css',
    'CSS - DataTables' => 'include/css/jquery.dataTables.min.css',
    'CSS - SweetAlert2' => 'include/css/sweetalert2.min.css',
    'CSS - Roboto Font' => 'include/css/roboto.css',
    'JS - jQuery' => 'include/js/jquery.min.js',
    'JS - Popper' => 'include/js/popper.min.js',
    'JS - Bootstrap' => 'include/js/bootstrap.min.js',
    'JS - Bootstrap Bundle' => 'include/js/bootstrap.bundle.min.js',
    'JS - DataTables' => 'include/js/jquery.dataTables.min.js',
    'JS - SweetAlert2' => 'include/js/sweetalert2.all.min.js',
    'JS - Fancybox' => 'include/js/jquery.fancybox.min.js',
    'JS - Chart.js' => 'include/js/chart.min.js',
    'Webfont - FA Solid WOFF2' => 'include/webfonts/fa-solid-900.woff2',
    'Webfont - FA Regular WOFF2' => 'include/webfonts/fa-regular-400.woff2',
    'Webfont - FA Brands WOFF2' => 'include/webfonts/fa-brands-400.woff2',
    'Webfont - Roboto WOFF2' => 'include/webfonts/roboto/roboto-v1.woff2',
];

foreach ($required_assets as $desc => $rel_path) {
    $full_path = $base_dir . '/' . $rel_path;
    assert_true(file_exists($full_path), "$desc exists at $rel_path");
    assert_true(filesize($full_path) > 1000, "$desc is valid and non-empty (>1KB, actual: " . filesize($full_path) . " bytes)");
}

// 2. Verify relative font path resolution in CSS
$fa_css = file_get_contents($base_dir . '/include/css/all.min.css');
assert_true(strpos($fa_css, '../webfonts/fa-solid-900.woff2') !== false, "all.min.css uses local relative webfonts path");

$roboto_css = file_get_contents($base_dir . '/include/css/roboto.css');
assert_true(strpos($roboto_css, '../webfonts/roboto/') !== false, "roboto.css uses local relative webfonts path");

// 3. Scan all PHP files across the entire application to verify 0 external CDN links remain
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base_dir));
$external_links = [];
$total_scanned = 0;

foreach ($files as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $path = $f->getPathname();
        // Skip tests, vendor, and generated files
        if (strpos($path, 'tests') !== false || strpos($path, 'vendor') !== false || strpos($path, '.system_generated') !== false) {
            continue;
        }
        $total_scanned++;
        $content = file_get_contents($path);
        if (preg_match_all('/<(link|script)[^>]+(href|src)=[\'"]https?:\/\/[^\'"]+[\'"][^>]*>/i', $content, $matches)) {
            $rel_path = str_replace($base_dir . DIRECTORY_SEPARATOR, '', $path);
            foreach ($matches[0] as $match) {
                $external_links[] = "$rel_path: $match";
            }
        }
    }
}

assert_true($total_scanned >= 200, "Scanned entire project surface ($total_scanned application PHP files)");
assert_true(count($external_links) === 0, "Zero external CDN link/script tags exist across the entire project");
if (!empty($external_links)) {
    echo "Found unexpected external links:\n";
    foreach ($external_links as $l) {
        echo "  - $l\n";
    }
}

// 4. Verify correct path prefixing in root vs subdirectory pages
$dashboard_content = file_get_contents($base_dir . '/dashboard.php');
assert_true(strpos($dashboard_content, 'href="include/css/bootstrap.min.css"') !== false, "Root dashboard.php uses include/ without leading ../");
assert_true(strpos($dashboard_content, 'src="include/js/bootstrap.min.js"') !== false, "Root dashboard.php uses include/ without leading ../");
assert_true(strpos($dashboard_content, 'src="include/js/chart.min.js"') !== false, "Root dashboard.php uses local chart.min.js");

$customer_list_content = file_get_contents($base_dir . '/customers/customers-list.php');
assert_true(strpos($customer_list_content, 'href="../include/css/bootstrap.min.css"') !== false, "Subdirectory customers-list.php uses ../include/");
assert_true(strpos($customer_list_content, 'src="../include/js/bootstrap.min.js"') !== false, "Subdirectory customers-list.php uses ../include/");

echo "\nAll offline local asset tests passed successfully!\n";
