# Meter Reading Module Complete Documentation (`markdown/meter_reading.md`)

## 1. Overview
The **Meter Reading** module in the Petrol Pump Management System (PPMS) tracks fuel sales per nozzle (`tbl_nozzles`), records meter closing/current readings, handles multi-row Credit Sales (`tbl_meter_reading_credit_sales`) and multi-row Card Sales (`tbl_meter_reading_card_sales`), calculates net sales & monetary amounts, renders complete Card & Credit Sales tables in view & PDF reports, and updates nozzle running meter readings (`tbl_nozzles.start_reading`).

---

## 2. Database Schemas

### Header Table (`tbl_meter_readings`)

```sql
CREATE TABLE IF NOT EXISTS `tbl_meter_readings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `date` DATE NOT NULL,
  `shift_id` INT(11) NOT NULL,
  `payment_type` VARCHAR(64) DEFAULT 'Cash',
  `grand_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `remarks` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_date_shift` (`date`, `shift_id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### Nozzle Detail Table (`tbl_meter_reading_details`)

```sql
CREATE TABLE IF NOT EXISTS `tbl_meter_reading_details` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `meter_reading_id` INT(11) NOT NULL,
  `nozzle_id` INT(11) NOT NULL,
  `staff_id` INT(11) DEFAULT 0,
  `item_type` VARCHAR(128) DEFAULT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `last_reading` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `current_reading` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `sale_reading` DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- Formula: current_reading - last_reading
  `test_reading` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `net_sale` DECIMAL(12,2) NOT NULL DEFAULT 0.00,      -- Formula: sale_reading - test_reading
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,        -- Formula: net_sale * price
  `payment_type` VARCHAR(64) DEFAULT 'Cash',
  PRIMARY KEY (`id`),
  KEY `idx_meter_reading_id` (`meter_reading_id`),
  KEY `idx_nozzle_id` (`nozzle_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### Multi-Row Card Sales Table (`tbl_meter_reading_card_sales`)

```sql
CREATE TABLE IF NOT EXISTS `tbl_meter_reading_card_sales` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `meter_reading_id` INT(11) NOT NULL,
  `nozzle_id` INT(11) NOT NULL,                        -- Attached nozzle ID
  `staff_id` INT(11) DEFAULT 0,
  `card_machine_id` INT(11) NOT NULL,
  `item_id` INT(11) DEFAULT 0,
  `no_of_cards` INT(11) NOT NULL DEFAULT 1,            -- Number of card transactions
  `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,        -- Gross card sale amount
  `batch_no` VARCHAR(64) DEFAULT NULL,                 -- Machine batch / terminal slip no
  `service_charges` DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- Machine percentage charges
  `net_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,    -- Formula: amount - service_charges
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_meter_reading_id` (`meter_reading_id`),
  KEY `idx_nozzle_id` (`nozzle_id`),
  KEY `idx_card_machine_id` (`card_machine_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### Multi-Row Credit Sales Table (`tbl_meter_reading_credit_sales`)

```sql
CREATE TABLE IF NOT EXISTS `tbl_meter_reading_credit_sales` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `meter_reading_id` INT(11) NOT NULL,
  `nozzle_id` INT(11) NOT NULL,                        -- Attached nozzle ID
  `slip_date` DATE NOT NULL,
  `slip_no` VARCHAR(64) NOT NULL,
  `account_number` VARCHAR(128) NOT NULL,
  `vehicle_number` VARCHAR(64) NOT NULL,
  `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `cash_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `issue_quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `balance_1` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `balance_2` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `wasoli` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `is_returned` TINYINT(1) NOT NULL DEFAULT 0,
  `returned_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_meter_reading_id` (`meter_reading_id`),
  KEY `idx_nozzle_id` (`nozzle_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 3. Core Business Rules & Validations

### 1. Mandatory Current Reading Validation ($\text{current\_reading} \ge \text{last\_reading}$)
- **Rule**: For every nozzle, the entered `current_reading` MUST be greater than or equal to `last_reading`:
  $$\text{current\_reading} \ge \text{last\_reading}$$
- **Real-Time UI Enforcement**: If a user enters a `current_reading` less than `last_reading`, an inline red error is rendered under the input, the row is flagged red, and form submission is prevented (`e.preventDefault()`).

### 2. Multi-Entry Card Sales per Nozzle (View & PDF Export)
- Card Sales support **all multiple dynamic entries** linked to nozzles and card machines (`tbl_card_machines`).
- **4-Decimal Service Charges Calculation**:
  - Card machine commission rates support 4 decimal places precision (e.g. `0.3456%`).
  - Automatic service charges & net amount calculation:
    $$\text{service\_charges} = \text{amount} \times \left(\frac{\text{charges\_percentage}}{100}\right)$$
    $$\text{net\_amount} = \text{amount} - \text{service\_charges}$$
- In `view-meter-reading.php` and `generate-pdf-meter-reading.php`, ALL card sales records associated with the meter reading ID are looped and rendered in a clean table format:
  - Columns: `#`, `Nozzle`, `Card Machine`, `Batch No`, `No. of Cards`, `Amount (Rs.)`, `Service Charges (Rs.)`, `Net Amount (Rs.)`.
  - Footer row summarizes Total Card Sale Amount.

### 3. Multi-Entry Credit Sales, 3 Slip Types with Received Check & Vehicle Master Integration
- **Mandatory Slip No & Modal Confirm Validation**:
  - `Slip No` (`credit_slip_no[]`) is strictly **mandatory** for every credit sale row.
  - Clicking the modal **Confirm** button or submitting the form verifies that all rows have a non-empty Slip No. If missing, the modal remains open, highlights the field, focuses it, and specifies the exact line: `"Validation Error: Please enter Slip No on Line #[N] before confirming."`
  - The server verifies `!empty($c_slip_no)` before executing database insertion into `tbl_meter_reading_credit_sales`.
- **3 Slip Types Business Logic & Received Check (Giving vs Received)**:
  - **Permanent Slip** (`Permanent Slip` - Default): Billed fuel. Customer is charged based on **QTY**:
    $$\text{charge\_amount} = \text{QTY} \times \text{Sale Rate}$$
    `Tmp. Receive` is disabled/0.
  - **Balanced Slip** (`Balanced Slip`): Customer collects remaining balance fuel from a previously billed permanent slip.
    $$\text{charge\_amount} = \mathbf{Rs.\;0.00}$$
    Nominal fuel value is logged for tank reconciliation, while customer charge is Rs. 0.00.
  - **Temporary Slip** (`Temporary Slip`): Customer takes loan fuel on a temporary voucher chit without immediate payment.
    - Features a **`[ ] Received`** checkbox with real-time UI status indicator:
      - **When NOT Checked (`is_returned = 0`) &mdash; "We are Giving"**:
        - Indicates pump is **giving** loan petrol to the customer.
        - UI Badge: `<span class="badge badge-warning text-dark"><i class="fas fa-hand-holding mr-1"></i> Giving (Loan Petrol)</span>`
        - The customer has not paid yet and owes this money to the pump:
          $$\text{charge\_amount} = \text{Tmp. Receive (wasoli)} \times \text{Sale Rate}$$
        - In Customer Report & Card 2: Classified under **Debit** as **`Giving Tmp. Receive (Loan Petrol Given — Must Collect)`** and added to **TOTAL AMOUNT WE NEED TO GET (MUST COLLECT)**.
      - **When Checked (`is_returned = 1`) &mdash; "We Received"**:
        - Indicates pump **received** the loan petrol or money back from the customer (settled).
        - UI Badge: `<span class="badge badge-success text-white"><i class="fas fa-check-circle mr-1"></i> Received (Settled)</span>`
        - The customer does not owe money for this slip:
          $$\text{charge\_amount} = \mathbf{Rs.\;0.00}$$
        - In Customer Report & Card 2: Classified under **Credit** as **`Received Tmp. Receive (Loan Petrol Received / Settled)`** (does NOT add to amount to collect).
  - **Interactive 1-Click Status on Customer Report**:
    - Any loan slip can be toggled between `Mark Received` and `Undo` directly in [`reports/customer-report.php`](../reports/customer-report.php) via AJAX without page reload.
- **Vehicle Master Search & Auto-Resolved Account No (Customer ID)**:
  - `Vehicle No` is linked to the vehicle master (`tbl_customer_vehicles`) with an autocomplete datalist matching registration numbers and numeric suffixes.
  - Upon typing or picking a vehicle number, the system searches the vehicle table, retrieves the linked `customer_id`, and auto-populates `Account No` (`credit_account_number[]`) with the Customer ID in read-only mode (`readonly`, `background-color: #e9ecef`).
  - An inline confirmation badge displays the owner's Customer Name and configured fuel quota (`fuel_limit` in Litres).
- **Credit Rate Auto-Binding for Sale Rate**:
  - When selecting a Nozzle in a Credit Sale entry, the **Sale Rate** (`credit_rate[]`) is automatically populated from the attached fuel item's **Credit Rate** (`tbl_items.credit_rate`). If `credit_rate` is `0.00` or unset, it falls back to the cash rate (`tbl_items.cash_rate`).
  - The **Cash Rate** (`credit_cash_rate[]`) field is populated with the standard cash rate for comparison and accounting reconciliation.
- In `view-meter-reading.php` and `generate-pdf-meter-reading.php`, ALL credit sales records associated with the meter reading ID are looped and rendered in a clean table format:
  - Columns: `#`, `Nozzle`, `Item`, `Slip Date`, `Slip No`, `Slip Type`, `Account No (Customer ID & Name)`, `Vehicle No`, `Qty`, `Sale Rate (Credit Rate)`, `Amount (Gross Fuel Rs.)`, `Charge Amt (Billable Rs.)`, `Cash Rate`, `Issue Qty`, `Balance 1`, `Balance 2`, `Tmp. Receive`.
  - Footer row summarizes Total Fuel Value and Total Charge Amount.
- Comprehensive customer consumption ledgers and outstanding balances are accessible from **Reports $\rightarrow$ Customer Report** ([`reports/customer-report.php`](../reports/customer-report.php)).

### 4. Read-Only Baseline Meter & Running Meter Tracking (`tbl_nozzles.start_reading`)
- **Read-Only Baseline (`last_reading`)**: In [`meter-readings/add-meter-reading.php`](../meter-readings/add-meter-reading.php), the `last_reading` input field is strictly **read-only** (`readonly`, `background-color: #e9ecef; cursor: not-allowed;`) to prevent unauthorized tampering with the previous shift's baseline meter.
- **Operator Entry**: Operators enter the closing meter value into `current_reading`, which computes sales and advances the running meter.
- **Nozzle Master Lock**: In [`nozzles/edit-nozzle.php`](../nozzles/edit-nozzle.php), the **Current Reading** field is locked to read-only mode so running meter readings are exclusively updated and advanced through Meter Readings.
- Whenever a meter reading is successfully saved, the backend automatically updates each nozzle's running meter reading in `tbl_nozzles`:
  ```sql
  UPDATE tbl_nozzles SET start_reading = '$current_reading' WHERE id = '$nozzle_id';
  ```

---

## 5. CRUD Workflow & Navigation

### 1. New Meter Reading (Add)
- Accessible from the top header of [`meter-readings/meter-reading-list.php`](../meter-readings/meter-reading-list.php) and [`meter-readings/view-meter-reading.php`](../meter-readings/view-meter-reading.php).
- Permission guarded: `has_permission('meter_readings', 'add')`.

### 2. Edit Meter Reading (Click on Date)
- To preserve a clean list layout without icon clutter, **clicking directly on the Date column** in [`meter-readings/meter-reading-list.php`](../meter-readings/meter-reading-list.php) opens the edit screen: [`meter-readings/edit-meter-reading.php?id=...`](../meter-readings/edit-meter-reading.php).
- Also accessible from the action bar in [`meter-readings/view-meter-reading.php`](../meter-readings/view-meter-reading.php).
- Pre-loads all existing nozzle readings, multi-row card transactions, and multi-row credit slips.
- Enables modifying quantities, rates, staff assignments, and adding/removing card or credit slips with atomic recalculation.
- Permission guarded: `has_permission('meter_readings', 'edit')`.

### 3. Delete Meter Reading
- Accessible in the Actions column of [`meter-readings/meter-reading-list.php`](../meter-readings/meter-reading-list.php) via `<i class="fas fa-trash-alt"></i>` and in [`meter-readings/view-meter-reading.php`](../meter-readings/view-meter-reading.php).
- Triggers a JavaScript confirmation prompt before executing AJAX soft-delete via `include/deletemeterreading.php`.
- Soft-deleted records are automatically filtered out from active lists (`WHERE deleted_at IS NULL`).
- Permission guarded: `has_permission('meter_readings', 'delete')`.

---

## 6. UI, Theme & Icon Guidelines

- **Theme**: `#04204e` (`var(--primary-color)`), primary gradient `var(--primary-gradient)`, header `var(--gradient-header)`.
- **Icons (FontAwesome 5)**:
  - Add Row / New Reading: `<i class="fas fa-plus mr-1"></i>`
  - Remove Row / Delete: `<i class="fas fa-trash-alt text-danger"></i>`
  - Save / Update: `<i class="fas fa-save mr-1"></i>`
  - View Detail: `<i class="fas fa-eye"></i>`
  - Print / PDF: `<i class="fas fa-file-pdf"></i>`
  - Edit: `<i class="fas fa-edit mr-1"></i>`
