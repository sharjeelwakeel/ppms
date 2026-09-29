<?php
/**
 * PPMS Runtime License Gatekeeper
 * Enforces hardware license and delivers decrypted DB credentials.
 */

require_once __DIR__ . '/license_helper.php';

if (!function_exists('enforce_ppms_license_and_get_db_password')) {
    /**
     * Enforce machine hardware license check and return the decrypted MySQL password
     *
     * @return string Decrypted MySQL Database Password
     */
    function enforce_ppms_license_and_get_db_password() {
        // Start session if not started yet to leverage fast authorization caching
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $license_file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'license.lic';

        // 1. File existence MUST be verified on every request (takes < 0.00001s)
        if (!file_exists($license_file)) {
            unset($_SESSION['__ppms_lic_status'], $_SESSION['__ppms_lic_time'], $_SESSION['__ppms_lic_mtime'], $_SESSION['__ppms_db_pass']);
            $result = [
                'valid' => false,
                'error' => 'License file (license.lic) is missing from application root.'
            ];
        } else {
            // 2. Fast session cache check (15 minutes TTL) if license file has not been modified
            $file_mtime = filemtime($license_file);
            if (
                isset($_SESSION['__ppms_lic_status']) &&
                $_SESSION['__ppms_lic_status'] === 'valid' &&
                isset($_SESSION['__ppms_lic_time']) &&
                (time() - $_SESSION['__ppms_lic_time']) < 900 &&
                isset($_SESSION['__ppms_lic_mtime']) &&
                $_SESSION['__ppms_lic_mtime'] === $file_mtime &&
                isset($_SESSION['__ppms_db_pass'])
            ) {
                return (string)$_SESSION['__ppms_db_pass'];
            }

            // Perform physical hardware verification against root license.lic
            $result = verify_ppms_hardware_license($license_file);
            if ($result['valid']) {
                // Hardware verified successfully! Cache credentials in session
                $_SESSION['__ppms_lic_status'] = 'valid';
                $_SESSION['__ppms_lic_time']   = time();
                $_SESSION['__ppms_lic_mtime']  = $file_mtime;
                $_SESSION['__ppms_db_pass']    = $result['db_password'];
                $_SESSION['__ppms_client']     = $result['data']['client_name'] ?? 'Authorized Client';
                return (string)$result['db_password'];
            }
        }

        // License invalid or missing: write failure reason strictly to license.log (never shown on UI)
        $log_file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'license.log';
        $client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $timestamp = date('Y-m-d H:i:s');
        $error_detail = $result['error'] ?? 'Unknown hardware authorization failure';
        $log_line = "[{$timestamp}] [IP: {$client_ip}] PPMS License Lock: {$error_detail}\n";
        @file_put_contents($log_file, $log_line, FILE_APPEND);

        // Invalidate session cache and halt execution
        unset($_SESSION['__ppms_lic_status'], $_SESSION['__ppms_lic_time'], $_SESSION['__ppms_lic_mtime'], $_SESSION['__ppms_db_pass']);

        // Render clean, generic terminal/browser lockout response without revealing internal reasons
        http_response_code(403);

        $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') ||
                   (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

        if ($is_ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status'  => 'error',
                'message' => 'Software authorization required. Please contact your software administrator.'
            ]);
            exit;
        }

        // Output professional security lockout page (clean, no reasons exposed to client)
        echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PPMS - Station Protection</title>
    <style>
        body {
            background: #f4f6fb;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #2d3748;
            margin: 0;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .lock-container {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(4, 32, 78, 0.12);
            max-width: 540px;
            width: 90%;
            padding: 42px 36px;
            border-top: 6px solid #04204e;
            text-align: center;
        }
        .lock-icon {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: #ffebee;
            color: #c62828;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin-bottom: 20px;
        }
        h2 {
            margin: 0 0 12px;
            color: #04204e;
            font-size: 23px;
            font-weight: 700;
        }
        p {
            font-size: 15px;
            line-height: 1.6;
            color: #4a5568;
            margin-bottom: 20px;
        }
        .contact-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
            font-size: 14px;
            color: #4a5568;
            margin-top: 20px;
        }
        .contact-box strong {
            color: #04204e;
        }
    </style>
</head>
<body>
    <div class="lock-container">
        <div class="lock-icon">&#128274;</div>
        <h2>PPMS Station Protection</h2>
        <p>This software installation is protected and unauthorized for this machine.</p>
        <div class="contact-box">
            Please contact your <strong>software administrator</strong> for authorization.
        </div>
    </div>
</body>
</html>';
        exit;
    }
}
