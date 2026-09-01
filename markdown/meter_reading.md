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

### 3. Multi-Entry Credit Sales, Slip Type Radio & Vehicle Master Integration
- **Mandatory Slip No & Modal Confirm Validation**:
  - `Slip No` (`credit_slip_no[]`) is strictly **mandatory** for every credit sale row.
  - Clicking the modal **Confirm** button or submitting the form verifies that all rows have a non-empty Slip No. If missing, the modal remains open, highlights the field, focuses it, and specifies the exact line: `"Validation Error: Please enter Slip No on Line #[N] before confirming."`
  - The server verifies `!empty($c_slip_no)` before executing database insertion into `tbl_meter_reading_credit_sales`.
- **Slip Type Radio Selection (Mandatory, Default: Permanent Slip)**:
  - Each credit sale row features an inline radio button selector with 3 options:
    - `Permanent Slip` (**Default**, `checked`): Standard credit billing chit to customer account.
    - `Balanced Slip`: Slip balancing outstanding credit/advance payments.
    - `Temporary Slip`: Temporary voucher pending formal bill generation.
  - Stored in `tbl_meter_reading_credit_sales.slip_type` (`ENUM('Permanent Slip', 'Balanced Slip', 'Temporary Slip') NOT NULL DEFAULT 'Permanent Slip'`).
- **Vehicle Master Search & Auto-Resolved Account No (Customer ID)**:
  - `Vehicle No` is linked to the vehicle master (`tbl_customer_vehicles`) with an autocomplete datalist matching registration numbers and numeric suffixes.
  - Upon typing or picking a vehicle number, the system searches the vehicle table, retrieves the linked `customer_id`, and auto-populates `Account No` (`credit_account_number[]`) with the Customer ID in read-only mode (`readonly`, `background-color: #e9ecef`).
  - An inline confirmation badge displays the owner's Customer Name and configured fuel quota (`fuel_limit` in Litres).
- **Credit Rate Auto-Binding for Sale Rate**:
  - When selecting a Nozzle in a Credit Sale entry, the **Sale Rate** (`credit_rate[]`) is automatically populated from the attached fuel item's **Credit Rate** (`tbl_items.credit_rate`). If `credit_rate` is `0.00` or unset, it falls back to the cash rate (`tbl_items.cash_rate`).
  - The **Cash Rate** (`credit_cash_rate[]`) field is populated with the standard cash rate for comparison and accounting reconciliation.
  - Credit amount is computed dynamically: $\text{amount} = \text{quantity} \times \text{sale\_rate}$.
- In `view-meter-reading.php` and `generate-pdf-meter-reading.php`, ALL credit sales records associated with the meter reading ID are looped and rendered in a clean table format:
  - Columns: `#`, `Nozzle`, `Item`, `Slip Date`, `Slip No`, `Slip Type`, `Account No (Customer ID & Name)`, `Vehicle No`, `Qty`, `Sale Rate (Credit Rate)`, `Amount (Rs.)`, `Cash Rate`, `Issue Qty`, `Balance 1`, `Balance 2`, `Wasoli`.
  - Footer row summarizes Total Credit Sale Amount.

### 4. Read-Only Baseline Meter & Running Meter Tracking (`tbl_nozzles.start_reading`)
- **Read-Only Baseline (`last_reading`)**: In [`meter-readings/add-meter-reading.php`](../meter-readings/add-meter-reading.php), the `last_reading` input field is strictly **read-only** (`readonly`, `background-color: #e9ecef; cursor: not-allowed;`) to prevent unauthorized tampering with the previous shift's baseline meter.
- **Operator Entry**: Operators enter the closing meter value into `current_reading`, which computes sales and advances the running meter.
- **Nozzle Master Lock**: In [`nozzles/edit-nozzle.php`](../nozzles/edit-nozzle.php), the **Current Reading** field is locked to read-only mode so running meter readings are exclusively updated and advanced through Meter Readings.
- Whenever a meter reading is successfully saved, the backend automatically updates each nozzle's running meter reading in `tbl_nozzles`:
  ```sql
  UPDATE tbl_nozzles SET start_reading = '$current_reading' WHERE id = '$nozzle_id';
  ```

---

## 4. UI, Theme & Icon Guidelines

- **Theme**: `#04204e` (`var(--primary-color)`), primary gradient `var(--primary-gradient)`, header `var(--gradient-header)`.
- **Icons (FontAwesome 5)**:
  - Add Row: `<i class="fas fa-plus mr-1"></i>`
  - Remove Row: `<i class="fas fa-trash-alt text-danger"></i>`
  - Save Meter Reading: `<i class="fas fa-save mr-1"></i>`
  - View Detail: `<i class="fas fa-eye"></i>`
  - Print / PDF: `<i class="fas fa-file-pdf"></i>`
