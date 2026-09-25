# Daily Nozzle Report Module Complete Documentation (`markdown/nozzle_report.md`)

## 1. Overview
The **Daily Nozzle Report** (`reports/nozzle-report.php`) and its companion printable A4 PDF generator (`reports/generate-pdf-nozzle-report.php`) provide petrol pump owners, auditors, and station managers with a dual-scope operational and financial audit:
1. **Station-Wide Summary Mode (`nozzle_id = 0`)**: Aggregates all dispensing nozzles (`tbl_nozzles`) across the station, providing total net sales, fuel product rollups, comparative nozzle performance matrices, and station reconciliation.
2. **Individual Nozzle Audit Mode (`nozzle_id > 0`)**: Provides a 360-degree audit of a specific dispensing nozzle, itemizing physical readings, settlements, and dedicated equipment maintenance costs.

It cross-reconciles:
1. **Physical Meter Readings**: Opening totalizer, closing totalizer, test fuel deductions, and net litres dispensed.
2. **Payment Channel Settlements**:
   - **Cash Sales**: Volume and cash collected (`tbl_meter_reading_cash_sales`).
   - **Credit Sales**: Fuel issued and billable customer slips (`tbl_meter_reading_credit_sales`).
   - **Card Sales**: Volume, gross terminal amount, service fee deductions, and net bank deposits (`tbl_meter_reading_card_sales`).
3. **Audit Reconciliation & Variance**: Side-by-side verification between physical fuel dispensed through the nozzle and recorded payment settlements.
4. **Equipment Expenses**: Maintenance, repair, calibration, and operating costs recorded on behalf of this nozzle (`tbl_expenses.nozzle_id = :id`) as well as station general expenses.
5. **Net Nozzle & Station Operating Yield**: Gross fuel revenue generated minus all operating expenses.

---

## 2. Filter Rules & Form Behavior

| Parameter | Type | Validation Rule | Default Behavior |
| :--- | :--- | :--- | :--- |
| **Nozzle (`nozzle_id`)** | Integer (FK) | **OPTIONAL** | Defaults to `0` (*-- All Dispensing Nozzles (Station Summary) --*). When `0`, the report executes station-wide across all active nozzles with comparison matrix. When a nozzle is chosen, filters strictly to that nozzle. |
| **Shift (`shift_id`)** | Integer (FK) | **OPTIONAL** | If empty (`0`), queries aggregate across **both / all shifts** for that day. If a specific shift is chosen, queries filter strictly to that shift. |
| **Report Date (`date`)** | Date (`YYYY-MM-DD`) | **MANDATORY** | Single day picker defaulting to the current date (`date('Y-m-d')`). Ensures lightning-fast execution (< 5ms) without heavy memory overhead. |

---

## 3. Mathematical Equations & Audit Reconciliation

For the selected nozzle (or station-wide aggregate) on the chosen date and optional shift:

### 1. Physical Fuel Dispensed (Meter Counter)
$$\text{Opening Meter} = \min(\text{last\_reading}), \quad \text{Closing Meter} = \max(\text{current\_reading})$$
$$\text{Gross Dispensed (Ltr)} = \sum (\text{current\_reading} - \text{last\_reading})$$
$$\text{Testing Volume (Ltr)} = \sum \text{test\_reading}$$
$$\mathbf{Total\ Net\ Sale\ (Ltr)} = \sum \text{net\_sale} = \text{Gross Dispensed} - \text{Testing Volume}$$
$$\mathbf{Gross\ Meter\ Revenue\ (Rs.)} = \sum (\text{net\_sale} \times \text{price})$$

### 2. Payment Settlement Aggregations
- **Cash Sales**:
  $$\text{Cash Litres} = \sum \text{quantity}, \quad \mathbf{Cash\ Amount\ (Rs.)} = \sum \text{amount}$$
- **Credit Sales (Domain Classification)**:
  - **Standard Voucher (`Permanent Slip`)**: Fuel issued to vehicle (`issue_quantity`) advances nozzle counter. Quota (`quantity`) is billed at product rate (`amount`).
  - **Petrol on Balance (`Balanced Slip`)**: Driver claims fuel balance from an earlier voucher. Physical fuel pumped (`issue_quantity`) flowed through the nozzle today, advancing the meter. Customer charge is strictly `Rs. 0.00` (prepaid). Displayed with badge `[Balanced Slip]` and `From #ref_slip_no`.
  - **Temp Receive (`wasoli > 0`)**: Driver settles an old loan chit attached to a permanent slip. **CRITICAL DOMAIN INVARIANT**: Only fresh fuel pumped today (`issue_quantity`) is counted in today's nozzle volume. Historical loan fuel (`wasoli`) flowed weeks ago on its original loan date and is strictly excluded from today's nozzle throughput. Displayed with badge `[Settled Loan #temp_slip_no (wasoli Ltr)]`.
  - **Temporary Loan (`Temporary Slip`)**: Driver takes fuel on loan. Physical fuel dispensed today advances nozzle meter throughput. Displayed with badge `[Temporary Slip]`.
  $$\mathbf{Credit\ Issued\ Litres} = \sum \text{issue\_quantity}, \quad \mathbf{Credit\ Fuel\ Value\ (Rs.)} = \sum \text{amount}$$
- **Card Machine Sales**:
  $$\text{Card Litres} = \sum \text{quantity}, \quad \text{Gross Card} = \sum \text{amount}$$
  $$\text{Bank Charges} = \sum \text{service\_charges}, \quad \mathbf{Net\ Bank\ Deposit\ (Rs.)} = \sum \text{net\_amount}$$

### 3. Settlement Reconciliation & Variance Detection
$$\text{Total Settled Litres} = \text{Cash Litres} + \text{Credit Issued Litres} + \text{Card Litres}$$
$$\text{Total Settled Amount (Rs.)} = \text{Cash Amount} + \text{Credit Fuel Value} + \text{Card Gross Amount}$$
$$\mathbf{Volume\ Variance\ (\Delta L)} = \text{Total Settled Litres} - \text{Total Net Sale (Meter)}$$
$$\mathbf{Financial\ Variance\ (\Delta Rs.)} = \text{Total Settled Amount} - \text{Gross Meter Revenue}$$

- $\Delta = 0$: Reconciled (Perfect exact match).
- $\Delta < 0$: Shortage (Dispensed fuel exceeds recorded collections).
- $\Delta > 0$: Excess / Surplus (Recorded collections exceed meter counter).

### 4. Nozzle Equipment Expenses & Net Yield
$$\mathbf{Nozzle\ Expenses\ (Rs.)} = \sum_{\text{tbl\_expenses}} \text{amount} \quad (\text{WHERE nozzle\_id} = :id)$$
$$\mathbf{Net\ Nozzle\ Operating\ Yield\ (Rs.)} = \text{Gross Meter Revenue} - \text{Nozzle Expenses}$$

---

## 4. Automated Cash Sale Synchronization

Whenever a **Meter Reading**, **Credit Sale**, or **Card Sale** is added, updated, or deleted, the system executes:
```php
require_once __DIR__ . '/../include/cash_automation_helper.php';
sync_shift_cash_sales($connection, $date, $shift_id);
```
- If a meter reading exists for that `(date, shift_id)`, Cash Sale is automatically updated:
  $$\text{Cash Litres} = \max\Big(\text{Net Meter} - (\text{Credit} + \text{Card}),\ 0\Big)$$
  $$\text{Cash Amount} = \text{Cash Litres} \times \text{Fuel Rate}$$
- If a user manually edits a cash sale entry in `cash-sales/edit-cash-sale.php`, `is_manual_override = 1` is flagged so background recalculations will not overwrite custom managerial adjustments.
- When adding cash sales manually in `cash-sales/add-cash-sale.php`, an AJAX pre-check detects existing readings and provides an interactive modal asking to edit the existing reading.

---

## 5. UI Architecture & Components

1. **Top Header & Meta Badges**: Deep Navy theme `#04204e`, Title, active Nozzle name (or All Nozzles scope), Tank name, Product name, Running Meter counter, Export PDF button, Print button, Reset button.
2. **Filter Card**: Nozzle (Optional dropdown: defaults to *-- All Dispensing Nozzles (Station Summary) --*), Shift (Optional dropdown: All Shifts or Shift 1/2), Date (Single date picker, defaults to today), Generate Report button.
3. **6 Responsive KPI Cards Grid**:
   - ⛽ **Net Sale (Meter)**: Litres & Gross Revenue.
   - 💵 **Cash Sales**: Litres & Rupees.
   - 📑 **Credit Sales**: Issued Litres & Rupees.
   - 💳 **Card Sales**: Litres, Gross Rupees, & Net Deposit.
   - 🛠️ **Expenses**: Combined Nozzle + General Station costs.
   - 📈 **Net Operating Yield**: Net financial contribution.
4. **Audit Reconciliation Box**: Side-by-side volume comparison, total receipts, and variance indicator badge.
5. **Fuel Product Rollup Summary (All-Nozzles Mode)**: Summary table by fuel product (Petrol, Diesel) with nozzle count, volume, revenue, and market share.
6. **Nozzle Comparison & Reconciliation Matrix (All-Nozzles Mode)**: Executive side-by-side audit table listing all active nozzles with opening/closing meters, volume, revenue, payment channel breakdown, financial variance, status badge, and direct audit drill-down link.
7. **5 Itemized Detail Tables (with dynamic Nozzle column in Station View)**:
   - Physical Shift Meter Readings
   - Cash Sales Breakdown
   - Credit Sales Slips (with Badges: `[Balanced Slip]`, `[Settled Loan #... (wasoli)]`, `[Temporary Slip]`, `[Remaining Balance]`)
   - Card Machine / POS Transactions
   - Equipment Maintenance & Expenses

---

## 6. PDF Companion (`reports/generate-pdf-nozzle-report.php`)

- Print-ready A4 CSS layout (`@media print`).
- Dual-mode support: Single Nozzle Audit or Station-Wide Summary (`nozzle_id=0`).
- Station branding header from `get_station_settings($connection)`.
- Concise executive audit tables matching web KPIs.
- Dispensing Nozzles Comparative Audit Matrix when exporting station summary.
- Domain settlement badges for credit slips.
- Formal 3-signatory verification block:
  - Prepared By (Shift Cashier)
  - Audited By (Station Accountant)
  - Approved By (Station Manager)
- Auto print trigger: `window.onload = function() { window.print(); };`.

---

## 7. Role-Based Access Control (RBAC) & Security

- Authentication required: `userloggedin()`.
- Permission check:
  ```php
  if (!has_permission('reports', 'show') && !has_permission('meter_readings', 'show')) {
      header('Location: ../dashboard.php');
      exit;
  }
  ```
- All database queries strictly enforce soft-delete protection:
  ```sql
  AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
  ```

---

## 8. Automated Test Suite (`tests/daily-nozzle-report/`)

A dedicated suite of 20 automated tests validates all reporting logic against `ppms_test`:
```bash
d:\xampp\php\php.exe tests/run_all_tests.php --suite=daily-nozzle-report
```
- **TC-NOZ-01**: Filter routing & dual-scope handling (Clean state empty structure on single nozzle vs. Station Summary generation when `nozzle_id = 0`).
- **TC-NOZ-02 to 04**: Date and shift filter validation (Default single date today, all shifts combined vs single shift filtering).
- **TC-NOZ-05 to 07**: Meter volume & test deduction, cash sales aggregation, and permanent credit sales vouchers aggregation.
- **TC-NOZ-08**: Balanced Slip handling (Prepaid balance claimed, physical volume accounted, Rs. 0 charge).
- **TC-NOZ-09**: Temp Receive settlement (Fresh fuel counted today, wasoli loan chit isolated).
- **TC-NOZ-10**: Temporary Loan Slip handling (Direct loan issued today accounted in volume and revenue).
- **TC-NOZ-11**: Card Sales aggregation & POS terminal tracking.
- **TC-NOZ-12 to 14**: Volumetric & financial variance audit (Zero variance balanced, volumetric shortage detection, volumetric excess detection).
- **TC-NOZ-15 to 17**: Nozzle expense isolation, Net Operating Yield, and multi-nozzle partitioning.
- **TC-NOZ-18 to 19**: Soft-delete exclusion audit and PDF generator HTML/domain badges rendering.
- **TC-NOZ-20**: Station-Wide All-Nozzles Performance & Reconciliation Audit (`nozzle_id = 0`) - verifies multi-nozzle aggregation across meter readings, cash, credit vouchers, card POS, nozzle & general expenses, volumetric & financial variance, Balanced status, Net Station Yield, and PDF comparative audit matrix rendering.

---

## 9. File Manifest

| File Path | Description |
| :--- | :--- |
| `reports/nozzle-report.php` | Main interactive web report supporting both Station-Wide Summary (`nozzle_id = 0`) and Individual Nozzle Audit (`nozzle_id > 0`). |
| `reports/generate-pdf-nozzle-report.php` | Dedicated print-ready and PDF export companion supporting both station-wide comparative matrix and single-nozzle breakdown. |
| `include/nozzle_report_helper.php` | Shared reporting query & calculation engine (`get_daily_nozzle_report_data` & `get_daily_station_report_data`). |
| `include/cash_automation_helper.php` | Reactive cash sales synchronization helper across all transaction modules. |
| `cash-sales/ajax-check-existing-cash.php` | Real-time AJAX endpoint detecting existing cash entries. |
| `tests/daily-nozzle-report/` | Dedicated automated test suite with 20 modular test cases (TC-01 through TC-20). |
| `markdown/nozzle_report.md` | Living technical specification and architectural documentation (this file). |

