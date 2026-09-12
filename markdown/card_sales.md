# Card Sale Reading Module Complete Documentation (`markdown/card_sales.md`)

## 1. Overview
The **Card Sale Reading** module in the Petrol Pump Management System (PPMS) tracks fuel sales settled via bank POS debit/credit card terminals (`tbl_card_machines`), records swipe counts, POS batch numbers, gross card amounts, automatically computes bank fee deductions with 4-decimal precision, calculates net bank receivable amounts, and provides daily consolidated statements with itemized modal breakdowns, edit/delete capabilities, and A4 PDF settlement exports.

---

## 2. Database Schema

All card transaction records are stored in `tbl_meter_reading_card_sales`, supporting standalone shift entries (`meter_reading_id = 0`) with terminal trace and revenue difference tracking:

```sql
CREATE TABLE IF NOT EXISTS `tbl_meter_reading_card_sales` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `meter_reading_id` INT(11) NOT NULL DEFAULT 0,
  `sale_date` DATE NOT NULL,                           -- Date of card transactions
  `shift_id` INT(11) NOT NULL DEFAULT 0,              -- Station shift ID (tbl_shifts)
  `staff_id` INT(11) DEFAULT 0,
  `card_machine_id` INT(11) NOT NULL,                 -- POS Machine / Bank Terminal (tbl_card_machines)
  `item_id` INT(11) DEFAULT 0,                        -- Attached fuel item
  `rate_type` ENUM('Cash','Credit') NOT NULL DEFAULT 'Cash',
  `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,        -- Gross swipe amount (Rs.)
  `difference` DECIMAL(12,2) NOT NULL DEFAULT 0.00,    -- Revenue charge difference amount
  `batch_no` VARCHAR(64) DEFAULT NULL,                 -- POS batch no
  `trace_no` VARCHAR(64) DEFAULT NULL,                 -- POS transaction trace no
  `service_charges` DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- Bank percentage fee deducted
  `net_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,    -- Net deposit receivable (amount - charges)
  `nozzle_id` INT(11) DEFAULT NULL,                    -- Attached nozzle ID
  `no_of_cards` INT(11) NOT NULL DEFAULT 1,            -- Legacy count
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sale_date` (`sale_date`),
  KEY `idx_shift_id` (`shift_id`),
  KEY `idx_nozzle_id` (`nozzle_id`),
  KEY `idx_card_machine_id` (`card_machine_id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 3. Core Business Rules & Validations

### 1. The 6 Standard Card Sale Entry Fields
Each card transaction row records exactly 6 fields:
1. **Nozzle \***: Attached fuel dispensing nozzle (`tbl_nozzles`).
2. **Machine Type \***: Bank POS terminal (`tbl_card_machines`, e.g. Meezan, HBL, Bank Alfalah).
3. **Batch No**: Terminal batch number from the POS receipt.
4. **Trace No**: Unique transaction trace number from the POS receipt.
5. **Amount (Rs.) \***: Total gross transaction swipe amount.
6. **Difference (Rs.)**: Machine revenue difference charge.

### 2. Difference & Net Bank Deposit Formulas
- **Difference Calculation**:
  When a card machine is selected or the amount changes, Difference is automatically populated from the machine's configured `revenue_charge` %:
  $$\text{difference} = \text{amount} \times \left(\frac{\text{revenue\_charge}}{100}\right)$$
  Operators can also manually adjust this difference field if necessary.
- **Bank Service Charges (Commission Fee)**:
  Computed automatically using the machine's 4-decimal POS percentage fee (`charges_percentage`):
  $$\text{service\_charges} = \text{amount} \times \left(\frac{\text{charges\_percentage}}{100}\right)$$
- **Net Bank Receivable**:
  $$\text{net\_amount} = \text{amount} - \text{service\_charges}$$

### 3. Nozzle Reading Decoupling
Physical nozzle meter counters and shift usage rely exclusively on Detail Meter Readings (`tbl_meter_reading_details` and shift closing readings). Card sales record monetary settlements and revenue differences without mutating nozzle running start readings.

### 4. Automatic Row Expansion & Fast Data Entry ("Add New Row")
- **Spreadsheet-Style Auto-Spawn**: Typing or selecting in the last row auto-spawns a new blank row.
- **Smart Pruning**: Untouched trailing blank rows are cleanly stripped prior to form validation and database insertion.

---

## 4. CRUD Workflow & Navigation

### 1. Navigation Menu
- Located under **Transactions $\rightarrow$ Card Sale Reading** in [`include/navbar.php`](../include/navbar.php).
- Permission module: `'card_sales'`.

### 2. Daily Consolidated List (`card-sales/card-sales-list.php`)
- Shows date, shift, total batch entries, gross card sales, total difference, service charges, and net bank receivable.
- **Actions**:
  - **View Breakdown Modal**: Displays itemized table with Machine Type, Batch No, Trace No, Nozzle, Amount, Difference, Bank Fee, and Net Receivable.
  - **PDF Statement**: Generates print-ready A4 statement with all 6 fields.
  - **Delete**: Soft-deletes card sales for the date/shift.
  - **Edit**: Clicking the Date link opens `edit-card-sale.php`.

### 3. Add & Edit Interfaces
- [`card-sales/add-card-sale.php`](../card-sales/add-card-sale.php) & [`card-sales/edit-card-sale.php`](../card-sales/edit-card-sale.php)
- Lean spreadsheet layout featuring the 6 standard fields: **Nozzle**, **Machine Type**, **Batch No**, **Trace No**, **Amount (Rs.)**, **Difference**, plus row deletion action.
- Live summary counters for Total Entries, Total Amount, and Total Difference.

---

## 5. Card Sale Settlement CRUD Sub-Module

In addition to day-to-day shift swipe logs, the system tracks official bank terminal batch settlements via a dedicated sub-module accessible via the **Card Settlements** button on [`card-sales/card-sales-list.php`](../card-sales/card-sales-list.php).

### 1. Database Schema (`tbl_card_sale_settlements`)
```sql
CREATE TABLE IF NOT EXISTS `tbl_card_sale_settlements` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `card_machine_id` INT(11) NOT NULL,
  `settlement_date` DATE NOT NULL,
  `batch_no` VARCHAR(64) NOT NULL,
  `no_of_cards` INT(11) NOT NULL DEFAULT 1,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `charges_percentage` DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
  `service_charges` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `net_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_card_machine_id` (`card_machine_id`),
  KEY `idx_settlement_date` (`settlement_date`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### 2. Standard Settlement Fields
1. **Machine**: Card Machine (Bank POS Terminal from `tbl_card_machines`).
2. **Batch No**: Terminal batch slip reference number.
3. **Settlement Date**: Date of settlement (defaulting to current date).
4. **No. of Cards**: Card swipe transaction count (default `1`).
5. **Amount (Rs.)**: Gross batch settled amount.
6. **Charges & Net**: Automatically computed from machine fee % ($\text{Amount} \times \frac{\text{charges\_percentage}}{100}$).

### 3. Workflows
- **List View** ([`card-sales/settlement-list.php`](../card-sales/settlement-list.php)): Full paginated DataTable, date range filter, summary metric cards (Total Settlements, Cards, Gross Amount, Net Receivable), action buttons (Edit, PDF Statement, Delete).
- **Add Settlement** ([`card-sales/add-settlement.php`](../card-sales/add-settlement.php)): Fast entry with default current date, auto-fee preview, and validation.
- **Edit Settlement** ([`card-sales/edit-settlement.php`](../card-sales/edit-settlement.php)): Prepopulated interface for modifying batch details.
- **Soft Delete** ([`include/deletesettlement.php`](../include/deletesettlement.php)): Soft delete endpoint protected by RBAC gate.
- **Printable Statement** ([`card-sales/generate-pdf-settlement.php`](../card-sales/generate-pdf-settlement.php)): A4 print-ready bank reconciliation voucher.

---

## 6. File Architecture

| File Path | Purpose |
| :--- | :--- |
| [`card-sales/card-sales-list.php`](../card-sales/card-sales-list.php) | Consolidated card sales list with metrics, date filter, itemized modal, and action buttons |
| [`card-sales/add-card-sale.php`](../card-sales/add-card-sale.php) | 6-field card sale entry form with dynamic rows and difference auto-calculation |
| [`card-sales/edit-card-sale.php`](../card-sales/edit-card-sale.php) | Card sales editing interface with prepopulated trace numbers and differences |
| [`card-sales/generate-pdf-card-sale.php`](../card-sales/generate-pdf-card-sale.php) | A4 print-ready daily card sales settlement statement |
| [`include/deletecardsale.php`](../include/deletecardsale.php) | Soft-delete AJAX handler for card sales by date or record ID |
| [`card-sales/settlement-list.php`](../card-sales/settlement-list.php) | Card Sale Settlements listing with summary cards, date filter, and action buttons |
| [`card-sales/add-settlement.php`](../card-sales/add-settlement.php) | Card Sale Settlement creation form (Machine, Batch, Date, Cards, Amount) |
| [`card-sales/edit-settlement.php`](../card-sales/edit-settlement.php) | Card Sale Settlement edit form |
| [`include/deletesettlement.php`](../include/deletesettlement.php) | Soft-delete AJAX handler for card sale settlements |
| [`card-sales/generate-pdf-settlement.php`](../card-sales/generate-pdf-settlement.php) | A4 print-ready settlement statement |
