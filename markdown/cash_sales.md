# Cash Sale Reading Module Complete Documentation (`markdown/cash_sales.md`)

## 1. Overview
The **Cash Sale Reading** module in the Petrol Pump Management System (PPMS) records direct fuel cash transactions per nozzle and shift. It provides cashiers and station managers with rapid multi-row data entry where:
- The transaction date defaults to the current day (`date('Y-m-d')`).
- Selecting a physical **Nozzle** automatically identifies the attached **Fuel Type** (e.g., Petrol or Diesel) and populates the active **Cash Rate (Rs./Ltr)** from `tbl_items`.
- Dynamic bidirectional calculation ensures that entering a **Cash Amount (Rs.)** automatically calculates **Litres** dispensed, and entering **Litres** automatically calculates the **Cash Amount**.
- Shift cash collections are consolidated in a list view featuring KPI cards, multi-parameter filters (Date, Shift, Nozzle, Fuel Type), modal previews, soft-delete management, and printable A4 PDF statements.

---

## 2. Database Schema

All cash transactions are stored in `tbl_meter_reading_cash_sales`:

```sql
CREATE TABLE IF NOT EXISTS `tbl_meter_reading_cash_sales` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `meter_reading_id` INT(11) NOT NULL DEFAULT 0,
  `is_manual_override` TINYINT(1) NOT NULL DEFAULT 0,  -- 0 = System Auto, 1 = User Edited Manually
  `sale_date` DATE NOT NULL,                           -- Transaction date (default: CURRENT_DATE)
  `shift_id` INT(11) NOT NULL DEFAULT 0,              -- Active shift ID (tbl_shifts)
  `staff_id` INT(11) NOT NULL DEFAULT 0,              -- Sales staff (if applicable)
  `nozzle_id` INT(11) NOT NULL,                       -- Physical nozzle ID (tbl_nozzles)
  `item_id` INT(11) NOT NULL,                         -- Fuel product item ID (tbl_items)
  `rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,         -- Fuel cash rate per litre
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,        -- Total cash received (Rs.)
  `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,      -- Fuel litres dispensed
  `notes` VARCHAR(255) DEFAULT NULL,                  -- Optional transaction remarks
  `created_by` INT(11) DEFAULT NULL,                  -- Account ID of the creator
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,                 -- Soft-deletion timestamp
  PRIMARY KEY (`id`),
  KEY `idx_sale_date` (`sale_date`),
  KEY `idx_shift_id` (`shift_id`),
  KEY `idx_nozzle_id` (`nozzle_id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 3. Workflow & Business Rules

### 3.1 Date & Shift Binding
- The entry form initializes with today's date (`date('Y-m-d')`).
- Cashier selects the shift (`Morning`, `Evening`, etc. from `tbl_shifts`).

### 3.2 Automated Cash Sale Synchronization Engine (`include/cash_automation_helper.php`)
Physical fuel dispensed from a dispensing nozzle is measured by its **Meter Reading**.
Because credit voucher slips and card swipes represent non-cash settlement channels:
$$\mathbf{Cash\ Litres} = \max\Big(\text{Net Meter Litres} - (\text{Credit Litres} + \text{Card Litres}),\ 0\Big)$$
$$\mathbf{Cash\ Amount\ (Rs.)} = \text{round}\Big(\mathbf{Cash\ Litres} \times \text{Fuel Rate},\ 2\Big)$$

Whenever **ANY** transaction is added, updated, or deleted:
1. **Meter Reading Saved / Edited / Deleted**:
   - Computes net sales from meters and creates or updates `tbl_meter_reading_cash_sales` automatically with `notes = 'Auto-calculated from Meter Reading #...'`.
   - On deletion, soft-deletes linked auto cash records.
2. **Credit Sale Added / Edited / Deleted**:
   - If a meter reading exists for that date and shift (whether today or a past date), the system automatically updates the cash remainder in real-time.
3. **Card Sale Added / Edited / Deleted**:
   - If a meter reading exists for that date and shift, the system automatically recalculates and updates the cash remainder.

### 3.3 Interactive Conflict Detection on Manual Entry (`add-cash-sale.php`)
When a user manually adds a cash sale for a date, shift, and nozzle that already has an existing cash reading (system-generated or manual):
1. **Real-Time AJAX Check**: `ajax-check-existing-cash.php` immediately detects the existing record.
2. **Interactive SweetAlert Modal**: A prompt informs the user:
   - *"Existing Reading Detected: Nozzle X on Date & Shift already has Y Litres / Rs. Z."*
   - Gives two clear choices:
     - `[Edit Existing Reading]` $\rightarrow$ Redirects directly to `edit-cash-sale.php?id=...`.
     - `[Add Another Anyway]` $\rightarrow$ Dismisses prompt and allows manual override.
3. **Manual Override Protection**: When manually edited, `is_manual_override = 1` is set so automated background sync will never silently overwrite custom management adjustments.

### 3.4 Nozzle $\rightarrow$ Fuel Type & Rate Resolution
1. The cashier selects a nozzle from the dropdown (e.g. `Nozzle 1 (Petrol)`).
2. The `<option>` contains data attributes:
   - `data-item-id`: ID of the fuel item in `tbl_items`.
   - `data-item-name`: Fuel product name (e.g., *Petrol*, *Diesel*).
   - `data-rate`: Current cash rate per litre (`tbl_items.cash_rate`).
3. Upon selection, the UI instantly updates the **Fuel Type** badge and populates the **Rate (Rs./Ltr)** input.

### 3.5 Bidirectional Calculation
- **Amount $\rightarrow$ Litres**:
  $$\text{Litres} = \frac{\text{Amount}}{\text{Rate}}$$
  (rounded to 2 decimal places).
- **Litres $\rightarrow$ Amount**:
  $$\text{Amount} = \text{Litres} \times \text{Rate}$$
  (rounded to 2 decimal places).
- **Rate Adjustment**:
  If the operator alters the default rate, whichever value was last entered (Amount or Litres) recalculates its counterpart automatically.

### 3.6 Multi-Row Entry Pattern
- Operators can log multiple cash transactions across different nozzles/fuel items in one session using the `+ Add Another Row` button.
- A live sticky summary displays:
  - **Total Entries** (active row count).
  - **Total Litres Sold** ($\sum \text{quantity}$).
  - **Total Cash Received** ($\sum \text{amount}$).
- Submissions are atomic: wrapped in `mysqli_begin_transaction()` and `mysqli_commit()`.

---

## 4. Navigation & Role-Based Access Control (RBAC)

### 4.1 Navigation Menu
- Located under the **Transactions** dropdown in `include/navbar.php`:
  ```html
  <a class="dropdown-item" href="cash-sales/cash-sales-list.php">
      <i class="fas fa-money-bill-wave mr-1"></i> Cash Sale Reading
  </a>
  ```
- Guarded by `$showTransactions` and `has_permission('cash_sales', 'show')`.

### 4.2 Module Permission Slug
- Slug: `cash_sales`.
- Registered in `include/permissions.php` under `get_system_modules()`.
- Supports fallback inheritance to `meter_readings` so all users with meter reading access automatically possess cash sales access.

---

## 5. File Manifest

| File Path | Description |
| :--- | :--- |
| `include/cash_automation_helper.php` | Reactive bidirectional synchronization engine and conflict lookup. |
| `cash-sales/ajax-check-existing-cash.php` | AJAX endpoint returning status of existing cash readings for a nozzle and shift. |
| `include/cash_helper.php` | Self-healing migration, active nozzle/shift lookups, and metrics aggregation. |
| `include/navbar.php` | Navigation link under Transactions menu. |
| `include/permissions.php` | Permission registry & fallback mapping for `cash_sales`. |
| `cash-sales/cash-sales-list.php` | Paginated listing with KPI cards, multi-filters, quick view modal, soft delete, and `[Auto]` / `[Manual]` badges. |
| `cash-sales/add-cash-sale.php` | Multi-row cash entry form with bidirectional calculations and real-time conflict detection modal. |
| `cash-sales/edit-cash-sale.php` | Single transaction edit form with `is_manual_override = 1` tracking. |
| `cash-sales/ajax-delete-cash-sale.php` | AJAX soft delete endpoint (`deleted_at = NOW()`). |
| `cash-sales/generate-pdf-cash-sale.php` | Printable A4 statement and shift voucher with station header and signature blocks. |
| `markdown/cash_sales.md` | Complete architectural and business rule documentation. |

