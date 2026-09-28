<?php
/**
 * Redirect forwarder: Salary Payment History -> Staff Salary Report
 * Ensures 100% backward-compatibility for any bookmark or legacy link.
 */

$query_string = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
header("Location: ../reports/staff-salary-report.php" . $query_string);
exit;
