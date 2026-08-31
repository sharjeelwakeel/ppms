# Staff & HR Management Module Complete Documentation (`markdown/staff.md`)

## 1. Overview
The **Staff & HR Management** module manages physical personnel (`tbl_staff`), employee guarantors/references (`tbl_staff_guarantors`), daily shift assignments, per-day salary calculations, and employee work profiles in the Petrol Pump Management System (PPMS).

---

## 2. Database Schema (`tbl_staff`)

```sql
CREATE TABLE IF NOT EXISTS `tbl_staff` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `first_name` VARCHAR(128) NOT NULL,
  `last_name` VARCHAR(128) NOT NULL,
  `role_id` INT(11) NOT NULL,                        -- Reference -> tbl_staff_roles.id
  `joining_date` DATE NOT NULL,
  `shift_id` INT(11) NOT NULL,                       -- Reference -> tbl_shifts.id
  `salary` DECIMAL(10,2) NOT NULL DEFAULT 0.00,      -- Daily salary rate
  `experience` VARCHAR(255) DEFAULT NULL,             -- Optional experience description
  `address` VARCHAR(512) DEFAULT NULL,
  `phone` VARCHAR(32) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_role_id` (`role_id`),
  KEY `idx_shift_id` (`shift_id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 3. Experience Field Specification

- **Field Name**: `experience` (`VARCHAR(255) DEFAULT NULL`)
- **Required**: **No** (Optional / Not Required field).
- **Input Control**: Multi-line `<textarea name="experience" class="form-control" rows="3" placeholder="Optional work experience details"></textarea>`.
- **Create Form (`staff/add-staff.php`)**: Renders `<textarea>` field. Stored as `NULL` if omitted or empty.
- **Edit Form (`staff/edit-staff.php`)**: Renders `<textarea>` pre-populated with stored experience. Can be modified or cleared to `NULL`.
- **List View (`staff/staff-list.php`)**: Omitted from the main summary table to keep the list clean and compact (managed via Edit Staff).

---

## 4. Guarantor / Reference Person (`tbl_staff_guarantors`)

Every staff member is linked to a guarantor / reference person:
- `name`: Guarantor full name (Required).
- `phone`: Guarantor contact phone (Required).
- `address`: Guarantor address (Optional).

```sql
CREATE TABLE IF NOT EXISTS `tbl_staff_guarantors` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `staff_id` INT(11) NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `phone` VARCHAR(32) NOT NULL,
  `address` VARCHAR(512) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_staff_id` (`staff_id`),
  CONSTRAINT `tbl_staff_guarantors_ibfk_1` FOREIGN KEY (`staff_id`) REFERENCES `tbl_staff` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 5. File Architecture

| File Path | Description |
|---|---|
| `staff/staff-list.php` | List of all employees with designation, shift, joining date, salary, phone, and guarantor |
| `staff/add-staff.php` | Form to create a staff record and linked guarantor, including optional experience input |
| `staff/edit-staff.php` | Form to update staff profile, designation, shift, salary, optional experience, and guarantor details |
| `staff/staff-roles-list.php` | CRUD management for employee designations / job titles (`tbl_staff_roles`) |
| `staff/attendance-list.php` | Daily employee attendance tracking |
| `staff/leave-setup.php` | Configurable allowed monthly/annual leaves per staff member |
| `staff/salary-calculator.php` | Monthly wage & payroll calculator based on attendance and per-day salary |
| `include/deletestaff.php` | Backend AJAX handler for soft-deleting staff members (`deleted_at = NOW()`) |
| `markdown/staff.md` | Module specification and complete documentation (this file) |

---

## 6. UI Theme & Icon Standards

- **Theme Compliance (`markdown/theme.md`)**:
  - Primary color: `#04204e` (`var(--primary-color)`).
  - Primary buttons: `var(--primary-gradient)` (`.btn-primary`).
  - Table Header (`#staffListTable thead th`): `#04204e`.
- **Icons (FontAwesome 5)**:
  - Staff Module Header: `<i class="fas fa-users mr-2 text-primary"></i>`
  - Add / Edit Staff Header: `<i class="fas fa-user-tie mr-2 text-primary"></i>`
  - Guarantor Section: `<i class="fas fa-user-shield mr-2 text-primary"></i>`
  - Add New Staff: `<i class="fas fa-plus mr-1"></i> Add New Staff`
  - Save Staff: `<i class="fas fa-save mr-1"></i> Save Staff`
  - Cancel: `<i class="fas fa-times mr-1"></i> Cancel`
  - Delete Staff: `<i class="fas fa-trash-alt text-danger"></i>`
