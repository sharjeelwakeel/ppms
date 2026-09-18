# Card Sale Reading Module Complete Documentation (`markdown/card_sales.md`)

## 1. Overview
The **Card Sale Reading** module in the Petrol Pump Management System (PPMS) tracks fuel sales settled via bank POS debit/credit card terminals (`tbl_card_machines`), records swipe counts, POS batch numbers with automated machine + date + shift dependent sequential allocation, gross card amounts, automatically computes bank fee deductions with 4-decimal precision, calculates net bank receivable amounts, and provides daily consolidated statements with itemized modal breakdowns, edit/delete capabilities, and A4 PDF settlement exports.

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
  `batch_no` VARCHAR(64) DEFAULT NULL,                 -- Auto-sequenced POS batch no
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

## 3. POS Card Machine Batch Entry & Management

### 1. Manual Batch Number Entry (Operator Input)
- **Operator-Entered Field**: The `Batch No` input is an active, editable text field (removing `readonly` / auto-increment constraints). The operator types the physical POS batch number directly from the terminal report.
- **Machine Row Memory**: When an operator inputs a batch number for a specific machine, subsequent rows selected for that same machine conveniently default to the same batch number to eliminate repetitive typing, while remaining 100% editable.
- **Flexible Batch Identifiers**: Supports alphanumeric POS batch identifiers (e.g. `105`, `BATCH-01`, `9920`).
- **Database Persistence**: The entered batch number is stored directly into `tbl_meter_reading_card_sales.batch_no`.

---

### 2. Concrete Workflow Trace & Example

| Row / Action | Machine | Date | Shift | Batch No | Explanation |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Row 1** | **Meezan Bank** | 19/07/2026 | Morning | **2054** | Operator manually enters batch from Meezan slip |
| **Row 2** | **Meezan Bank** | 19/07/2026 | Morning | **2054** | Auto-carries `2054` for Meezan (editable by operator) |
| **Row 3** | **HBL POS** | 19/07/2026 | Morning | **7701** | Operator enters batch from HBL POS slip |
| **Next Shift** | **Meezan Bank** | 19/07/2026 | Evening | **2055** | Operator types new batch number for evening shift |

---

### 3. SQL Resolution Algorithm (`include/card_helper.php` $\rightarrow$ `get_shift_machine_batches()`)

1. **Step 1 — Check Existing Saved Batches for This Date & Shift**:
   ```sql
   SELECT card_machine_id, batch_no 
   FROM tbl_meter_reading_card_sales 
   WHERE sale_date = :sale_date 
     AND shift_id = :shift_id 
     AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
     AND batch_no IS NOT NULL AND TRIM(batch_no) != ''
   ORDER BY id ASC;
   ```
   *Any machine already saved preserves its assigned `batch_no`.*

2. **Step 2 — Find Maximum Batch Number Prior to This Shift**:
   ```sql
   SELECT MAX(CAST(batch_no AS UNSIGNED)) AS max_prev_batch
   FROM tbl_meter_reading_card_sales 
   WHERE (
       sale_date < :sale_date 
       OR (sale_date = :sale_date AND shift_id < :shift_id)
   )
   AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
   AND batch_no IS NOT NULL AND TRIM(batch_no) != ''
   AND batch_no REGEXP '^[0-9]+$';
   ```

3. **Step 3 — Sequentially Assign Unassigned Machines**:
   ```php
   $current_counter = max($max_prev_batch, $max_assigned_in_current_shift);
   foreach ($active_machines as $m) {
       if (isset($existing_batches[$m['id']])) {
           $batches[$m['id']] = $existing_batches[$m['id']];
       } else {
           $current_counter++;
           $batches[$m['id']] = strval($current_counter);
       }
   }
   ```

---

## 4. Core Business Rules & Validations

### 1. The 6 Standard Card Sale Entry Fields
Each card transaction row records exactly 6 fields:
1. **Nozzle \***: Attached fuel dispensing nozzle (`tbl_nozzles`).
2. **Machine Type \***: Bank POS terminal (`tbl_card_machines`, e.g. Meezan, HBL, Bank Alfalah).
3. **Batch No (Auto)**: Auto-sequenced read-only POS batch number.
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

> [!IMPORTANT]
> **Revenue Charge Exclusion Rule**:
> Revenue charges (`difference`) represent internal station revenue markup and are **STRICTLY NOT INCLUDED in the Net Settlement Total**. The Net Settlement Total accounts **ONLY for Bank Service Charges** deducted from Pure Sales ($\text{Net Total} = \text{Pure Sales} - \text{Bank Service Charges}$). Physical bank terminal settlement slips reconcile purely card sales less bank commission fees.

### 3. Nozzle Reading Decoupling
Physical nozzle meter counters and shift usage rely exclusively on Detail Meter Readings (`tbl_meter_reading_details` and shift closing readings). Card sales record monetary settlements and revenue differences without mutating nozzle running start readings.

### 4. Automatic Row Expansion & Fast Data Entry ("Add New Row")
- **Spreadsheet-Style Auto-Spawn**: Typing or selecting in the last row auto-spawns a new blank row.
- **Smart Pruning**: Untouched trailing blank rows are cleanly stripped prior to form validation and database insertion.

---

## 5. CRUD Workflow & Navigation

### 1. Navigation Menu
- Located under **Transactions $\rightarrow$ Card Sale Reading** in [`include/navbar.php`](../include/navbar.php).
- Permission module: `'card_sales'`.

### 2. Daily Consolidated List (`card-sales/card-sales-list.php`)
- Shows date, shift, total batch entries, gross card sales, and total difference (bank service charges and net bank receivables are managed under Card Sale Settlements).
- **Actions**:
  - **View Breakdown Modal**: Displays itemized table with Machine Type, Batch No, Trace No, Nozzle, Amount, and Difference.
  - **PDF Statement**: Generates print-ready A4 statement with entries, gross amount, and difference.
  - **Delete**: Soft-deletes card sales for the date/shift.
  - **Edit**: Clicking the Date link opens `edit-card-sale.php`.

### 3. Add & Edit Interfaces
- [`card-sales/add-card-sale.php`](../card-sales/add-card-sale.php) & [`card-sales/edit-card-sale.php`](../card-sales/edit-card-sale.php)
- Lean spreadsheet layout featuring the 6 standard fields: **Nozzle**, **Machine Type**, **Batch No (Auto)**, **Trace No**, **Amount (Rs.)**, **Difference**, plus row deletion action.
- Live summary counters for Total Entries, Total Amount, and Total Difference.

---

## 6. Card Sale Settlement CRUD Sub-Module

In addition to day-to-day shift swipe logs, the system tracks official bank terminal batch settlements via a dedicated sub-module accessible via the **Card Settlements** button on [`card-sales/card-sales-list.php`](../card-sales/card-sales-list.php).

### 1. Database Schema (`tbl_card_sale_settlements`)
```sql
CREATE TABLE IF NOT EXISTS `tbl_card_sale_settlements` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `card_machine_id` INT(11) NOT NULL,
  `settlement_date` DATE NOT NULL,
  `shift_id` INT(11) NOT NULL DEFAULT 0,
  `batch_no` VARCHAR(64) NOT NULL,
  `no_of_cards` INT(11) NOT NULL DEFAULT 1,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `charges_percentage` DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
  `service_charges` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `revenue_percentage` DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
  `revenue_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `net_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_card_machine_id` (`card_machine_id`),
  KEY `idx_settlement_date` (`settlement_date`),
  KEY `idx_shift_id` (`shift_id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### 2. Standard Settlement Fields & Data Dictionary
1. **Settlement Date**: Date of settlement (defaulting to current date).
2. **Shift**: Mandatory Station Shift (`tbl_shifts`), populated with active shifts (e.g., Morning and Evening).
3. **Card Machine**: Bank POS Terminal from `tbl_card_machines`.
4. **Batch No**: Operator-entered text field with auto-suggesting `<datalist>` of active batches logged on that machine/shift/date.
5. **No. of Cards**: Card swipe count (auto-filled from matched swipes, verifiable by operator).
6. **Total Amount (Rs.) (`amount`)**: Pure gross card sales entered by operator from the physical terminal slip (without fee deduction and without revenue surcharge).
7. **Bank Service Charges (`charges_percentage` & `service_charges`)**: POS terminal processing commission percentage and calculated fee amount ($\text{Total Amount} \times \frac{\text{charges\_percentage}}{100}$).
8. **Net Settlement Amount (`net_amount`)**: Actual deposited net balance from the POS terminal slip ($\text{Total Amount} - \text{Bank Service Charges}$).
9. **Revenue Surcharge (`revenue_percentage` & `revenue_amount`)**: Station card surcharge percentage and calculated revenue amount from batch ($\text{Total Amount} \times \frac{\text{revenue\_percentage}}{100}$), preserved independently and never subtracted from or added into the bank net settlement.

### 2.1 Settlement List Data Table Specifications (`settlement-list.php`)
| # | Column Header | DB Field / Expression | Format / Styling |
|:---|:---|:---|:---|
| 1 | `#` | Row counter | Centered |
| 2 | `Date` | `s.settlement_date` | Date link (`d-m-Y`) |
| 3 | `Shift` | `sh.name` | Badge pill |
| 4 | `Machine (Bank Terminal)` | `cm.name` | Bold text with POS terminal icon |
| 5 | `Batch No` | `s.batch_no` | Centered monospace text |
| 6 | `Cards` | `s.no_of_cards` | Centered info badge |
| 7 | `Total Amount (Rs.)` | `s.amount` | Bold navy pure sales (`Rs. 200.00`) |
| 8 | `Fee %` | `s.charges_percentage` | Centered muted monospace rate (`0.3000%`) |
| 9 | `Charges (Rs.)` | `-s.service_charges` | Red bold fee deduction (`-Rs. 0.60`) |
| 10 | `Net Amount (Rs.)` | `s.net_amount` | Green bold net deposit (`Rs. 199.40`) |
| 11 | `Revenue %` | `s.revenue_percentage` | Centered info monospace rate (`0.2000%`) |
| 12 | `Revenue (Rs.)` | `+s.revenue_amount` | Info blue bold station surcharge (`+Rs. 0.40`) |
| 13 | `Actions` | Edit / Delete | Deep navy edit & red delete buttons |

> [!NOTE]
> **Mandatory Revenue Percentage Display in Settlement List**:
> The Settlement List explicitly notes and displays the **Revenue %** (`s.revenue_percentage`, e.g. `0.2000%`) in a dedicated column alongside the calculated **Revenue (Rs.)** (`s.revenue_amount`, e.g. `+Rs. 0.40`). Both values are held permanently in `tbl_card_sale_settlements` and are completely distinct from the bank's processing fee percentage (`Fee %` / `charges_percentage`). A settlement rate note banner is displayed at the top of the table reminding operators that Revenue Surcharge is an internal station markup and is never deducted from or added into the bank net deposit ($\text{Net Amount} = \text{Total Amount} - \text{Charges}$).

### 3. Dynamic Batch Calculation Breakdown & Separation of Revenue Charges

> [!IMPORTANT]
> **Revenue Charge Exclusion Rule in Net Settlement**:
> Station **Revenue Charges (`difference`) are strictly NOT included** in the Net Settlement Total. The Net Settlement Total consists **ONLY of Pure Sales minus Bank Service Charges**:
> $$\text{Expected Net Total} = \text{Pure Sales Amount} - \text{Bank Service Charges}$$
> The physical bank terminal settlement report only reconciles fuel card swipes less the bank's processing commission. Station revenue charges remain an independent station accounting metric and are not bundled into terminal batch totals.

When entering or selecting a Batch No, the system queries [`card-sales/ajax-get-batch-summary.php`](../card-sales/ajax-get-batch-summary.php) to calculate:

1. **TOTAL AMOUNT (PURE SALES)**:
   $$\text{Total Amount} = \sum \text{amount}$$
   *Pure fuel sales settled via card, without adding revenue and without deducting service charges.*
2. **TOTAL REVENUE CHARGES (SEPARATE)**:
   $$\text{Total Revenue Charge} = \sum \text{difference}$$
   *Displays current station revenue rate percentage below it (e.g., `Current Rate: 1.00%`). Kept strictly separate from bank settlement.*
3. **TOTAL SERVICE CHARGES (BANK FEE)**:
   $$\text{Total Service Charges} = \text{Total Amount} \times \left(\frac{\text{charges\_percentage}}{100}\right)$$
   *Displays current bank fee percentage below it (e.g., `Bank Fee: 2.00%`).*
4. **NET SETTLEMENT TOTAL (ONLY DEDUCTS SERVICE CHARGES)**:
   $$\text{Expected Net Total} = \text{Total Amount} - \text{Total Service Charges}$$
   *The exact net settlement deposit expected on the physical POS terminal settlement report. Revenue charge is EXCLUDED.*

### 4. Operator Verification Workflow & Popup Modal Breakdown
- **Manual Batch Input with Datalist**: The Batch No input allows operators to freely type their terminal batch number, while a companion `<datalist id="batch_no_list">` suggests all batches logged for that machine & shift for 1-click convenience.
- **Modal Popup On-Demand Display**: The 4 separated calculation metrics (**TOTAL AMOUNT**, **REVENUE CHARGES** with current rate %, **SERVICE CHARGES** with bank fee %, **NET SETTLEMENT TOTAL**) and the itemized card swipe records table are displayed inside a Bootstrap modal popup upon clicking the **"View Calculations & Swipes Popup"** button.
- **Empty Settlement Amount Input**: The Settlement Amount field starts empty so the operator explicitly cross-verifies against the physical POS batch receipt and inputs the actual slip figure.
- **Apply Total Button**: The popup modal includes an **"Apply Total to Settlement Amount"** button that automatically copies the calculated net total into the form's amount input and closes the modal.
- **Itemized Swipes Table in Popup**: The modal contains a detailed table listing every swipe in that batch (`#`, `Date & Shift`, `Nozzle / Item`, `Trace No`, `Total Amount`, `Revenue Diff`, `Fee Charge`, `Net Amount`).

### 5. Settlement Amount Mismatch Alert & Operator Override
When an operator inputs a settlement amount and clicks **Save Settlement**:
1. **Exact / Within Tolerance Match ($\pm 0.01$)**: The record saves immediately without friction against Expected Net Total ($\text{Pure Sales} - \text{Service Charges}$).
2. **Mismatch Detection**: If the entered settlement amount does not match the system calculated net total:
   - System displays a SweetAlert2 warning dialog showing the exact calculation breakdown:
     - **Pure Card Sales Amount**: `Rs. X,XXX.XX`
     - **Revenue Charge (Separate)**: `+Rs. XX.XX` *(Rate: X.XX%)*
     - **Bank Service Charges**: `-Rs. XX.XX` *(Fee: X.XX%)*
     - **Expected Net Settlement Total**: `Rs. X,XXX.XX`
     - **You Entered**: `Rs. X,XXX.XX`
     - **Discrepancy / Difference**: `±Rs. XX.XX`
   - **Dual Operator Actions**:
     - **`[ Review & Fix ]`**: Closes the dialog, halts submission, and returns focus to the Settlement Amount field.
     - **`[ Yes, Proceed & Save ]`**: Allows operator confirmation to override the warning and save the settlement figure as-is into the database.

---

## 7. File Architecture

| File Path | Purpose |
| :--- | :--- |
| [`include/card_helper.php`](../include/card_helper.php) | Helper functions for card machine data handling |
| [`card-sales/ajax-get-machine-batch.php`](../card-sales/ajax-get-machine-batch.php) | AJAX JSON endpoint for dynamic machine batch suggestions on date/shift change |
| [`card-sales/ajax-get-batch-summary.php`](../card-sales/ajax-get-batch-summary.php) | AJAX JSON endpoint returning batch calculations (`Total Amount`, `After Adding Revenue`, `After Deduction Charge`, `Total`) and itemized transactions |
| [`card-sales/card-sales-list.php`](../card-sales/card-sales-list.php) | Consolidated card sales list with metrics, date filter, itemized modal, and action buttons |
| [`card-sales/add-card-sale.php`](../card-sales/add-card-sale.php) | Multi-row card sale entry form with manual operator batch input and difference auto-calc |
| [`card-sales/edit-card-sale.php`](../card-sales/edit-card-sale.php) | Card sales editing interface with editable batch numbers, trace numbers, and differences |
| [`card-sales/generate-pdf-card-sale.php`](../card-sales/generate-pdf-card-sale.php) | A4 print-ready daily card sales settlement statement |
| [`include/deletecardsale.php`](../include/deletecardsale.php) | Soft-delete AJAX handler for card sales by date or record ID |
| [`card-sales/settlement-list.php`](../card-sales/settlement-list.php) | Card Sale Settlements listing with Shift column, Shift filter, summary cards, and action buttons |
| [`card-sales/add-settlement.php`](../card-sales/add-settlement.php) | Settlement creation form with manual batch input, modal calculation breakdown, mismatch alert, and override |
| [`card-sales/edit-settlement.php`](../card-sales/edit-settlement.php) | Settlement edit form with manual batch input, modal breakdown, and mismatch alert with override |
| [`include/deletesettlement.php`](../include/deletesettlement.php) | Soft-delete AJAX handler for card sale settlements |
| [`card-sales/generate-pdf-settlement.php`](../card-sales/generate-pdf-settlement.php) | A4 print-ready settlement statement with Shift column |

---

## 8. Verification Scenarios & Test Cases

### Scenario 1: Invalid / "Everything Wrong" Inputs
1. **Case 1.1 — Missing Core Fields**: Leaving Shift, Machine, or Batch empty triggers required validation and blocks submission.
2. **Case 1.2 — Non-Existent Batch Number**: Operator enters an unrecorded batch number (0 swipes found in system readings). System displays warning badge *"No Swipes Logged for Batch"*. If operator inputs a figure and saves, warning modal explains that no system readings exist, but allows saving upon operator confirmation.
3. **Case 1.3 — Zero / Negative Settlement Amount**: Input validation prevents submitting `0` or negative values.

### Scenario 2: Batch Exists, but Operator Inputs Wrong Settlement Amount
1. **Setup**: Machine Meezan (2.00% fee). Batch #105 has Rs. 2,000.00 sales. Net calculation = Rs. 2,000 $-$ Rs. 40 = **Rs. 1,960.00**.
2. **Action**: Operator enters `2000.00` in Settlement Amount and clicks **Save Settlement**.
3. **System Behavior**: SweetAlert2 modal displays calculation breakdown showing Expected Net: Rs. 1,960.00, Entered: Rs. 2,000.00, Difference: +Rs. 40.00.
4. **Outcome A ("Review & Fix")**: Operator clicks Review & Fix $\rightarrow$ Dialog closes, focus returns to amount input.
5. **Outcome B ("Yes, Proceed & Save")**: Operator clicks Yes, Proceed & Save $\rightarrow$ Form submits and saves the settlement record with entered amount.

### Scenario 3: Exact Match / Happy Path
1. **Setup**: Same batch with Expected Net Rs. 1,960.00.
2. **Action**: Operator enters `1960.00` and clicks **Save Settlement**.
3. **Outcome**: Passes validation cleanly without mismatch prompt and saves immediately.

### Scenario 4: Manual Batch Number in Card Sales Entry
1. **Action**: In `add-card-sale.php`, operator enters batch `7721` for Row 1, adds Row 2 (machine retains `7721` as default, freely editable), and enters `4001` for Row 3.
2. **Outcome**: Exact batch numbers `7721` and `4001` are saved in `tbl_meter_reading_card_sales` and rendered as editable text in `edit-card-sale.php`.
