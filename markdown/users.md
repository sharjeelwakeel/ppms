# System Users Module Specification (`markdown/users.md`)

## 1. Overview
The **System Users** module manages web application login accounts (`tbl_accounts`). System users are separated from physical staff members (`tbl_staff`). Role-Based Access Control (RBAC) permissions (`tbl_role_permissions`) apply exclusively to System Users based on their assigned role (`role_id`).

---

## 2. Database Schema (`tbl_accounts`)

```sql
CREATE TABLE IF NOT EXISTS `tbl_accounts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(256) NOT NULL,
  `username` VARCHAR(64) NOT NULL,
  `password` VARCHAR(255) NOT NULL,                     -- Hashed using password_hash(..., PASSWORD_BCRYPT)
  `phonenumber` VARCHAR(64) DEFAULT NULL,
  `role_id` INT(11) DEFAULT NULL,                      -- Foreign Key -> tbl_roles.id
  `type` VARCHAR(64) DEFAULT 'user',                   -- 'admin' or 'user'
  `cnicnumber` VARCHAR(64) DEFAULT NULL,
  `address` VARCHAR(512) DEFAULT NULL,
  `city` VARCHAR(64) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_username` (`username`),
  KEY `idx_role_id` (`role_id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 3. Key Separation Rules: Staff vs. Users

1. **Staff (`tbl_staff`)**: Physical employees (e.g. Sales Executives, Fuel Attendants). Handled under `staff/`. They do NOT have web login access.
2. **Users (`tbl_accounts`)**: Web system operators and administrators with login credentials (`username` + `password`). Handled under `users/`. RBAC roles and permissions apply ONLY to System Users.

---

## 4. Automatic Password Generation Rules

1. **Create User (`users/add-user.php`)**:
   - The password field is **automatically generated** when the form loads (e.g., strong random password combining uppercase, lowercase, numbers, and symbols: `Ppms#8k2M9x`).
   - A **Regenerate Password** button (`<button id="btnGenerate">`) allows instant generation of a new password.
   - Includes a **Copy Password** button and a **Show / Hide Password** toggle.

2. **Edit User (`users/edit-user.php`)**:
   - The password input is initially blank. Leaving it blank retains the existing password.
   - An interactive **"Generate New Password"** button populates the input with a new random secure password if the user wants to update/reset the password.

3. **Security**:
   - All passwords are encrypted in the database using PHP's native `password_hash($password, PASSWORD_BCRYPT)`.
   - Authentication in `index.php` verifies credentials using `password_verify($password, $user['password'])`.

---

## 5. User CRUD Architecture

| File Path | Description |
|---|---|
| `users/users-list.php` | List of all system users, showing Name, Username, Phone, Role badge, Status, and Action buttons |
| `users/add-user.php` | Form to create a system user with automatic password generation and role selection |
| `users/edit-user.php` | Form to edit user details, reassign role, or generate/update password |
| `include/deleteuser.php` | Soft-delete handler for system user accounts |
| `markdown/users.md` | Module specification and documentation (this file) |

---

## 6. UI Theme & Icon Standards

- **Theme Compliance (`markdown/theme.md`)**:
  - Primary color: `#04204e` (`var(--primary-color)`).
  - Header: `var(--gradient-header)`.
  - Password Generate Button: `linear-gradient(135deg, #17a2b8 0%, #117a8b 100%)`.
- **Icons (FontAwesome 5)**:
  - Users Module: `<i class="fas fa-users-cog"></i>`
  - Add User: `<i class="fas fa-user-plus"></i>`
  - Edit User: `<i class="fas fa-user-edit"></i>`
  - Delete User: `<i class="fas fa-trash-alt text-danger"></i>`
  - Key / Password: `<i class="fas fa-key"></i>`
  - Regenerate: `<i class="fas fa-sync-alt"></i>`
