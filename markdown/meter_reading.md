# Meter Reading Module Complete Documentation (`markdown/meter_reading.md`)

## 1. Overview
The **Meter Reading** module in the Petrol Pump Management System (PPMS) tracks daily and shift-based fuel sales per nozzle (`tbl_nozzles`), records baseline starting meter readings and closing/current meter readings, deducts testing volume, calculates net sales (in litres) & monetary amounts (Rs.), and updates each nozzle's running meter position (`tbl_nozzles.start_reading`).

Credit sales and card machine sales are managed as dedicated standalone transaction modules (see [`markdown/credit_sales.md`](credit_sales.md) and [`markdown/card_sales.md`](card_sales.md)).

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

---

## 3. Core Business Rules & Validations

### 1. Mandatory Current Reading Validation ($\text{current\_reading} \ge \text{last\_reading}$)
- **Rule**: For every nozzle, the entered `current_reading` MUST be greater than or equal to `last_reading`:
  $$\text{current\_reading} \ge \text{last\_reading}$$
- **Real-Time UI Enforcement**: If a user enters a `current_reading` less than `last_reading`, an inline red error is rendered under the input, the row is flagged red, and form submission is prevented (`e.preventDefault()`).

### 2. Core Calculation Formulas
For each nozzle row:
1. **Sale Reading (Litres)**:
   $$\text{sale\_reading} = \max(\text{current\_reading} - \text{last\_reading}, 0)$$
2. **Net Sale (Litres)**:
   $$\text{net\_sale} = \max(\text{sale\_reading} - \text{test\_reading}, 0)$$
3. **Amount (Rs.)**:
   $$\text{amount} = \text{net\_sale} \times \text{price}$$
4. **Grand Total (Rs.)**:
   $$\text{grand\_total} = \sum \text{amount}$$

### 3. Read-Only Baseline Meter & Running Meter Tracking (`tbl_nozzles.start_reading`)
- **Read-Only Baseline (`last_reading`)**: In [`meter-readings/add-meter-reading.php`](../meter-readings/add-meter-reading.php), the `last_reading` input field is strictly **read-only** (`readonly`, `background-color: #e9ecef; cursor: not-allowed;`) to prevent unauthorized tampering with the previous shift's baseline meter.
- **Operator Entry**: Operators enter the closing meter value into `current_reading`, which computes sales and advances the running meter.
- **Nozzle Master Lock**: In [`nozzles/edit-nozzle.php`](../nozzles/edit-nozzle.php), the **Current Reading** field is locked to read-only mode so running meter readings are exclusively updated and advanced through Meter Readings.
- Whenever a meter reading is successfully saved, the backend automatically updates each nozzle's running meter reading in `tbl_nozzles`:
  ```sql
  UPDATE tbl_nozzles SET start_reading = '$current_reading' WHERE id = '$nozzle_id';
  ```

### 4. Automatic Reversion on Meter Reading Deletion (`include/deletemeterreading.php`)
- When a shift meter reading is deleted:
  - The system iterates over all attached nozzles in `tbl_meter_reading_details`.
  - If the nozzle is still at the closing reading, its running meter (`tbl_nozzles.start_reading`) reverts directly back to `last_reading` (or deducts `net_sale` if subsequent transactions occurred):
    ```sql
    UPDATE tbl_nozzles 
    SET start_reading = CASE 
        WHEN ROUND(start_reading, 2) = ROUND($current_reading, 2) THEN $last_reading 
        ELSE GREATEST(start_reading - $net_sale, 0.00) 
    END 
    WHERE id = '$nozzle_id';
    ```
  - Cleans up the daily snapshot from `tbl_daily_nozzle_readings`.

---

## 4. CRUD Workflow & Navigation

### 1. New Meter Reading (Add)
- Accessible from the top header of [`meter-readings/meter-reading-list.php`](../meter-readings/meter-reading-list.php) and [`meter-readings/view-meter-reading.php`](../meter-readings/view-meter-reading.php).
- Permission guarded: `has_permission('meter_readings', 'add')`.

### 2. Edit Meter Reading (Click on Date)
- To preserve a clean list layout without icon clutter, **clicking directly on the Date column** in [`meter-readings/meter-reading-list.php`](../meter-readings/meter-reading-list.php) opens the edit screen: [`meter-readings/edit-meter-reading.php?id=...`](../meter-readings/edit-meter-reading.php).
- Also accessible from the action bar in [`meter-readings/view-meter-reading.php`](../meter-readings/view-meter-reading.php).
- Pre-loads all existing nozzle readings, allows updating test readings, prices, and closing meters with automatic recomputation.
- Permission guarded: `has_permission('meter_readings', 'edit')`.

### 3. Delete Meter Reading
- Accessible in the Actions column of [`meter-readings/meter-reading-list.php`](../meter-readings/meter-reading-list.php) via `<i class="fas fa-trash-alt"></i>` and in [`meter-readings/view-meter-reading.php`](../meter-readings/view-meter-reading.php).
- Triggers a SweetAlert2 confirmation prompt before executing AJAX soft-delete via `include/deletemeterreading.php`.
- Soft-deleted records are automatically filtered out from active lists (`WHERE deleted_at IS NULL`).
- Permission guarded: `has_permission('meter_readings', 'delete')`.

### 4. Printable PDF Report
- Accessible via [`meter-readings/generate-pdf-meter-reading.php?id=...`](../meter-readings/generate-pdf-meter-reading.php).
- Outputs an A4 printable shift statement with station header, metric summary, and itemized nozzle meters table.

---

## 5. File Architecture

| File Path | Purpose |
| :--- | :--- |
| [`meter-readings/meter-reading-list.php`](../meter-readings/meter-reading-list.php) | Meter readings table with date filter, shift labels, grand totals, and quick actions |
| [`meter-readings/add-meter-reading.php`](../meter-readings/add-meter-reading.php) | Shift closing meter entry form with automatic running meter calculation |
| [`meter-readings/edit-meter-reading.php`](../meter-readings/edit-meter-reading.php) | Edit shift meter readings and recalculate totals |
| [`meter-readings/view-meter-reading.php`](../meter-readings/view-meter-reading.php) | View read-only breakdown of shift readings and totals |
| [`meter-readings/generate-pdf-meter-reading.php`](../meter-readings/generate-pdf-meter-reading.php) | A4 PDF / print layout for shift meter readings |
| [`include/deletemeterreading.php`](../include/deletemeterreading.php) | Soft-delete AJAX handler for meter readings |

---

## 6. UI, Theme & Icon Guidelines

- **Theme**: `#04204e` (`var(--primary-color)`), primary gradient `var(--primary-gradient)`, header `var(--gradient-header)`.
- **Icons (FontAwesome 5)**:
  - Add Reading: `<i class="fas fa-plus mr-1"></i>`
  - Remove / Delete: `<i class="fas fa-trash-alt text-danger"></i>`
  - Save / Update: `<i class="fas fa-save mr-1"></i>`
  - View Detail: `<i class="fas fa-eye"></i>`
  - Print / PDF: `<i class="fas fa-file-pdf"></i>`
  - Edit: `<i class="fas fa-edit mr-1"></i>`
