# Credit Sale Reading Module Complete Documentation (`markdown/credit_sales.md`)

## 1. Overview
The **Credit Sale Reading** module in the Petrol Pump Management System (PPMS) tracks fuel sales dispensed on credit across different voucher slip types (`Permanent Slip`, `Balanced Slip`, `Temporary Slip`), records customer vehicles and auto-resolves customer accounts, validates fuel quotas, supports real-time loan petrol status indicators (`Giving` vs `Received`), and provides daily consolidated statements with itemized modal breakdowns, edit/delete capabilities, and A4 PDF statement exports.

---

## 2. Database Schema

All credit sale records are stored in the existing table `tbl_meter_reading_credit_sales`, supporting both standalone entries (`meter_reading_id = 0`) and legacy shift entries:

```sql
CREATE TABLE IF NOT EXISTS `tbl_meter_reading_credit_sales` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `meter_reading_id` INT(11) NOT NULL DEFAULT 0,
  `nozzle_id` INT(11) NOT NULL,                        -- Attached nozzle ID
  `slip_date` DATE NOT NULL,
  `shift_id` INT(11) NOT NULL DEFAULT 0,              -- Attached station shift ID (tbl_shifts)
  `slip_no` VARCHAR(64) NOT NULL,
  `slip_type` ENUM('Permanent Slip','Balanced Slip','Temporary Slip') NOT NULL DEFAULT 'Permanent Slip',
  `account_number` VARCHAR(128) NOT NULL,              -- Customer ID
  `vehicle_number` VARCHAR(64) NOT NULL,
  `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,        -- Gross fuel value
  `charge_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- Amount billable to customer
  `cash_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `issue_quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `balance_1` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `balance_2` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `wasoli` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `is_returned` TINYINT(1) NOT NULL DEFAULT 0,         -- 0 = Giving (Loan Petrol), 1 = Received (Settled)
  `returned_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_meter_reading_id` (`meter_reading_id`),
  KEY `idx_slip_date` (`slip_date`),
  KEY `idx_shift_id` (`shift_id`),
  KEY `idx_slip_no` (`slip_no`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 3. Core Business Rules & Validations

### 1. Mandatory Slip Number
- `Slip No` (`credit_slip_no[]`) is strictly **mandatory** for every credit slip entered.
- The system prevents saving if any slip number is empty.

### 2. The 3 Slip Types & Received Check Logic
1. **Permanent Slip** (`Permanent Slip` - Default):
   - Standard credit bill. Customer owes the full dispensed fuel value.
   - Calculation is strictly on **QTY**:
     $$\text{charge\_amount} = \text{quantity} \times \text{rate}$$
   - `Tmp. Receive` is disabled/0.
2. **Balanced Slip** (`Balanced Slip`):
   - Customer collects fuel against an advance deposit or previously settled voucher.
   - Dispensed fuel volume is recorded for tank inventory reconciliation, but customer charge is:
     $$\text{charge\_amount} = \mathbf{Rs.\;0.00}$$
3. **Temporary Slip** (`Temporary Slip`):
   - Fuel loaned on a temporary chit/slip.
   - Features a **`[ ] Received`** checkbox with dynamic status:
     - **When NOT Checked (`is_returned = 0`) &mdash; "We are Giving"**:
       - The pump is **giving** loan petrol to the customer.
       - Badge: `<span class="badge badge-warning text-dark"><i class="fas fa-hand-holding mr-1"></i> Giving (Loan Petrol)</span>`
       - The customer owes this amount:
         $$\text{charge\_amount} = \text{effective\_qty} \times \text{rate}$$
       - In Customer Report: Classified as **Debit** under *"Giving Tmp. Receive (Loan Petrol Given — Must Collect)"*.
     - **When Checked (`is_returned = 1`) &mdash; "We Received"**:
       - The pump **received** the loan settlement from the customer.
       - Badge: `<span class="badge badge-success text-white"><i class="fas fa-check-circle mr-1"></i> Received (Settled)</span>`
       - Customer owes nothing for this slip:
         $$\text{charge\_amount} = \mathbf{Rs.\;0.00}$$
       - In Customer Report: Classified as **Credit** under *"Received Tmp. Receive (Loan Petrol Received / Settled)"*.

### 3. Vehicle Autocomplete & Customer Resolution
- The `Vehicle No` input is backed by `<datalist id="registeredVehiclesList">` querying active vehicles from `tbl_customer_vehicles`.
- When a user types or selects a vehicle number:
  - The system automatically retrieves the owner `customer_id` and populates the `Account No` field in read-only mode (`background-color: #e9ecef`).
  - An inline badge displays the customer's name and authorized fuel limit quota in Litres.
  - If the requested quantity exceeds the quota, a warning badge is displayed: `<i class="fas fa-exclamation-circle mr-1"></i>Exceeds quota ([Limit] Ltr)`.

### 4. Credit Rate Auto-Binding
- Selecting a nozzle automatically fetches the fuel item's credit rate (`tbl_items.credit_rate`).
- If credit rate is `0.00` or unset, it falls back to the standard cash rate (`tbl_items.cash_rate`).

---

## 4. CRUD Workflow & Navigation

### 1. Navigation Menu
- Located under **Transactions $\rightarrow$ Credit Sale Reading** in [`include/navbar.php`](../include/navbar.php).
- Permission module: `'credit_sales'` (inherits fallback from `'meter_readings'`).

### 2. Daily Consolidated List (`credit-sales/credit-sales-list.php`)
- Grouped by `slip_date` and `shift_id` showing shift-by-shift daily totals:
  - **Date** (Clickable to edit)
  - **Shift** (Primary gradient badge showing shift name, e.g. Morning, Evening, Night)
  - **Total Slips** (Count of vouchers)
  - **Total Dispensed Litres**
  - **Gross Fuel Amount (Rs.)**
  - **Total Billable Charge (Rs.)**
  - **Giving Loan Fuel** (Rs. owed by customers on temporary slips)
  - **Received Fuel** (Rs. settled on temporary slips)
  - **Actions**:
    - **PDF Statement**: Opens print-ready A4 daily credit statement filtered by shift.
    - **Delete**: SweetAlert2 soft-delete for slips of that specific date & shift.

### 3. Add Credit Sales (`credit-sales/add-credit-sale.php`)
- Standalone form allowing multi-row voucher entries for any date and shift.
- Header card includes **Sale Date** and **Shift** select dropdown (populated from active shifts in `tbl_shifts`).
- Consistent **`+ Add Another Row`** buttons present at both the top right of the card header and bottom left of the table.
- **Horizontal Scrolling & Full Column Visibility**: Table configured with `min-width: 1960px;` in an overflow-x responsive container with custom scrollbar, ensuring complete visibility of all inputs without truncation.
- **Standardized Column Ordering**:
  1. `Nozzle` (Select nozzle)
  2. `Slip Type` (Permanent / Balanced / Temporary radio buttons + Received checkbox)
  3. `Slip No *` (Mandatory slip identifier)
  4. `Vehicle No` (Autocomplete linked to registered vehicles)
  5. `Account No` (Auto-resolved customer ID)
  6. `Item` (Fuel item name)
  7. `Qty` (Dispensed volume in Litres)
  8. `Sale Rate` (Per-litre credit price)
  9. `Fuel Amt` (Gross fuel value)
  10. `Charge Amt` (Total billable charge to customer)
  11. `Cash Rate` (Baseline price)
  12. `Issue Qty` (Voucher balance issue)
  13. `Bal 1` (Pre-balance)
  14. `Bal 2` (Post-balance)
  15. `Wasoli` (Recovered amount)
  16. `Action` (Delete row)
- Real-time calculations of Gross Amount, Charge Amount, Giving Loans, and Received Settlements.

### 4. Edit Credit Sales (Click on Date)
- To preserve a clean list layout without icon clutter, **clicking directly on the Date column** in [`credit-sales/credit-sales-list.php`](credit-sales/credit-sales-list.php) opens the edit screen: [`credit-sales/edit-credit-sale.php?date=YYYY-MM-DD&shift_id=X`](credit-sales/edit-credit-sale.php).
- Features the exact same standardized column sequence (`Nozzle` $\rightarrow$ `Slip Type` $\rightarrow$ `Slip No` $\rightarrow$ ...), horizontal scroll container, and top/bottom **`+ Add Another Row`** buttons.
- Prepopulates all slips recorded for the selected date and shift with Shift dropdown pre-selected.
- Allows editing existing slips, changing shifts, adding new rows, deleting rows, or adjusting dates atomically.

### 5. Printable A4 PDF Statement (`credit-sales/generate-pdf-credit-sale.php?date=YYYY-MM-DD&shift_id=X`)
- Professional print layout styled with deep navy `#04204e` branding.
- Station header displaying Date and Shift name, summary metric cards, itemized slip table, and authorization signatures.

---

## 5. File Architecture

| File Path | Purpose |
| :--- | :--- |
| [`credit-sales/credit-sales-list.php`](../credit-sales/credit-sales-list.php) | Daily consolidated credit sales list with metrics, date filter, modal, and action buttons |
| [`credit-sales/add-credit-sale.php`](../credit-sales/add-credit-sale.php) | Standalone credit voucher entry form with dynamic rows and vehicle lookup |
| [`credit-sales/edit-credit-sale.php`](../credit-sales/edit-credit-sale.php) | Date-based credit sales editing interface |
| [`credit-sales/generate-pdf-credit-sale.php`](../credit-sales/generate-pdf-credit-sale.php) | A4 print-ready daily credit sales settlement statement |
| [`include/deletecreditsale.php`](../include/deletecreditsale.php) | Soft-delete AJAX handler for credit sales by date or record ID |
