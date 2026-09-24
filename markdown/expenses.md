# Expenses Management Module Complete Documentation (`markdown/expenses.md`)

## 1. Overview
The **Expenses Management** module tracks petrol pump operational expenditures, utility payments, maintenance costs, and equipment-specific disbursements. It provides categorised expense records (`tbl_expense_types`), daily expense entries (`tbl_expenses`), payment method tracking (Cash, Bank Transfer, Cheque, Card), and equipment-linked expenses (specifically linking dispensing nozzles via `nozzle_id`).

---

## 2. Database Schemas

### 1. Expense Categories Table (`tbl_expense_types`)

```sql
CREATE TABLE IF NOT EXISTS `tbl_expense_types` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `status` ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,            -- 1 for undeletable system categories
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_is_system` (`is_system`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### 2. Daily Expenses Table (`tbl_expenses`)

```sql
CREATE TABLE IF NOT EXISTS `tbl_expenses` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `expense_date` DATE NOT NULL,
  `expense_type_id` INT(11) NOT NULL,
  `nozzle_id` INT(11) DEFAULT NULL,                     -- Dispensing nozzle link for Nozzle Expenses
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` VARCHAR(50) NOT NULL DEFAULT 'Cash',
  `bank_id` INT(11) DEFAULT NULL,                       -- Source bank account master if non-cash
  `reference_no` VARCHAR(100) DEFAULT NULL,             -- Bill, voucher, or receipt reference
  `notes` TEXT DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_expense_date` (`expense_date`),
  KEY `idx_expense_type_id` (`expense_type_id`),
  KEY `idx_nozzle_id` (`nozzle_id`),
  KEY `idx_bank_id` (`bank_id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 3. Core Business Rules & System Invariants

### 1. Undeletable & Uneditable System Categories (`is_system = 1`)
- **Default Seed**: The system guarantees the presence of the **"Nozzle Expense"** category with `is_system = 1`.
- **UI Lock**:
  - In `expenses/expense-types-list.php`, rows with `is_system = 1` or `name = 'Nozzle Expense'` display a `<span class="badge badge-primary"><i class="fas fa-lock mr-1"></i>System</span>` badge.
  - **Edit Lock**: The Edit button is disabled with a lock icon (`<i class="fas fa-lock text-muted"></i>`); system categories cannot be modified or renamed.
  - **Delete Lock**: The Delete button is disabled with a lock icon; system categories cannot be removed.
- **Backend Gatekeepers**:
  - `expenses/expense-types-list.php` rejects POST update requests for records with `is_system = 1` (`msg=system_edit_blocked`).
  - `include/deleteexpensetype.php` verifies whether the record has `is_system = 1` or matches `Nozzle Expense`. Direct URL manipulation (e.g. `deleteexpensetype.php?id=...`) is immediately rejected with `msg=system_protected`.

### 2. Recording Expenses on Behalf of Dispensing Nozzles
When pump operators record expenses incurred by dispensing equipment (e.g. nozzle repairs, seal replacements, meter servicing, digital counter fixes, or calibration costs):

1. **Trigger Condition**:
   - The user selects **"Nozzle Expense"** (`is_system = 1`) in the Expense Category / Type dropdown (`#expense_type_id`).
2. **Dispensing Nozzle Dropdown Display**:
   - The **Dispensing Nozzle** input group (`#nozzleGroup`) dynamically slides down into view.
   - It is populated with **all active physical dispensing nozzles** from `tbl_nozzles`:
     ```sql
     SELECT n.id, n.name, t.tank_name, i.name AS item_name 
     FROM tbl_nozzles n 
     LEFT JOIN tbl_tanks t ON n.tank_id = t.id 
     LEFT JOIN tbl_items i ON n.item_id = i.id 
     WHERE n.status = 'Active' AND (n.deleted_at IS NULL OR n.deleted_at = '0000-00-00 00:00:00')
     ORDER BY n.name ASC;
     ```
   - Each option clearly indicates the nozzle name, linked fuel tank, and product (e.g. `Nozzle A (Tank 1 - Super Petrol)`).
3. **Expense Logged on Behalf of Selected Nozzle**:
   - The user selects the specific physical nozzle and inputs the expense amount.
   - Upon submission, the expense is recorded directly **on behalf of that nozzle** by storing `tbl_expenses.nozzle_id = tbl_nozzles.id`.
4. **General Expenses (Non-Nozzle)**:
   - When any other category is selected (e.g. Electricity, Office Supplies), the nozzle container is automatically hidden, cleared, and saved as `nozzle_id = NULL`.

### 3. Server-Side Relational Integrity Invariants
- **Mandatory Nozzle Rule**:
  $$\text{Expense Type} = \text{'Nozzle Expense'} \implies \text{nozzle\_id} > 0 \land \text{nozzle\_id} \in \text{tbl\_nozzles(id)}$$
- If `nozzle_id` is missing or invalid when recording a Nozzle Expense, the transaction aborts with an explicit error: `"Please select which dispensing nozzle this expense belongs to."`

### 4. Listing, Searching & Per-Nozzle Expense Reporting
- In `expenses/expenses-ajax.php`, the query executes a `LEFT JOIN tbl_nozzles n ON e.nozzle_id = n.id`.
- If an expense is linked to a nozzle, the Category column outputs:
  ```html
  <span class="badge badge-info">Nozzle Expense</span>
  <span class="badge badge-warning mt-1"><i class="fas fa-gas-pump mr-1"></i>Nozzle A</span>
  ```
- Nozzle names are included in the global search parameters (`n.name LIKE '%...%'`).
- The Expenses List filter bar includes a dedicated **Nozzle filter dropdown** to view running expenses per specific nozzle.
- **Clickable Date for Editing**: In the Expenses DataTable (`expenses/expenses-ajax.php`), the **Date** column is rendered as an interactive primary link (`<a href="edit-expense.php?id=..." class="font-weight-bold">DD-MM-YYYY</a>`). Clicking the Date directly opens the Edit Expense view. The redundant Edit icon has been removed from the Actions column to maintain a clean and uncluttered table layout.

---

## 4. File Architecture

| File Path | Description |
|---|---|
| `expenses/expenses-list.php` | List view with KPI summary cards, date range, category, and nozzle filter dropdowns |
| `expenses/expenses-ajax.php` | Server-side DataTables endpoint joining `tbl_nozzles`, handling nozzle badges, clickable date editing, and filters |
| `expenses/add-expense.php` | Expense creation form with dynamic `#nozzleGroup` visibility and validation |
| `expenses/edit-expense.php` | Expense edit form pre-populating existing `nozzle_id` and managing dynamic nozzle visibility |
| `expenses/expense-types-list.php` | Category management view with Add/Edit modal, protecting system categories (`is_system = 1`) |
| `include/deleteexpense.php` | Soft-delete endpoint for daily expense records (`deleted_at = NOW()`) |
| `include/deleteexpensetype.php` | Soft-delete endpoint for categories, enforcing `is_system` protection rules |
| `markdown/expenses.md` | Living documentation for the Expenses Management module (this file) |

---

## 5. UI, Theme & Icon Guidelines

- **Theme Compliance (`markdown/theme.md`)**:
  - Primary color: `#04204e` (`var(--primary-color)`).
  - Primary buttons: `var(--primary-gradient)` (`.btn-primary`).
  - System badge: `<span class="badge badge-primary"><i class="fas fa-lock mr-1"></i>System</span>`.
  - Nozzle badge: `<span class="badge badge-warning"><i class="fas fa-gas-pump mr-1"></i>Nozzle Name</span>`.
  - Clickable Date: Deep navy bold anchor link (`color: var(--primary-color)`).
- **Icons (FontAwesome 5)**:
  - Expenses Navigation: `<i class="fas fa-receipt"></i>`
  - Expense Categories: `<i class="fas fa-tags"></i>`
  - Record New Expense: `<i class="fas fa-plus-circle"></i>`
  - Dispensing Nozzle: `<i class="fas fa-gas-pump"></i>`
  - System Protected: `<i class="fas fa-lock"></i>`
  - Delete Expense: `<i class="fas fa-trash-alt text-danger"></i>`
