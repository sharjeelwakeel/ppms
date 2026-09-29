<?php
/**
 * PPMS Hardware-Locked Licensing Engine
 * Cryptographic verification & hardware fingerprinting.
 */

if (!defined('PPMS_LICENSE_MASTER_SECRET')) {
    // Master developer encryption secret
    define('PPMS_LICENSE_MASTER_SECRET', 'PPMS_PETROL_STATION_AES256_HARDWARE_KEY_2026!#$');
}

/**
 * Retrieve physical Motherboard Serial Number on Windows
 * @return string
 */
function get_local_motherboard_serial() {
    $out = [];
    @exec('powershell -NoProfile -Command "(Get-CimInstance Win32_BaseBoard).SerialNumber"', $out);
    $serial = trim(implode('', $out));

    if (empty($serial)) {
        // Fallback via WMIC if available
        $out_wmic = [];
        @exec('wmic baseboard get serialnumber 2>NUL', $out_wmic);
        foreach ($out_wmic as $line) {
            $line = trim($line);
            if ($line !== '' && strtolower($line) !== 'serialnumber') {
                $serial = $line;
                break;
            }
        }
    }

    return strtoupper(trim($serial));
}

/**
 * Retrieve all physical network adapter MAC addresses on Windows
 * @return array
 */
function get_local_mac_addresses() {
    $out = [];
    @exec('getmac /fo csv /nh 2>NUL', $out);
    $macs = [];

    foreach ($out as $line) {
        $cols = str_getcsv($line);
        if (!empty($cols[0])) {
            $candidate = strtoupper(trim($cols[0]));
            // Valid MAC format: 6 groups of 2 hex digits separated by - or :
            if (preg_match('/^[0-9A-F]{2}([-:])[0-9A-F]{2}(\\1[0-9A-F]{2}){4}$/', $candidate)) {
                $macs[] = str_replace(':', '-', $candidate);
            }
        }
    }

    // PowerShell fallback if getmac returns empty
    if (empty($macs)) {
        $out_ps = [];
        @exec('powershell -NoProfile -Command "(Get-NetAdapter | Where-Object Status -eq \'Up\').MacAddress"', $out_ps);
        foreach ($out_ps as $line) {
            $candidate = strtoupper(trim($line));
            if (preg_match('/^[0-9A-F]{2}([-:])[0-9A-F]{2}(\\1[0-9A-F]{2}){4}$/', $candidate)) {
                $macs[] = str_replace(':', '-', $candidate);
            }
        }
    }

    return array_values(array_unique($macs));
}

/**
 * Normalize hardware identifiers for strict comparison (removes spaces, hyphens, colons)
 * @param string $id
 * @return string
 */
function normalize_hardware_id($id) {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$id));
}

/**
 * Generate an encrypted, signed license file string
 *
 * @param string $motherboard Client Motherboard Serial
 * @param string $mac Client MAC Address
 * @param string $db_password MySQL database password
 * @param string $client_name Client / Petrol Pump Name
 * @return string Formatted license file content
 */
function generate_ppms_license_string($motherboard, $mac, $db_password = '', $client_name = 'PPMS Client') {
    $payload = [
        'client_name'        => trim($client_name),
        'motherboard_serial' => trim($motherboard),
        'mac_address'        => strtoupper(trim($mac)),
        'db_password'        => (string)$db_password,
        'created_at'         => date('Y-m-d H:i:s'),
        'version'            => '1.0'
    ];

    $json_payload = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $enc_key = hash('sha256', PPMS_LICENSE_MASTER_SECRET, true);
    $iv = openssl_random_pseudo_bytes(16);

    $ciphertext = openssl_encrypt($json_payload, 'AES-256-CBC', $enc_key, OPENSSL_RAW_DATA, $iv);
    $hmac = hash_hmac('sha256', $iv . $ciphertext, $enc_key, true);

    // Envelope format: MAGIC "PPMS" (4 bytes) + IV (16 bytes) + HMAC (32 bytes) + Ciphertext
    $binary_envelope = 'PPMS' . $iv . $hmac . $ciphertext;
    $base64_data = chunk_split(base64_encode($binary_envelope), 64, "\n");

    return "-----BEGIN PPMS LICENSE-----\n" . trim($base64_data) . "\n-----END PPMS LICENSE-----\n";
}

/**
 * Decrypt and verify any license string or file content
 *
 * @param string $license_content Raw license file text or base64
 * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
 */
function decrypt_ppms_license_string($license_content) {
    if (empty($license_content)) {
        return ['success' => false, 'error' => 'Empty license content.'];
    }

    // Extract content between delimiters
    if (preg_match('/-----BEGIN PPMS LICENSE-----\s*([A-Za-z0-9+\/=\s]+)\s*-----END PPMS LICENSE-----/', $license_content, $matches)) {
        $raw_base64 = preg_replace('/\s+/', '', $matches[1]);
    } else {
        $raw_base64 = preg_replace('/\s+/', '', $license_content);
    }

    $binary = base64_decode($raw_base64, true);
    if ($binary === false || strlen($binary) < 52) { // 4 magic + 16 IV + 32 HMAC = 52 min
        return ['success' => false, 'error' => 'Invalid or corrupted license file structure.'];
    }

    $magic      = substr($binary, 0, 4);
    $iv         = substr($binary, 4, 16);
    $stored_hmac= substr($binary, 20, 32);
    $ciphertext = substr($binary, 52);

    if ($magic !== 'PPMS') {
        return ['success' => false, 'error' => 'Invalid license header signature.'];
    }

    $enc_key = hash('sha256', PPMS_LICENSE_MASTER_SECRET, true);
    $computed_hmac = hash_hmac('sha256', $iv . $ciphertext, $enc_key, true);

    // Constant-time comparison to prevent timing attacks and tampering
    if (!hash_equals($stored_hmac, $computed_hmac)) {
        return ['success' => false, 'error' => 'Tamper detected: License HMAC signature verification failed.'];
    }

    $decrypted = openssl_decrypt($ciphertext, 'AES-256-CBC', $enc_key, OPENSSL_RAW_DATA, $iv);
    if ($decrypted === false) {
        return ['success' => false, 'error' => 'Decryption failure: Invalid cipher payload.'];
    }

    $data = json_decode($decrypted, true);
    if (!is_array($data) || !isset($data['motherboard_serial']) || !isset($data['mac_address'])) {
        return ['success' => false, 'error' => 'License payload schema is invalid.'];
    }

    return [
        'success' => true,
        'data'    => $data,
        'error'   => null
    ];
}

/**
 * Verify a license file against the local machine's actual hardware
 *
 * @param string|null $license_file_path Absolute path to license.lic
 * @return array ['valid' => bool, 'db_password' => string, 'error' => string|null, 'data' => array|null]
 */
function verify_ppms_hardware_license($license_file_path = null) {
    if ($license_file_path === null) {
        $license_file_path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'license.lic';
    }

    if (!file_exists($license_file_path)) {
        return [
            'valid'       => false,
            'db_password' => '',
            'error'       => 'License file (license.lic) is missing from application root.',
            'data'        => null
        ];
    }

    $content = @file_get_contents($license_file_path);
    $decrypted = decrypt_ppms_license_string($content);

    if (!$decrypted['success']) {
        return [
            'valid'       => false,
            'db_password' => '',
            'error'       => $decrypted['error'],
            'data'        => null
        ];
    }

    $lic_data = $decrypted['data'];
    $lic_mb   = normalize_hardware_id($lic_data['motherboard_serial']);
    $lic_mac  = normalize_hardware_id($lic_data['mac_address']);

    // Detect local physical machine credentials
    $local_mb_raw = get_local_motherboard_serial();
    $local_mb     = normalize_hardware_id($local_mb_raw);

    $local_macs_raw = get_local_mac_addresses();
    $local_macs     = array_map('normalize_hardware_id', $local_macs_raw);

    // 1. Verify Motherboard Match
    if ($local_mb === '' || $lic_mb !== $local_mb) {
        return [
            'valid'       => false,
            'db_password' => '',
            'error'       => 'Hardware Mismatch: Motherboard serial does not match this license.',
            'data'        => $lic_data
        ];
    }

    // 2. Verify MAC Address Match (matches if licensed MAC is among local physical adapters)
    if (!in_array($lic_mac, $local_macs, true)) {
        return [
            'valid'       => false,
            'db_password' => '',
            'error'       => 'Hardware Mismatch: Network MAC address does not match this license.',
            'data'        => $lic_data
        ];
    }

    // Both hardware credentials match perfectly!
    return [
        'valid'       => true,
        'db_password' => (string)($lic_data['db_password'] ?? ''),
        'error'       => null,
        'data'        => $lic_data
    ];
}
