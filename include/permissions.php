<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config.php';

// Auto-migrate tbl_accounts to include role_id if missing
$check_acc_role = mysqli_query($connection, "SHOW COLUMNS FROM tbl_accounts LIKE 'role_id'");
if ($check_acc_role && mysqli_num_rows($check_acc_role) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_accounts ADD COLUMN role_id INT DEFAULT NULL");
}

// Module catalog
function get_system_modules() {
    return [
        'purchases'      => 'Purchases & Tank Links',
        'meter_readings' => 'Meter Readings',
        'tanks'          => 'Tanks & Dip Chart Log',
        'nozzles'        => 'Nozzles',
        'staff'          => 'Sales Staff',
        'shifts'         => 'Shifts',
        'card_machines'  => 'Card Machines',
        'items'          => 'Items / Fuel Products',
        'banks'          => 'Bank Masters',
        'roles'          => 'Roles & Permissions',
        'users'          => 'System Users / Accounts'
    ];
}

/**
 * Check if logged-in user has permission for a specific module and action
 * @param string $module_slug (e.g. 'purchases', 'meter_readings')
 * @param string $action ('show', 'add', 'edit', 'delete')
 * @return bool
 */
function has_permission($module_slug, $action) {
    global $connection;

    if (!isset($_SESSION['loggedInUser']) || empty($_SESSION['loggedInUser'])) {
        return false;
    }

    $user_id = intval($_SESSION['loggedInUser']);

    // Fetch user details from tbl_accounts
    $user_res = mysqli_query($connection, "SELECT type, role_id FROM tbl_accounts WHERE id = '$user_id' LIMIT 1");
    if (!$user_res || !($user = mysqli_fetch_assoc($user_res))) {
        return false; // Deny if account row missing
    }

    // Super Admin bypass (if user type is 'admin' or 'Admin')
    if (strtolower(trim($user['type'] ?? '')) === 'admin') {
        return true;
    }

    $role_id = intval($user['role_id'] ?? 0);
    if ($role_id <= 0) {
        return false; // Deny if non-admin user has no role assigned
    }

    // Check if assigned role name is Admin
    $r_check = mysqli_query($connection, "SELECT name FROM tbl_roles WHERE id = '$role_id' LIMIT 1");
    if ($r_check && ($r_row = mysqli_fetch_assoc($r_check))) {
        if (strtolower(trim($r_row['name'])) === 'admin') {
            return true; // Admin role has full permissions for all modules
        }
    }

    // Map action name to column name
    $col_map = [
        'show'   => 'can_show',
        'view'   => 'can_show',
        'add'    => 'can_add',
        'create' => 'can_add',
        'edit'   => 'can_edit',
        'update' => 'can_edit',
        'delete' => 'can_delete'
    ];

    $col_name = $col_map[strtolower($action)] ?? 'can_show';

    $perm_res = mysqli_query($connection, "SELECT $col_name FROM tbl_role_permissions WHERE role_id = '$role_id' AND module_slug = '$module_slug' LIMIT 1");
    if ($perm_res && ($perm = mysqli_fetch_assoc($perm_res))) {
        return intval($perm[$col_name]) === 1;
    }

    return false; // Default deny if explicit permission row is missing or 0
}

/**
 * Enforce access check on pages. If unauthorized, redirect to unauthorized.php
 */
function check_access($module_slug, $action) {
    if (!has_permission($module_slug, $action)) {
        header('Location: ../unauthorized.php');
        exit;
    }
}
?>
