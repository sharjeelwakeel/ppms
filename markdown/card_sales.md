# Card Sale Reading Module Complete Documentation (`markdown/card_sales.md`)

## 1. Overview
The **Card Sale Reading** module in the Petrol Pump Management System (PPMS) tracks fuel sales settled via bank POS debit/credit card terminals (`tbl_card_machines`), records swipe counts, POS batch numbers, gross card amounts, automatically computes bank fee deductions with 4-decimal precision, calculates net bank receivable amounts, and provides daily consolidated statements with itemized modal breakdowns, edit/delete capabilities, and A4 PDF settlement exports.

---

## 2. Database Schema

All card transaction records are stored in the existing table `tbl_meter_reading_card_sales`, supporting both standalone entries (`meter_reading_id = 0`) and legacy shift entries:

```sql
CREATE TABLE IF NOT EXISTS `tbl_meter_reading_card_sales` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `meter_reading_id` INT(11) NOT NULL DEFAULT 0,
  `sale_date` DATE NOT NULL,                           -- Date of card transactions
  `shift_id` INT(11) NOT NULL DEFAULT 0,              -- Attached station shift ID (tbl_shifts)
  `staff_id` INT(11) DEFAULT 0,
  `card_machine_id` INT(11) NOT NULL,                 -- POS Machine / Bank Terminal
  `item_id` INT(11) DEFAULT 0,                        -- Attached fuel item
  `no_of_cards` INT(11) NOT NULL DEFAULT 1,            -- Number of card swipes
  `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,        -- Gross swipe amount (Rs.)
  `batch_no` VARCHAR(64) DEFAULT NULL,                 -- POS batch / terminal slip no
  `service_charges` DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- Bank percentage fee deducted
  `net_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,    -- Net deposit receivable (amount - charges)
  `nozzle_id` INT(11) DEFAULT NULL,                    -- Attached nozzle ID
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_meter_reading_id` (`meter_reading_id`),
  KEY `idx_sale_date` (`sale_date`),
  KEY `idx_shift_id` (`shift_id`),
  KEY `idx_nozzle_id` (`nozzle_id`),
  KEY `idx_card_machine_id` (`card_machine_id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 3. Core Business Rules & Validations

### 1. 4-Decimal Bank Fee Precision
- Bank card machines configure service charge percentages with up to 4 decimal places (e.g. `0.3456%`).
- Selecting a card machine auto-populates the configured commission percentage.

### 2. Automatic Net Bank Deposit Calculation
For each card transaction entry:
1. **Service Charges (Bank Fee)**:
   $$\text{service\_charges} = \text{amount} \times \left(\frac{\text{charges\_percentage}}{100}\right)$$
2. **Net Bank Receivable**:
   $$\text{net\_amount} = \text{amount} - \text{service\_charges}$$

### 3. POS Terminal & Batch Tracking
- Each card entry tracks the POS machine name (e.g. Meezan Bank, HBL, Alfalah), terminal batch reference number, card count (number of swipes), and attached nozzle.

### 4. Automatic Petrol Quantity Calculation & Nozzle Synchronization
- **Dispensed Petrol Volume (Litres)**:
  $$\text{quantity (Litres)} = \frac{\text{amount (Rs.)}}{\text{fuel\_rate (Rs./Ltr)}}$$
  Where `fuel_rate` is retrieved from `tbl_items.cash_rate` for the selected nozzle's attached product.
- **Nozzle Running Meter Update (`tbl_nozzles`)**:
  - **On Add**: Automatically advances the nozzle's running meter reading:
    ```sql
    UPDATE tbl_nozzles SET start_reading = start_reading + $quantity WHERE id = '$nozzle_id';
    ```
  - **On Edit**: Atomically adjusts the nozzle's `start_reading` by reverting the previous litres and applying the new transaction litres.
  - **On Delete**: Soft-deleting card sales for a date or single swipe automatically deducts and rolls back the petrol volume (`GREATEST(start_reading - $quantity, 0.00)`) from `tbl_nozzles`.

---

## 4. CRUD Workflow & Navigation

### 1. Navigation Menu
- Located under **Transactions $\rightarrow$ Card Sale Reading** in [`include/navbar.php`](../include/navbar.php).
- Permission module: `'card_sales'` (inherits fallback from `'meter_readings'`).

### 2. Daily Consolidated List (`card-sales/card-sales-list.php`)
- Grouped by `sale_date` and `shift_id` showing shift-by-shift daily totals:
  - **Date** (Clickable to edit)
  - **Shift** (Primary gradient badge showing shift name, e.g. Morning, Evening, Night)
  - **Batches / Entries Count**
  - **Total Swipes (Cards)**
  - **Gross Card Sale (Rs.)**
  - **Service Charges (Rs.)** (Bank commission deducted)
  - **Net Bank Receivable (Rs.)**
  - **Actions**:
    - **PDF Statement**: Opens print-ready A4 daily card settlement statement filtered by shift.
    - **Delete**: SweetAlert2 soft-delete for card sales of that specific date & shift.

### 3. Add Card Sales (`card-sales/add-card-sale.php`)
- Standalone form allowing multi-row card terminal entries for any date and shift.
- Header card includes **Sale Date** and **Shift** select dropdown (populated from active shifts in `tbl_shifts`).
- **Entry Table Columns** (strictly matching the classic card sale modal inputs):
  1. **Nozzle \*** (`-- Select Nozzle --`)
  2. **Card Machine \*** (`-- Select Machine --`)
  3. **Batch No** (Optional text)
  4. **No of Cards** (Numeric swipe count, default `1`)
  5. **Amount (Rs.) \*** (Gross swipe value)
  6. **Action** (Delete row button)
- Dynamic **`+ Add Card Sale Row`** button to append additional entries.
- Real-time calculations of Total Cards and Total Gross Amount, with background auto-computation of bank commission fees and net amounts upon saving.

### 4. Edit Card Sales (Click on Date)
- To preserve a clean list layout without icon clutter, **clicking directly on the Date column** in [`card-sales/card-sales-list.php`](card-sales/card-sales-list.php) opens the edit screen: [`card-sales/edit-card-sale.php?date=YYYY-MM-DD&shift_id=X`](card-sales/edit-card-sale.php).
- Prepopulates all card transactions recorded for that date and shift across the exact same 5 input fields (`Nozzle`, `Card Machine`, `Batch No`, `No of Cards`, `Amount (Rs.)`).
- Allows updating swipe amounts, batch numbers, shifting nozzles or machines, changing shifts, adding new rows, deleting rows, or adjusting dates atomically.

### 5. Printable A4 PDF Statement (`card-sales/generate-pdf-card-sale.php?date=YYYY-MM-DD&shift_id=X`)
- Professional print layout styled with deep navy `#04204e` branding.
- Station header displaying Date and Shift name, summary metric cards, itemized terminal table, and bank deposit signatures.

---

## 5. File Architecture

| File Path | Purpose |
| :--- | :--- |
| [`card-sales/card-sales-list.php`](../card-sales/card-sales-list.php) | Daily consolidated card sales list with metrics, date filter, modal, and action buttons |
| [`card-sales/add-card-sale.php`](../card-sales/add-card-sale.php) | Standalone card machine swipe entry form with dynamic rows and automatic net calculation |
| [`card-sales/edit-card-sale.php`](../card-sales/edit-card-sale.php) | Date-based card sales editing interface |
| [`card-sales/generate-pdf-card-sale.php`](../card-sales/generate-pdf-card-sale.php) | A4 print-ready daily card sales settlement statement |
| [`include/deletecardsale.php`](../include/deletecardsale.php) | Soft-delete AJAX handler for card sales by date or record ID |
