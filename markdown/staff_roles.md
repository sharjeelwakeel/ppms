# Staff Designations Module Specification (`markdown/staff_roles.md`)

## 1. Overview
Physical employees (`tbl_staff`) are assigned **Staff Designations / Job Titles** (`tbl_staff_roles`) such as *Sales Executive*, *Fuel Attendant*, *Helper*, *Accountant*, and *Manager*. Staff Designations are completely separated from **System User Permission Roles** (`tbl_roles`).

---

## 2. Database Schema (`tbl_staff_roles`)

```sql
CREATE TABLE IF NOT EXISTS `tbl_staff_roles` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,                        -- Staff designation title
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 3. Clear Distinction: System User Roles vs. Staff Designations

| Aspect | System User Permission Roles (`tbl_roles`) | Staff Designations (`tbl_staff_roles`) |
|---|---|---|
| **Target Entity** | Web System Accounts (`tbl_accounts`) | Physical Employees (`tbl_staff`) |
| **Purpose** | RBAC Module Access (Show, Add, Edit, Delete) | Employee job titles & payroll classification |
| **Web Login Access** | Yes (System users log in with username/pass) | No (Staff members do not log in) |
| **Module Management** | `roles/` (Roles & Permissions Matrix) | `staff/staff-roles-list.php` (Staff Designations) |

---

## 4. Default Staff Designations

Upon initialization, `tbl_staff_roles` is populated with default designations:
1. `Sales Executive`
2. `Fuel Attendant`
3. `Helper`
4. `Accountant`
5. `Manager`
6. `Cleaner`

---

## 5. Affected Files & Schema Updates

1. `tbl_staff.role_id`: Updated to reference `tbl_staff_roles.id`.
2. `staff/staff-list.php`: Queries `LEFT JOIN tbl_staff_roles r ON s.role_id = r.id`.
3. `staff/add-staff.php`: Populates Designation dropdown from `tbl_staff_roles`.
4. `staff/edit-staff.php`: Populates Designation dropdown from `tbl_staff_roles`.
5. `staff/attendance-list.php`: Displays staff designation from `tbl_staff_roles`.
6. `staff/salary-calculator.php`: Displays staff designation from `tbl_staff_roles`.
7. `staff/leave-setup.php`: Displays staff designation from `tbl_staff_roles`.
8. `staff/staff-roles-list.php`: Dedicated CRUD list and management for Staff Designations.
