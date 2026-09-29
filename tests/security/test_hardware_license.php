<?php
/**
 * Test Suite: Hardware-Locked Cryptographic Licensing System
 * PPMS (Petrol Pump Management System)
 *
 * Verifies:
 * 1. Windows hardware fingerprint extraction (Motherboard Serial & MAC).
 * 2. AES-256-CBC license encryption & HMAC-SHA256 signature generation.
 * 3. Exact hardware matching verification (Valid).
 * 4. Motherboard mismatch rejection (Access Denied).
 * 5. MAC address mismatch rejection (Access Denied).
 * 6. Cryptographic anti-tamper seal (Tampered file rejected).
 * 7. Missing license.lic handling.
 * 8. Encrypted Database Password storage and extraction.
 */

require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../include/license_helper.php';

test_header('TC-LIC-01', 'Hardware-Locked Cryptographic Licensing System');

$tmp_test_dir = __DIR__ . '/tmp_lic_tests';
if (!is_dir($tmp_test_dir)) {
    mkdir($tmp_test_dir, 0777, true);
}

try {
    // -----------------------------------------------------------------
    // Part 1: Physical Hardware Detection on Windows
    // -----------------------------------------------------------------
    $local_mb = get_local_motherboard_serial();
    $local_macs = get_local_mac_addresses();

    assert_true(!empty($local_mb), "Local Motherboard Serial successfully detected: [$local_mb]");
    assert_true(!empty($local_macs), "Local physical network MAC addresses detected: " . json_encode($local_macs));
    assert_true(count($local_macs) >= 1, "At least 1 active physical network card identified");

    $primary_mac = $local_macs[0];

    // -----------------------------------------------------------------
    // Part 2: License Generation & Decryption (Payload Integrity)
    // -----------------------------------------------------------------
    $client_name = "Al-Madina Petroleum Testing";
    $test_db_pass = "MySecretDbPass#2026!";

    $lic_content = generate_ppms_license_string($local_mb, $primary_mac, $test_db_pass, $client_name);
    assert_true(strpos($lic_content, '-----BEGIN PPMS LICENSE-----') !== false, "License content contains standard BEGIN delimiter");
    assert_true(strpos($lic_content, '-----END PPMS LICENSE-----') !== false, "License content contains standard END delimiter");

    // Decrypt and inspect
    $decrypted = decrypt_ppms_license_string($lic_content);
    assert_true($decrypted['success'], "License decrypted successfully with master key");
    assert_eq($decrypted['data']['client_name'], $client_name, "Decrypted client name matches");
    assert_eq($decrypted['data']['motherboard_serial'], $local_mb, "Decrypted Motherboard Serial matches");
    assert_eq(normalize_hardware_id($decrypted['data']['mac_address']), normalize_hardware_id($primary_mac), "Decrypted MAC address matches");
    assert_eq($decrypted['data']['db_password'], $test_db_pass, "Decrypted MySQL Database Password matches");

    // -----------------------------------------------------------------
    // Part 3: Genuine Hardware Verification (Matching PC)
    // -----------------------------------------------------------------
    $valid_lic_path = $tmp_test_dir . '/valid.lic';
    file_put_contents($valid_lic_path, $lic_content);

    $verify_valid = verify_ppms_hardware_license($valid_lic_path);
    assert_true($verify_valid['valid'], "Hardware license matches local machine physical credentials");
    assert_eq($verify_valid['db_password'], $test_db_pass, "Verified license yields correct DB password");
    assert_true($verify_valid['error'] === null, "Verified license has zero errors");

    // -----------------------------------------------------------------
    // Part 4: Mismatched Motherboard Rejection (Machine Unauthorized)
    // -----------------------------------------------------------------
    $forged_mb = "FORGED-MB-SERIAL-9999";
    $mismatch_mb_lic = generate_ppms_license_string($forged_mb, $primary_mac, $test_db_pass, "Thief Machine");
    $mismatch_mb_path = $tmp_test_dir . '/mismatch_mb.lic';
    file_put_contents($mismatch_mb_path, $mismatch_mb_lic);

    $verify_bad_mb = verify_ppms_hardware_license($mismatch_mb_path);
    assert_true(!$verify_bad_mb['valid'], "License with forged Motherboard Serial is strictly blocked");
    assert_true(strpos($verify_bad_mb['error'], 'Motherboard') !== false, "Error message explicitly identifies Motherboard mismatch");
    assert_eq($verify_bad_mb['db_password'], '', "Blocked license does not yield DB password");

    // -----------------------------------------------------------------
    // Part 5: Mismatched MAC Address Rejection (Copied to another PC)
    // -----------------------------------------------------------------
    $forged_mac = "AA-BB-CC-DD-EE-FF";
    $mismatch_mac_lic = generate_ppms_license_string($local_mb, $forged_mac, $test_db_pass, "Copied Laptop");
    $mismatch_mac_path = $tmp_test_dir . '/mismatch_mac.lic';
    file_put_contents($mismatch_mac_path, $mismatch_mac_lic);

    $verify_bad_mac = verify_ppms_hardware_license($mismatch_mac_path);
    assert_true(!$verify_bad_mac['valid'], "License with forged/copied MAC address is strictly blocked");
    assert_true(strpos($verify_bad_mac['error'], 'MAC') !== false, "Error message explicitly identifies MAC address mismatch");
    assert_eq($verify_bad_mac['db_password'], '', "Blocked license does not yield DB password");

    // -----------------------------------------------------------------
    // Part 6: Anti-Tampering Seal Check (Modified / Edited File)
    // -----------------------------------------------------------------
    // Alter a single character in base64 envelope
    $lines = explode("\n", trim($lic_content));
    $mid_line = (int)(count($lines) / 2);
    $lines[$mid_line] = ($lines[$mid_line][0] === 'A' ? 'B' : 'A') . substr($lines[$mid_line], 1);
    $tampered_content = implode("\n", $lines);

    $tampered_path = $tmp_test_dir . '/tampered.lic';
    file_put_contents($tampered_path, $tampered_content);

    $verify_tampered = verify_ppms_hardware_license($tampered_path);
    assert_true(!$verify_tampered['valid'], "Tampered license file is strictly blocked by HMAC seal");
    assert_true(strpos($verify_tampered['error'], 'Tamper') !== false || strpos($verify_tampered['error'], 'Invalid') !== false, "Error message confirms tamper detection");

    // -----------------------------------------------------------------
    // Part 7: Missing License File Handling
    // -----------------------------------------------------------------
    $non_existent_file = $tmp_test_dir . '/missing_file.lic';
    $verify_missing = verify_ppms_hardware_license($non_existent_file);
    assert_true(!$verify_missing['valid'], "Missing license.lic is strictly detected and blocked");
    assert_true(strpos($verify_missing['error'], 'missing') !== false, "Error message informs that license.lic is missing");

    // -----------------------------------------------------------------
    // Part 8: Git Exclusion Verification (.gitignore)
    // -----------------------------------------------------------------
    $gitignore_content = file_get_contents(__DIR__ . '/../../.gitignore');
    assert_true(strpos($gitignore_content, 'encryption/') !== false, ".gitignore excludes encryption/ folder");
    assert_true(strpos($gitignore_content, 'license.lic') !== false, ".gitignore excludes license.lic file");
    assert_true(strpos($gitignore_content, '*.lic') !== false, ".gitignore excludes all *.lic files");
    assert_true(strpos($gitignore_content, 'license.log') !== false, ".gitignore excludes license.log file");

} finally {
    // Clean up temporary test license files
    if (is_dir($tmp_test_dir)) {
        $files = glob($tmp_test_dir . '/*');
        foreach ($files as $f) {
            if (is_file($f)) @unlink($f);
        }
        @rmdir($tmp_test_dir);
    }
}

exit(print_suite_summary('TC-LIC-01'));
