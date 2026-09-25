# Automated Testing Architecture & Test Case Suite (`markdown/tests.md`)

## 1. Golden Rule: Dedicated Isolated Test Database (`ppms_test`)

> [!CRITICAL]
> **MANDATORY DATABASE ISOLATION RULE**:
> All automated tests, unit tests, integration tests, and test runner scripts **MUST ALWAYS execute against the dedicated test database (`ppms_test`)** initialized from [`ppms-test.sql`](../ppms-test.sql).
> 
> - **NEVER** run automated test suites against the primary production database (`ppms`).
> - The test configuration file (`tests/config.php`) connects strictly to `DB_NAME = 'ppms_test'`.
> - Every test script must wrap its state in `try { ... } finally { ... }` blocks to ensure complete teardown and database cleanup, leaving zero test artifacts behind.

---

## 2. Directory Architecture & Modular Test Organization

All tests are organized inside domain-specific subdirectories under `tests/`. Each business module has its own dedicated folder containing isolated test scripts:

```text
tests/
  ├── config.php                  # Test Database Connection (Database: 'ppms_test')
  ├── test_helper.php             # Common assertion functions, colored CLI output, DB reset
  ├── run_all_tests.php           # Master test runner (executes all or individual folder suites)
  │
  ├── cash-reading-sale/          # Cash Reading Automation & Nozzle Sync Suite (19 tests)
  │     ├── test_01_auto_cash_creation.php
  │     ├── test_02_test_fuel_deduction.php
  │     ├── test_03_meter_edit_recomputation.php
  │     ├── test_04_credit_sale_deduction.php
  │     ├── test_05_credit_sale_edit_adjustment.php
  │     ├── test_06_credit_sale_delete_restoration.php
  │     ├── test_07_card_sale_deduction.php
  │     ├── test_08_card_sale_delete_restoration.php
  │     ├── test_09_multi_channel_compound.php
  │     ├── test_10_zero_floor_clamping.php
  │     ├── test_11_meter_delete_cascade_cleanup.php
  │     ├── test_12_manual_override_protection.php
  │     ├── test_13_conflict_detection_ajax.php
  │     ├── test_14_reverse_recalculation_and_remarks.php
  │     ├── test_15_nozzle_advance_on_meter_add.php
  │     ├── test_16_nozzle_advance_on_meter_edit.php
  │     ├── test_17_nozzle_revert_on_meter_delete.php
  │     ├── test_18_nozzle_advance_on_sales_recalc.php
  │     └── test_19_historical_past_date_protection.php
  │
  ├── daily-nozzle-report/        # Daily Nozzle Report & Settlement Audit Suite (19 tests)
  │     ├── test_01_mandatory_nozzle_filter.php
  │     ├── test_02_default_date_behavior.php
  │     ├── test_03_all_shifts_combined_agg.php
  │     ├── test_04_single_shift_filtering.php
  │     ├── test_05_meter_volume_and_revenue.php
  │     ├── test_06_cash_sales_aggregation.php
  │     ├── test_07_credit_sales_aggregation.php
  │     ├── test_08_balanced_slip_handling.php
  │     ├── test_09_temp_receive_settlement.php
  │     ├── test_10_temporary_loan_slip.php
  │     ├── test_11_card_sales_aggregation.php
  │     ├── test_12_perfect_reconciliation.php
  │     ├── test_13_volumetric_shortage_variance.php
  │     ├── test_14_volumetric_excess_variance.php
  │     ├── test_15_nozzle_expense_filtering.php
  │     ├── test_16_net_operating_yield.php
  │     ├── test_17_multi_nozzle_isolation.php
  │     ├── test_18_soft_deleted_records_exclusion.php
  │     └── test_19_pdf_generator_output.php
  │
  ├── credit-sales/               # Future folder: Voucher quota, dual-fuel & vehicle checks
  ├── meter-readings/             # Future folder: Totalizer validation & shift closures
  └── tanks/                      # Future folder: Tanks & dip chart calibration
```

---

## 3. Test Cases: `cash-reading-sale/` Suite

This suite verifies that the cash remainder equation:
$$\mathbf{Cash\ Litres} = \max\Big(\text{Net Meter Litres} - (\text{Credit Litres} + \text{Card Litres}),\ 0\Big)$$
and physical nozzle tracking:
$$\mathbf{tbl\_nozzles.start\_reading} = \text{Latest Chronological Current Reading}$$
remain 100% synchronized, resilient, and non-destructive.

---

### Group A: Meter Reading & Auto-Cash Generation

#### TC-CASH-01: Standard Auto-Cash Generation
- **Objective**: Verify that creating a shift meter reading automatically creates a cash sales record for that nozzle and shift.
- **Inputs**:
  - Date: `2029-01-01`, Shift: `1`, Nozzle: `1`, Fuel Price: `Rs. 200.00`
  - Opening Meter: `0.00`, Closing Meter: `1,000.00`, Test Reading: `0.00` $\rightarrow$ Net Sale: `1,000.00 Ltr`
- **Expected DB State (`ppms_test.tbl_meter_reading_cash_sales`)**:
  - `quantity = 1000.00`
  - `amount = 200000.00`
  - `rate = 200.00`
  - `is_manual_override = 0`
  - `notes` contains `"Auto-calculated from Meter Reading"`

#### TC-CASH-02: Test Fuel Deduction Handling
- **Objective**: Verify that testing fuel deducted from meter counter is excluded from Cash Sales.
- **Inputs**:
  - Opening: `0.00`, Closing: `1,005.00` (Gross: `1,005.00 Ltr`), Test Reading: `5.00 Ltr` $\rightarrow$ Net Sale: `1,000.00 Ltr`.
- **Expected DB State**:
  - Cash Sale quantity equals exactly `1000.00 Ltr` (Net Sale), **not** gross counter difference `1005.00`.

#### TC-CASH-03: Meter Reading Edit Recomputation
- **Objective**: Verify that editing closing meter in an existing shift updates the auto-generated cash record.
- **Inputs**:
  - Edit closing meter from `1,000.00` to `1,200.00` (Net Sale becomes `1,200.00 Ltr`).
- **Expected DB State**:
  - `tbl_meter_reading_cash_sales.quantity` updates from `1000.00` to `1200.00`.
  - `tbl_meter_reading_cash_sales.amount` updates from `Rs. 200,000.00` to `Rs. 240,000.00`.

---

### Group B: Non-Cash Settlement Deductions & Restoration

#### TC-CASH-04: Credit Sale Fuel Deduction
- **Objective**: Verify that adding a credit slip automatically reduces the cash sales remainder.
- **Inputs**:
  - Shift has `1,000.00 Ltr` Net Sale. Add Credit Slip of `300.00 Ltr` (`tbl_meter_reading_credit_sales`).
- **Expected DB State**:
  - Cash Sale quantity reduces to **`700.00 Ltr`** (`amount = Rs. 140,000.00`).

#### TC-CASH-05: Credit Sale Edit Adjustment
- **Objective**: Verify that editing an existing credit slip adjusts cash sales accordingly.
- **Inputs**:
  - Update the Credit Slip volume from `300.00 Ltr` to `450.00 Ltr`.
- **Expected DB State**:
  - Cash Sale quantity reduces to **`550.00 Ltr`** (`amount = Rs. 110,000.00`).

#### TC-CASH-06: Credit Sale Delete Restoration
- **Objective**: Verify that soft-deleting a credit slip restores the litres back into cash sales.
- **Inputs**:
  - Soft-delete the `450.00 Ltr` Credit Slip (`deleted_at = NOW()`).
- **Expected DB State**:
  - Cash Sale quantity restores back to **`1,000.00 Ltr`** (`amount = Rs. 200,000.00`).

#### TC-CASH-07: Card Sale Fuel Deduction
- **Objective**: Verify that adding a Card POS swipe reduces the cash sales remainder.
- **Inputs**:
  - Shift has `1,000.00 Ltr` Net Sale. Add Card swipe of `200.00 Ltr` (`tbl_meter_reading_card_sales`).
- **Expected DB State**:
  - Cash Sale quantity reduces to **`800.00 Ltr`** (`amount = Rs. 160,000.00`).

#### TC-CASH-08: Card Sale Delete Restoration
- **Objective**: Verify that soft-deleting a card transaction restores the litres back into cash sales.
- **Inputs**:
  - Soft-delete the `200.00 Ltr` Card Sale (`deleted_at = NOW()`).
- **Expected DB State**:
  - Cash Sale quantity restores back to **`1,000.00 Ltr`** (`amount = Rs. 200,000.00`).

#### TC-CASH-09: Multi-Channel Compound Reconciliation
- **Objective**: Verify exact mathematical balance when Cash, Credit, and Card occur on the same shift.
- **Inputs**:
  - Net Meter Sale: `1,000.00 Ltr`.
  - Add Credit Slips: `400.00 Ltr`.
  - Add Card Swipes: `350.00 Ltr`.
- **Expected DB State**:
  - Cash Sale quantity = $1,000 - 400 - 350 = \mathbf{250.00\text{ Ltr}}$.
  - Financial Amount = $\mathbf{Rs.\ 50,000.00}$.
  - Reconciled Invariant: $\text{Cash (250)} + \text{Credit (400)} + \text{Card (350)} = \text{Meter (1000)}$ ($100\%$ balanced).

#### TC-CASH-10: Zero-Floor Clamping
- **Objective**: Verify that non-cash volume exceeding meter net volume clamps cash to zero, never negative.
- **Inputs**:
  - Net Meter: `500.00 Ltr`. Credit added: `600.00 Ltr`.
- **Expected DB State**:
  - Cash Sale quantity clamps to **`0.00 Ltr`** and amount clamps to **`Rs. 0.00`** (using `max(0, net - credit - card)`).

---

### Group C: Lifecycle, Manual Overrides & Pre-Checks

#### TC-CASH-11: Meter Deletion Cascade Cleanup
- **Objective**: Verify that soft-deleting a shift meter reading cleans up its auto-generated cash record.
- **Inputs**:
  - Soft-delete Meter Reading header (`deleted_at = NOW()`).
- **Expected DB State**:
  - Linked auto cash record (`meter_reading_id = mr.id` and `is_manual_override = 0`) is soft-deleted (`deleted_at IS NOT NULL`).

#### TC-CASH-12: Manual Override Protection
- **Objective**: Verify that cashier manual adjustments are protected from background automated overwrites.
- **Inputs**:
  - Cashier edits Cash Sale manually (`is_manual_override = 1`).
  - Later, a credit slip or card swipe is added on that shift.
- **Expected DB State**:
  - Automated background sync detects `is_manual_override = 1` and **does not overwrite** the user's manual adjustments.

#### TC-CASH-13: Conflict Detection AJAX Pre-Check
- **Objective**: Verify that `ajax-check-existing-cash.php` correctly detects existing records.
- **Inputs**:
  - Query endpoint with Date, Shift, and Nozzle where a reading exists.
- **Expected Response**:
  - Returns JSON `{ status: "success", exists: true, data: { ... } }`.
  - Querying with empty shift returns `{ status: "success", exists: false }`.

---

### Group D: Reverse Recalculation & Remarks Audit

#### TC-CASH-14: Current Reading Recalculation & Audit Remarks Logging
- **Objective**: Verify that manually editing Cash Sale updates physical closing meter and logs audit remarks.
- **Inputs**:
  - Meter has closing reading = `1,500.00` (Net: `500.00 Ltr`).
  - Cashier manually increases Cash Sale to `600.00 Ltr` (+`100.00 Ltr`).
  - Triggers `recalculate_meter_reading_from_sales()`.
- **Expected DB State**:
  - `tbl_meter_reading_details.current_reading` advances from `1500.00` to **`1600.00`**.
  - `tbl_meter_reading_details.net_sale` advances to **`600.00`**.
  - `tbl_meter_readings.grand_total` updates.
  - `tbl_meter_readings.remarks` appends timestamped note:
    `[DD-MM-YYYY HH:MM] Nozzle: Current reading recalculated from 1,500.00 to 1,600.00 (+100.00 Ltr) due to Cash Sale update.`

---

### Group E: Physical Dispensing Nozzle Running Counter (`tbl_nozzles.start_reading`)

#### TC-NOZ-01: Running Counter Advance on Meter Creation
- **Objective**: Verify that adding a meter reading advances the nozzle's physical counter.
- **Inputs**:
  - Initial `tbl_nozzles.start_reading = 1000.00`.
  - Create Meter Reading with closing reading = `1,500.00`.
- **Expected DB State**:
  - `tbl_nozzles.start_reading` becomes **`1,500.00`**.

#### TC-NOZ-02: Running Counter Advance on Latest Meter Edit
- **Objective**: Verify that editing the latest meter reading advances the nozzle's physical counter.
- **Inputs**:
  - Edit the closing reading from `1,500.00` to `1,750.00`.
- **Expected DB State**:
  - `tbl_nozzles.start_reading` becomes **`1,750.00`**.

#### TC-NOZ-03: Running Counter Safe Revert on Meter Deletion
- **Objective**: Verify that soft-deleting a meter reading rolls back the nozzle counter to the previous baseline.
- **Inputs**:
  - Soft-delete the meter reading.
- **Expected DB State**:
  - `tbl_nozzles.start_reading` rolls back to baseline opening reading **`1,000.00`**.

#### TC-NOZ-04: Running Counter Advance on Sales Recomputation
- **Objective**: Verify that reverse sales recalculation updates physical nozzle counter.
- **Inputs**:
  - User manually increases Cash Sale by `+100.00 Ltr`.
- **Expected DB State**:
  - `tbl_nozzles.start_reading` advances to match the new current reading.

#### TC-NOZ-05: Historical / Past-Date Protection (CRITICAL)
- **Objective**: Verify that editing or adding a transaction on a **past date** does NOT roll back or tamper with the live physical nozzle counter of a later date.
- **Inputs**:
  - Day 1 (Past Date, e.g. `2029-01-01`): Meter closed at `1,000.00`.
  - Day 2 (Latest Date, e.g. `2029-01-02`): Meter closed at `2,000.00`. Live counter is `2,000.00`.
  - User edits Day 1 closing reading or Day 1 Cash/Credit sale to `1,100.00`.
- **Expected DB State**:
  - Day 1 records update to `1,100.00`.
  - **`tbl_nozzles.start_reading` STRICTLY REMAINS `2,000.00`** (PROTECTED).
  - Later, when Day 2 (Latest Date) is edited to `2,200.00`, `tbl_nozzles.start_reading` advances to `2,200.00`.

---

## 4. Test Cases: `daily-nozzle-report/` Suite

This suite verifies that the daily nozzle mathematical reconciliation:
$$\mathbf{Total\ Settled\ Litres} = \mathbf{Cash\ Litres} + \mathbf{Credit\ Issued\ Litres} + \mathbf{Card\ Litres}$$
$$\mathbf{Volume\ Variance} = \mathbf{Total\ Settled\ Litres} - \mathbf{Meter\ Net\ Sale\ Litres}$$
$$\mathbf{Financial\ Variance} = \mathbf{Total\ Settled\ Amount} - \mathbf{Meter\ Gross\ Revenue}$$
$$\mathbf{Net\ Nozzle\ Yield} = \mathbf{Meter\ Gross\ Revenue} - \mathbf{Nozzle\ Maintenance\ Expenses}$$
and Credit Sales domain invariants (Balanced Slips, Temp Receive settlements, Temporary loan chits) remain 100% accurate, resilient, and non-destructive.

---

### Group A: Filter Routing & Parameters

#### TC-RPT-01: Mandatory Nozzle Selection
- **Objective**: Verify that when `nozzle_id = 0`, report safely returns empty state, zero metrics, and prompts user to select a nozzle.
- **Assertion**: `selected_nozzle` is null, all transaction arrays empty, total meter litres = 0.00.

#### TC-RPT-02: Default Single Date Today
- **Objective**: Verify that when `date` parameter is omitted, report defaults strictly to current date `date('Y-m-d')`, not a date range.
- **Assertion**: `report_date` strictly equals `date('Y-m-d')`.

#### TC-RPT-03: All Shifts Combined Aggregation
- **Objective**: Verify that `shift_id = 0` combines Shift 1 and Shift 2 readings and settlements for the selected nozzle.
- **Assertion**: Meter volume equals sum of Shift 1 (600 Ltr) + Shift 2 (400 Ltr) = 1,000.00 Ltr.

#### TC-RPT-04: Single Shift Isolation Filtering
- **Objective**: Verify that selecting `shift_id = 1` strictly isolates Shift 1 and excludes Shift 2 transactions.
- **Assertion**: Returns only Shift 1 records (600 Ltr), zero Shift 2 data.

---

### Group B: Meter Readings & Financial Channels

#### TC-RPT-05: Meter Volume, Test Reading Deduction & Gross Revenue Audit
- **Objective**: Verify physical meter Net Sale ($Current - Last - Test$) and Gross Revenue ($Net \times Price$), tracking opening meter ($min$) and closing meter ($max$).
- **Assertion**: Test reading (10 Ltr) deducted from gross difference (1,010 Ltr) = 1,000.00 Ltr net sale. Revenue = Rs. 250,000.00.

#### TC-RPT-06: Cash Sales Aggregation
- **Objective**: Verify aggregation of multiple cash sales transactions from `tbl_meter_reading_cash_sales`.
- **Assertion**: Sums 350 Ltr + 150 Ltr = 500.00 Ltr, amount = Rs. 100,000.00.

#### TC-RPT-07: Permanent Credit Sales Vouchers Aggregation
- **Objective**: Verify that permanent credit slips account for issued fuel volume (`issue_quantity`), billed quota (`quantity`), and customer charge (`amount`).
- **Assertion**: Issued volume = 80.00 Ltr, Quota = 100.00 Ltr, Amount = Rs. 20,000.00.

#### TC-RPT-08: Balanced Slip Handling (Petrol on Balance - Prepaid Claim)
- **Objective**: **CRITICAL DOMAIN INVARIANT**. When a driver claims balance fuel from an earlier slip (`slip_type = 'Balanced Slip'`):
  - Physical fuel pumped (`issue_quantity` = 50.00 Ltr) flowed through the nozzle counter today.
  - Customer charge is `Rs. 0.00` (prepaid on original voucher).
- **Assertion**: `total_credit_issued_litres` includes 50.00 Ltr, `volume_variance = 0.00 Ltr`, customer charge = Rs. 0.00.

#### TC-RPT-09: Temp Receive Settlement (wasoli loan chit isolation)
- **Objective**: **CRITICAL DOMAIN INVARIANT**. When a driver brings a permanent slip today and settles an old loan chit (`wasoli > 0`):
  - Only fresh fuel pumped today (`issue_quantity` = 15.00 Ltr) flowed through the nozzle counter today.
  - Settled loan chit (`wasoli` = 25.00 Ltr) flowed weeks ago on its original loan date.
- **Assertion**: Today's nozzle credit volume accounts strictly for `15.00 Ltr` (NOT 40 Ltr, NOT wasoli). `volume_variance = 0.00 Ltr`.

#### TC-RPT-10: Temporary Loan Slip Handling
- **Objective**: Verify that temporary loan slips (`slip_type = 'Temporary Slip'`) dispensed today advance nozzle meter throughput.
- **Assertion**: Loan volume (25.00 Ltr) is accounted for in `total_credit_issued_litres`.

#### TC-RPT-11: Card Machine / POS Terminal Transactions Aggregation
- **Objective**: Verify POS terminal swipes: litres, gross amount, service charges, and net bank deposit.
- **Assertion**: Litres = 80.00 Ltr, Gross = Rs. 16,000.00, Fee = Rs. 240.00, Net Bank = Rs. 15,760.00.

---

### Group C: Reconciliation & Variance Analysis

#### TC-RPT-12: Perfect Volumetric & Financial Reconciliation (Zero Variance)
- **Objective**: Verify exact zero variance when Cash (500 Ltr) + Credit (300 Ltr) + Card (200 Ltr) = 1,000.00 Ltr Net Sale.
- **Assertion**: `volume_variance = 0.00 Ltr`, `financial_variance = Rs. 0.00`.

#### TC-RPT-13: Volumetric & Financial Shortage Variance (Unsettled Deficit)
- **Objective**: Verify negative variance when collections sum to 900 Ltr vs 1,000 Ltr meter net sale.
- **Assertion**: `volume_variance = -100.00 Ltr`, `financial_variance = -Rs. 20,000.00`.

#### TC-RPT-14: Volumetric & Financial Excess Variance (Surplus Collections)
- **Objective**: Verify positive variance when collections sum to 1,100 Ltr vs 1,000 Ltr meter net sale.
- **Assertion**: `volume_variance = +100.00 Ltr`, `financial_variance = +Rs. 20,000.00`.

---

### Group D: Maintenance Expenses & Isolation

#### TC-RPT-15: Nozzle-Specific Expense Isolation
- **Objective**: Verify that expenses with `nozzle_id = 1` are included while Nozzle #2 and general expenses are excluded.
- **Assertion**: Total expenses for Nozzle #1 = Rs. 1,500.00.

#### TC-RPT-16: Net Nozzle Operating Yield Calculation
- **Objective**: Verify formula $\text{Net Nozzle Operating Yield} = \text{Meter Gross Revenue} - \text{Nozzle Maintenance Expenses}$.
- **Assertion**: Gross Rs. 100,000 - Expenses Rs. 1,500 = Net Yield Rs. 98,500.00.

#### TC-RPT-17: Multi-Nozzle Isolation (Strict Per-Nozzle Partitioning)
- **Objective**: Verify that Nozzle #1 data strictly isolates from Nozzle #2 on the same date and shift.
- **Assertion**: Nozzle #1 = 400 Ltr, Nozzle #2 = 800 Ltr with zero crosstalk.

#### TC-RPT-18: Soft-Deleted Records Exclusion Audit
- **Objective**: Verify that soft-deleted records (`deleted_at IS NOT NULL`) across meter readings, cash, credit, card, and expenses are strictly ignored.
- **Assertion**: All soft-deleted records excluded from counts and totals.

#### TC-RPT-19: PDF Generator Clean Output & Domain Badges Rendering
- **Objective**: End-to-end rendering audit of `reports/generate-pdf-nozzle-report.php` verifying valid HTML, company branding, and settlement badges.
- **Assertion**: Non-empty output containing report title, Balanced slip badge `(Prepaid)`, Temp Receive badge `Settled #TMP-77`, and Net Operating Yield.

---

## 5. Test Execution Instructions

### A. Run Master Test Runner (All Suites):
Executes all 38 automated test cases across both suites against `ppms_test`:
```bash
d:\xampp\php\php.exe tests/run_all_tests.php
```

### B. Run Cash Reading Automation Suite (19 Tests):
```bash
d:\xampp\php\php.exe tests/run_all_tests.php --suite=cash-reading-sale
```

### C. Run Daily Nozzle Report Suite (19 Tests):
```bash
d:\xampp\php\php.exe tests/run_all_tests.php --suite=daily-nozzle-report
```

### D. Run Individual Test Case:
```bash
# Test 08: Balanced Slip Handling (Petrol on Balance)
d:\xampp\php\php.exe tests/daily-nozzle-report/test_08_balanced_slip_handling.php

# Test 09: Temp Receive Settlement (wasoli loan chit isolation)
d:\xampp\php\php.exe tests/daily-nozzle-report/test_09_temp_receive_settlement.php

# Test 12: Perfect Volumetric Reconciliation
d:\xampp\php\php.exe tests/daily-nozzle-report/test_12_perfect_reconciliation.php
```

