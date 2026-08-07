# Role-Based Permissions Module Specification (`markdown/roles_permissions.md`)

## 1. Overview
The **Role-Based Access Control (RBAC)** system allows administrators to define granular permissions per module for each role (`tbl_roles`). For every module in the Petrol Pump Management System, four discrete actions are controlled: **Show (View)**, **Add (Create)**, **Edit (Update)**, and **Delete**.

---

## 2. Database Schemas

### Roles Table (`tbl_roles`)
```sql
CREATE TABLE IF NOT EXISTS `tbl_roles` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### Role Permissions Table (`tbl_role_permissions`)
```sql
CREATE TABLE IF NOT EXISTS `tbl_role_permissions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `role_id` INT(11) NOT NULL,
  `module_slug` VARCHAR(64) NOT NULL,            -- Unique identifier for the module
  `can_show` TINYINT(1) NOT NULL DEFAULT 0,       -- 1 = Can View List / Details
  `can_add` TINYINT(1) NOT NULL DEFAULT 0,        -- 1 = Can Add New Record
  `can_edit` TINYINT(1) NOT NULL DEFAULT 0,       -- 1 = Can Edit / Update Record
  `can_delete` TINYINT(1) NOT NULL DEFAULT 0,     -- 1 = Can Delete Record
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_role_module` (`role_id`, `module_slug`),
  KEY `idx_role_id` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 3. Module Catalog

The system supports permission management across all key operational modules:

| Module Slug | Module Label | Description |
|---|---|---|
| `purchases` | Purchases & Tank Links | Purchase orders, payment disbursements, and tank allocations |
| `meter_readings` | Meter Readings | Daily shift closing meter readings, card sales & credit sales |
| `tanks` | Tanks & Dip Chart Log | Tank dip chart readings, physical balances, and dip lookups |
| `nozzles` | Nozzle Master | Nozzle configuration, attached tanks, and running meter baselines |
| `staff` | Staff Management | Fuel sales staff and sales executives |
| `shifts` | Shift Master | Operational shift schedules |
| `card_machines` | Card Machine Master | POS card terminal configuration & percentage fees |
| `items` | Items / Fuel Products | Fuel types, petrol, diesel, and cash rates |
| `banks` | Bank Masters | Bank accounts used for purchase payment sourcing |
| `roles` | Roles & Permissions | Role definitions and access matrix management |
| `users` | System Users | System user credentials and role assignments |

---

## 4. Permission Matrix UI (`roles/add-role.php` & `roles/edit-role.php`)

When creating or editing a role, a permission matrix table is rendered:

```
+------------------------+-----------+----------+-----------+------------+---------------+
| Module Name            | Show (Eye)| Add (+)  | Edit (Pencil) | Delete (X) | Select Row   |
+------------------------+-----------+----------+-----------+------------+---------------+
| Purchases & Tank Links |  [X]      |   [X]    |   [X]     |   [ ]      |  [Check All]  |
| Meter Readings         |  [X]      |   [X]    |   [X]     |   [ ]      |  [Check All]  |
| Tanks & Dip Chart Log  |  [X]      |   [X]    |   [ ]     |   [ ]      |  [Check All]  |
| ...                    |   ...     |   ...    |   ...     |   ...      |  ...          |
+------------------------+-----------+----------+-----------+------------+---------------+
```

- **Header Controls**: "Select All Global Permissions" checkbox to quickly toggle all permissions across all modules.
- **Row Controls**: "Check Row" toggle per module row.

---

## 5. Permission Enforcement & Middleware (`include/permissions.php`)

### Helper Functions

1. **`has_permission($module_slug, $action)`**:
   - Returns `true` if the logged-in user's role has permission for `$action` (`show`, `add`, `edit`, `delete`) on `$module_slug`.
   - Super Admin (`role_id = 1` or role name `Admin`) bypasses permission checks and returns `true` automatically.

2. **`check_access($module_slug, $action)`**:
   - Call at top of pages. If `has_permission()` returns `false`, redirects user to `unauthorized.php` or stops execution with an error alert.

### UI Action Control
- Buttons (e.g. Add, Edit, Delete) across list pages and details view are conditionally displayed based on `has_permission()` checks.

---

## 6. UI Theme & Icon Standards

- **Theme Compliance (`markdown/theme.md`)**:
  - Primary color: `#04204e` (`var(--primary-color)`).
  - Header: `var(--gradient-header)`.
  - Matrix Header: Deep Navy `#04204e` with white text.
- **Icons (FontAwesome 5)**:
  - Show / View: `<i class="fas fa-eye text-info"></i>`
  - Add: `<i class="fas fa-plus text-success"></i>`
  - Edit: `<i class="fas fa-edit text-warning"></i>`
  - Delete: `<i class="fas fa-trash-alt text-danger"></i>`
  - Roles & Permissions: `<i class="fas fa-user-shield"></i>`
