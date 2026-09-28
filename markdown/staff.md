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
  `weekly_off` VARCHAR(16) NOT NULL DEFAULT 'Friday',-- Assigned weekly holiday day (Friday, Sunday, etc.)
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

## 3. Experience & Weekly Off Field Specification

- **Experience Field**:
  - `experience` (`VARCHAR(255) DEFAULT NULL`): Optional work experience details.
  - Multi-line `<textarea name="experience">` in Add/Edit forms.
- **Weekly Off / Holiday**:
  - `weekly_off` (`VARCHAR(16) NOT NULL DEFAULT 'Friday'`): Designates which day of the week is the employee's weekly holiday (e.g. Friday, Sunday, Monday).
  - Selected during employee creation (`staff/add-staff.php`) and editable in (`staff/edit-staff.php`).
  - Rendered with an umbrella-beach badge in the Staff List (`staff/staff-list.php`).
  - On attendance marking (`staff/attendance-list.php`), staff whose `weekly_off` matches the attendance date automatically default to `Holiday` status.

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
| `staff/staff-list.php` | List of all employees with designation, shift, weekly off day, salary, and phone |
| `staff/add-staff.php` | Form to create a staff record with weekly off day, optional experience, and guarantor |
| `staff/edit-staff.php` | Form to update staff profile, weekly off day, salary, experience, and guarantor |
| `staff/staff-roles-list.php` | CRUD management for employee designations / job titles (`tbl_staff_roles`) |
| `staff/attendance-list.php` | Daily employee attendance tracking with `Present`, `Late`, `Holiday`, `Leave`, `Absent` |
| `staff/leave-setup.php` | Global Paid Leave Policy setup page (Option A: 2 days/month default across all staff) |
| `staff/salary-calculator.php` | Monthly wage & payroll calculator based on attendance with 1-click Cash Disbursement modal |
| `staff/process-salary-payment.php` | AJAX controller handling cash payment recording and soft-delete reversions |
| `reports/staff-salary-report.php` | Official Staff Salary Report & payment audit log in the Reports section |
| `staff/salary-payment-history.php` | Backward-compatible redirect forwarder to `reports/staff-salary-report.php` |
| `staff/generate-pdf-salary-receipt.php` | Printable cash salary disbursement voucher with attendance breakdown & dual signatures |
| `include/salary_payment_helper.php` | Centralized business logic for calculation, voucher numbering, duplicate prevention, and soft deletes |
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
  - Weekly Off / Holiday: `<i class="fas fa-umbrella-beach mr-1 text-info"></i>`
  - Leave Setup: `<i class="fas fa-calendar-alt mr-1"></i>`
  - Guarantor Section: `<i class="fas fa-user-shield mr-2 text-primary"></i>`
  - Add New Staff: `<i class="fas fa-plus mr-1"></i> Add New Staff`
  - Save Staff: `<i class="fas fa-save mr-1"></i> Save Staff`
  - Cancel: `<i class="fas fa-times mr-1"></i> Cancel`
  - Delete Staff: `<i class="fas fa-trash-alt text-danger"></i>`
  - Pay Cash: `<i class="fas fa-money-bill-wave mr-1"></i> Pay Cash`
  - Staff Salary Report: `<i class="fas fa-file-invoice-dollar mr-1 text-success"></i> Staff Salary Report`
  - Print Voucher: `<i class="fas fa-print mr-1"></i> Print Voucher`

---

## 7. Attendance-Based Cash Salary Disbursement & Voucher System

### Business Rules & Constraints:
1. **Attendance-Based Days Paid**: Salary is paid based on physical attendance days, weekly holiday, and paid leaves according to the global policy:
   $$\text{Paid Leaves Granted} = \min(\text{Leaves Taken}, \text{Global Allowed Limit})$$
   $$\text{Paid Days} = \text{Present} + \text{Late} + \text{Weekly Holiday} + \text{Paid Leaves Granted}$$
   $$\text{Unpaid Days} = \text{Absent} + \max(0, \text{Leaves Taken} - \text{Paid Leaves Granted})$$
   $$\text{Calculated Salary} = \text{Paid Days} \times \text{Daily Wage Rate}$$
2. **Weekly Holiday Policy**:
   - Each staff member has a chosen weekly off day (e.g. Friday, Sunday) assigned in `tbl_staff.weekly_off`.
   - On that day, attendance status is marked as `Holiday`.
   - `Holiday` status is considered a paid rest day and is added to `Paid Days`.
3. **Leave Setup Policy (Option A - Global Pump Policy)**:
   - Configurable pump-wide allowance: default is **2 paid leaves per month** for all employees.
   - Configurable in `staff/leave-setup.php` and stored in `tbl_settings.global_monthly_paid_leaves`.
   - When an employee takes leaves up to the monthly quota, they are fully paid (`Paid Leaves`).
   - If an employee takes excess leaves beyond the quota, the excess days are unpaid (not added to `Paid Days`).
4. **No Advance Deductions**: Staff are not granted salary advances; no advance deductions are factored into payroll.
5. **100% Cash Only**: Payment mode is strictly cash at the pump counter (no bank transfers, cheques, or digital wallets).
6. **Voucher Identification**: Every disbursement automatically generates a unique sequential voucher code formatted as:
   `SAL-YYYYMM-XXXX` (e.g. `SAL-202610-0001`).
7. **Double Payment Prevention**: An atomic transaction with `FOR UPDATE` row lock and indexed lookup prevents paying the same employee twice for the same calendar month/year.
8. **Soft Delete Reversion**: In the event of a payroll error, authorized managers can revert a payment back to `Unpaid` status via soft-deletion (`deleted_at = NOW()`).

### Database Schema (`tbl_staff_salary_payments`):
```sql
CREATE TABLE IF NOT EXISTS `tbl_staff_salary_payments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `voucher_no` VARCHAR(64) NOT NULL,
  `staff_id` INT(11) NOT NULL,
  `salary_month` INT(2) NOT NULL,
  `salary_year` INT(4) NOT NULL,
  `days_worked` INT(11) NOT NULL DEFAULT 0,
  `daily_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `paid_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_date` DATE NOT NULL,
  `payment_mode` VARCHAR(32) NOT NULL DEFAULT 'Cash',
  `payment_status` ENUM('Paid') NOT NULL DEFAULT 'Paid',
  `remarks` VARCHAR(255) DEFAULT NULL,
  `paid_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_staff_period` (`staff_id`, `salary_year`, `salary_month`),
  KEY `idx_voucher` (`voucher_no`),
  KEY `idx_payment_date` (`payment_date`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

