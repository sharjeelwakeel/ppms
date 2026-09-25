# Cash Reading Automation & Nozzle Synchronization Test Suite (`tests/cash-reading-sale/`)

## 1. Overview & Golden Invariants
This dedicated test suite validates the **Cash Reading Automation** engine and **Dispensing Nozzle Running Counter (`tbl_nozzles.start_reading`)** synchronization across all lifecycle events.

### Primary Mathematical Invariant:
$$\mathbf{Cash\ Litres} = \max\Big(\text{Net Meter Litres} - (\text{Credit Litres} + \text{Card Litres}),\ 0\Big)$$
$$\mathbf{Cash\ Amount\ (Rs.)} = \text{round}\Big(\mathbf{Cash\ Litres} \times \text{Fuel Rate},\ 2\Big)$$

### Nozzle Counter Invariant:
$$\mathbf{tbl\_nozzles.start\_reading} = \text{Latest Chronological Current Reading}$$
Editing past/historical shifts updates past records & remarks, but **never rolls back the live nozzle counter of today into the past**.

### Database Isolation Rule:
All tests in this folder connect strictly to the dedicated test database **`ppms_test`** via `tests/config.php`. **Production database `ppms` is never touched.**

---

## 2. Execution Instructions

### A. Run All Tests in this Folder Suite:
```bash
d:\xampp\php\php.exe tests/run_all_tests.php --suite=cash-reading-sale
```

### B. Run Any Individual Test Case:
```bash
# Test 01: Standard Auto Cash Creation
d:\xampp\php\php.exe tests/cash-reading-sale/test_01_auto_cash_creation.php

# Test 04: Credit Sale Fuel Deduction
d:\xampp\php\php.exe tests/cash-reading-sale/test_04_credit_sale_deduction.php

# Test 14: Reverse Recalculation & Remarks Audit
d:\xampp\php\php.exe tests/cash-reading-sale/test_14_reverse_recalculation_and_remarks.php

# Test 19: Historical / Past-Date Protection
d:\xampp\php\php.exe tests/cash-reading-sale/test_19_historical_past_date_protection.php
```

---

## 3. Test Cases Specification & Expected Responses

### Group 1: Meter Reading $\rightarrow$ Auto Cash Generation

#### TC-CASH-01: Standard Auto-Cash Generation (`test_01_auto_cash_creation.php`)
- **Purpose**: Verify that creating a shift meter reading automatically creates a cash sales record for that nozzle and shift.
- **Preconditions**: Nozzle #1 active, baseline meter reading = `0.00`, fuel price = `Rs. 200.00`.
- **Trigger**: Insert Meter Reading with Net Sale = `1,000.00 Ltr` and invoke `sync_shift_cash_sales()`.
- **Expected Response & Database State**:
  - `tbl_meter_reading_cash_sales.quantity` = `1000.00`
  - `tbl_meter_reading_cash_sales.amount` = `200000.00`
  - `tbl_meter_reading_cash_sales.rate` = `200.00`
  - `tbl_meter_reading_cash_sales.is_manual_override` = `0`
  - `tbl_meter_reading_cash_sales.notes` contains `"Auto-calculated from Meter Reading"`
- **Teardown**: Deletes test records for the test date.

#### TC-CASH-02: Test Fuel Deduction Handling (`test_02_test_fuel_deduction.php`)
- **Purpose**: Verify that quality check/calibration fuel poured back into tank is deducted from Cash Sales.
- **Preconditions**: Nozzle #1, closing meter = `1,005.00`, test reading = `5.00 Ltr`.
- **Trigger**: Meter Net Sale evaluates to `1,000.00 Ltr`.
- **Expected Response & Database State**:
  - Cash Sale quantity equals exactly `1000.00 Ltr` (Net Sale), **not** gross counter difference `1005.00`.

#### TC-CASH-03: Meter Reading Edit Recomputation (`test_03_meter_edit_recomputation.php`)
- **Purpose**: Verify that editing the closing meter in an existing shift updates the auto-generated cash record.
- **Preconditions**: Shift exists with Net Sale = `1,000.00 Ltr` and Cash Sale = `1,000.00 Ltr`.
- **Trigger**: Operator edits closing reading so Net Sale becomes `1,200.00 Ltr`.
- **Expected Response & Database State**:
  - `tbl_meter_reading_cash_sales.quantity` updates to `1200.00`.
  - `tbl_meter_reading_cash_sales.amount` updates to `Rs. 240,000.00`.

---

### Group 2: Non-Cash Settlement Deductions & Restoration

#### TC-CASH-04: Credit Sale Fuel Deduction (`test_04_credit_sale_deduction.php`)
- **Purpose**: Verify that recording credit vouchers deducts litres from cash collections.
- **Preconditions**: Shift Net Sale = `1,000.00 Ltr`. Cash Sale = `1,000.00 Ltr`.
- **Trigger**: Insert Credit Slip of `300.00 Ltr` (`tbl_meter_reading_credit_sales`).
- **Expected Response & Database State**:
  - Cash Sale quantity automatically reduces to **`700.00 Ltr`** (`Rs. 140,000.00`).

#### TC-CASH-05: Credit Sale Edit Adjustment (`test_05_credit_sale_edit_adjustment.php`)
- **Purpose**: Verify that altering a credit slip quantity dynamically adjusts cash remainder.
- **Preconditions**: Shift has Credit Slip of `300.00 Ltr` and Cash Sale of `700.00 Ltr`.
- **Trigger**: Update Credit Slip volume to `450.00 Ltr` (+`150.00 Ltr`).
- **Expected Response & Database State**:
  - Cash Sale quantity reduces to **`550.00 Ltr`** (`Rs. 110,000.00`).

#### TC-CASH-06: Credit Sale Delete Restoration (`test_06_credit_sale_delete_restoration.php`)
- **Purpose**: Verify that deleting a credit voucher restores fuel volume to cash collections.
- **Preconditions**: Shift has Credit Slip of `450.00 Ltr` and Cash Sale of `550.00 Ltr`.
- **Trigger**: Soft-delete the Credit Slip (`deleted_at = NOW()`).
- **Expected Response & Database State**:
  - Cash Sale quantity restores back to **`1,000.00 Ltr`** (`Rs. 200,000.00`).

#### TC-CASH-07: Card Sale Fuel Deduction (`test_07_card_sale_deduction.php`)
- **Purpose**: Verify that card machine POS swipes deduct from cash collections.
- **Preconditions**: Shift Net Sale = `1,000.00 Ltr`.
- **Trigger**: Insert Card POS transaction of `200.00 Ltr` (`tbl_meter_reading_card_sales`).
- **Expected Response & Database State**:
  - Cash Sale quantity reduces to **`800.00 Ltr`** (`Rs. 160,000.00`).

#### TC-CASH-08: Card Sale Delete Restoration (`test_08_card_sale_delete_restoration.php`)
- **Purpose**: Verify that deleting a card transaction restores fuel volume to cash collections.
- **Preconditions**: Shift has Card Sale of `200.00 Ltr` and Cash Sale of `800.00 Ltr`.
- **Trigger**: Soft-delete the Card Sale (`deleted_at = NOW()`).
- **Expected Response & Database State**:
  - Cash Sale quantity restores back to **`1,000.00 Ltr`** (`Rs. 200,000.00`).

#### TC-CASH-09: Multi-Channel Compound Reconciliation (`test_09_multi_channel_compound.php`)
- **Purpose**: Verify exact mathematical balance when Cash, Credit, and Card occur simultaneously.
- **Preconditions**: Shift Net Sale = `1,000.00 Ltr`.
- **Trigger**: Add Credit = `400.00 Ltr` and Card = `350.00 Ltr`.
- **Expected Response & Database State**:
  - Cash Sale quantity = $1,000 - 400 - 350 = \mathbf{250.00\text{ Ltr}}$.
  - Cash Amount = $\mathbf{Rs.\ 50,000.00}$.
  - Reconciled Invariant: $\text{Cash (250)} + \text{Credit (400)} + \text{Card (350)} = \text{Meter (1,000)}$ ($100\%$ balanced).

#### TC-CASH-10: Zero-Floor Clamping (`test_10_zero_floor_clamping.php`)
- **Purpose**: Prevent negative cash if non-cash entries accidentally exceed meter reading.
- **Preconditions**: Shift Net Sale = `500.00 Ltr`.
- **Trigger**: Add Credit Slip of `600.00 Ltr` (> 500 Ltr).
- **Expected Response & Database State**:
  - Cash Sale quantity clamps to **`0.00 Ltr`** and amount clamps to **`Rs. 0.00`** (never negative).

---

### Group 3: Lifecycle, Manual Overrides & Pre-Checks

#### TC-CASH-11: Meter Deletion Cascade Cleanup (`test_11_meter_delete_cascade_cleanup.php`)
- **Purpose**: Verify that soft-deleting a shift meter reading cleans up its auto-generated cash record.
- **Preconditions**: Active meter reading and linked auto cash sale.
- **Trigger**: Soft-delete Meter Reading header (`deleted_at = NOW()`).
- **Expected Response & Database State**:
  - Linked auto cash record (`meter_reading_id = mr.id` and `is_manual_override = 0`) has `deleted_at IS NOT NULL`.

#### TC-CASH-12: Manual Override Protection (`test_12_manual_override_protection.php`)
- **Purpose**: Managerial manual adjustments (`is_manual_override = 1`) must never be silently overwritten by automated background sync.
- **Preconditions**: Cash Sale manually edited with `is_manual_override = 1` and `quantity = 750.00`.
- **Trigger**: A credit slip is added on that shift.
- **Expected Response & Database State**:
  - Automated background sync detects `is_manual_override = 1` and preserves `quantity = 750.00`.

#### TC-CASH-13: Conflict Detection AJAX Pre-Check (`test_13_conflict_detection_ajax.php`)
- **Purpose**: Verify UI endpoint warns cashier if a reading already exists on that shift.
- **Trigger**: Call `check_existing_cash_sale_for_shift($connection, $date, $shift_id, $nozzle_id)`.
- **Expected Response & Database State**:
  - Returns array containing existing `quantity`, `amount`, and `nozzle_name`.
  - Calling with an unrecorded shift returns `null`.

---

### Group 4: Reverse Recalculation & Audit Remarks

#### TC-CASH-14: Current Reading Recalculation & Remarks Audit (`test_14_reverse_recalculation_and_remarks.php`)
- **Purpose**: When cash sale is manually updated, physical closing meter updates and logs a timestamped explanation.
- **Preconditions**: Shift closing meter = `1,500.00` (Net: `500.00 Ltr`).
- **Trigger**: Cashier manually increases Cash Sale to `600.00 Ltr` (+`100.00 Ltr`).
- **Expected Response & Database State**:
  - `tbl_meter_reading_details.current_reading` advances from `1500.00` to **`1600.00`**.
  - `tbl_meter_reading_details.net_sale` advances to **`600.00`**.
  - `tbl_meter_readings.grand_total` updates.
  - `tbl_meter_readings.remarks` appends:
    `[DD-MM-YYYY HH:MM] Nozzle: Current reading recalculated from 1,500.00 to 1,600.00 (+100.00 Ltr) due to Cash Sale update.`

---

### Group 5: Physical Dispenser Nozzle Counter (`tbl_nozzles.start_reading`)

#### TC-NOZ-01: Running Counter Advance on Meter Creation (`test_15_nozzle_advance_on_meter_add.php`)
- **Purpose**: Verify nozzle live counter advances to shift closing reading.
- **Expected Response**: `tbl_nozzles.start_reading` becomes `1,500.00`.

#### TC-NOZ-02: Running Counter Advance on Latest Meter Edit (`test_16_nozzle_advance_on_meter_edit.php`)
- **Purpose**: Correcting latest closing counter updates nozzle live counter.
- **Expected Response**: `tbl_nozzles.start_reading` updates to `1,750.00`.

#### TC-NOZ-03: Running Counter Safe Revert on Meter Deletion (`test_17_nozzle_revert_on_meter_delete.php`)
- **Purpose**: Deleting shift safely rolls back nozzle counter to opening baseline.
- **Expected Response**: `tbl_nozzles.start_reading` safely rolls back to `1,000.00`.

#### TC-NOZ-04: Running Counter Advance on Sales Recomputation (`test_18_nozzle_advance_on_sales_recalc.php`)
- **Purpose**: Manual sales recomputation updates nozzle live counter.
- **Expected Response**: `tbl_nozzles.start_reading` advances to match the new current reading.

#### TC-NOZ-05: Historical / Past-Date Protection (CRITICAL) (`test_19_historical_past_date_protection.php`)
- **Purpose**: Editing past-date shifts updates past details & remarks, but **strictly protects the live nozzle counter of today from rolling back into the past**.
- **Preconditions**:
  - Day 1 (Past Date): Closing reading = `1,000.00`.
  - Day 2 (Latest Date): Closing reading = `2,000.00`. Live counter is `2,000.00`.
- **Trigger**: User edits Day 1 closing reading or Day 1 Cash/Credit sale to `1,100.00`.
- **Expected Response & Database State**:
  - Day 1 records update to `1,100.00`.
  - **`tbl_nozzles.start_reading` STRICTLY REMAINS `2,000.00`** (PROTECTED).
  - Later, when Day 2 (Latest Date) is edited to `2,200.00`, `tbl_nozzles.start_reading` advances to `2,200.00`.
