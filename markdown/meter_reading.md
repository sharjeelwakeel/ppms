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
- Card Sales support **all multiple dynamic entries** linked to nozzles.
- In `view-meter-reading.php` and `generate-pdf-meter-reading.php`, ALL card sales records associated with the meter reading ID are looped and rendered in a clean table format:
  - Columns: `#`, `Nozzle`, `Card Machine`, `Batch No`, `No. of Cards`, `Amount (Rs.)`, `Service Charges (Rs.)`, `Net Amount (Rs.)`.
  - Footer row summarizes Total Card Sale Amount.

### 3. Multi-Entry Credit Sales per Nozzle (View & PDF Export)
- Credit Sales support **all multiple dynamic entries** linked to nozzles.
- In `view-meter-reading.php` and `generate-pdf-meter-reading.php`, ALL credit sales records associated with the meter reading ID are looped and rendered in a clean table format:
  - Columns: `#`, `Nozzle`, `Item`, `Slip Date`, `Slip No`, `Account No`, `Vehicle No`, `Qty`, `Sale Rate`, `Amount (Rs.)`, `Cash Rate`, `Issue Qty`, `Balance 1`, `Balance 2`, `Wasoli`.
  - Footer row summarizes Total Credit Sale Amount.

### 4. Running Meter Tracking (`tbl_nozzles.start_reading` Update)
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
