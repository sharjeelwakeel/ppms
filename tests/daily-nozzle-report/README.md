# Daily Nozzle Performance & Settlement Test Suite (`tests/daily-nozzle-report/`)

## 1. Overview & Golden Invariants
This dedicated test suite validates the **Daily Nozzle Report (`reports/nozzle-report.php`)**, its **PDF Export (`reports/generate-pdf-nozzle-report.php`)**, and its core aggregation engine (`include/nozzle_report_helper.php`).

The Daily Nozzle Report provides an end-of-day mathematical and financial audit reconciling physical dispensing meters against financial settlements (Cash, Credit, Card) and operational nozzle maintenance expenses.

### Primary Mathematical Invariants:

1. **Physical Meter Net Sale**:
   $$\mathbf{Net\ Sale\ (Ltr)} = \mathbf{Closing\ Reading} - \mathbf{Opening\ Reading} - \mathbf{Calibration/Test\ Reading}$$
   $$\mathbf{Meter\ Gross\ Revenue} = \mathbf{Net\ Sale\ (Ltr)} \times \mathbf{Fuel\ Rate}$$

2. **Total Settled Litres**:
   $$\mathbf{Total\ Settled\ Litres} = \mathbf{Cash\ Litres} + \mathbf{Credit\ Issued\ Litres} + \mathbf{Card\ Litres}$$

3. **Total Settled Amount**:
   $$\mathbf{Total\ Settled\ Amount} = \mathbf{Cash\ Amount} + \mathbf{Credit\ Fuel\ Value} + \mathbf{Card\ Gross\ Amount}$$

4. **Volumetric & Financial Variance**:
   $$\mathbf{Volume\ Variance} = \mathbf{Total\ Settled\ Litres} - \mathbf{Physical\ Meter\ Net\ Sale}$$
   $$\mathbf{Financial\ Variance} = \mathbf{Total\ Settled\ Amount} - \mathbf{Meter\ Gross\ Revenue}$$
   - **Variance = 0.00**: Perfect Reconciliation.
   - **Variance < 0.00**: Shortage (Volume/Cash deficit).
   - **Variance > 0.00**: Excess (Volume/Cash surplus).

5. **Net Nozzle Operating Yield**:
   $$\mathbf{Net\ Nozzle\ Yield} = \mathbf{Meter\ Gross\ Revenue} - \mathbf{Nozzle\ Maintenance\ Expenses}$$

---

## 2. In-Depth Credit Sales Domain Mechanics in Daily Nozzle Report

Credit sales at a fuel station have unique physical dispensing vs. financial billing characteristics. The Nozzle Report must reconcile **physical fuel that flowed through the nozzle today** against the **financial ledger**.

```
                           +-----------------------------------------------+
                           |        PHYSICAL NOZZLE DISPENSER TODAY        |
                           |  (Totalizer advances only for fuel pumped)    |
                           +-----------------------+-----------------------+
                                                   |
                     +-----------------------------+-----------------------------+
                     |                                                           |
                     v                                                           v
       [ FRESH FUEL PUMPED TODAY ]                                 [ NO FUEL PUMPED TODAY ]
   - Standard Slip: issue_quantity                             - Historical Wasoli (Loan Chit)
   - Balanced Slip: issue_quantity                               (Pumped weeks ago on loan date;
   - Temp Slip: issue_quantity                                    settled financially today only)
                     |                                                           |
                     v                                                           v
   +------------------------------------+                      +------------------------------------+
   |  COUNTS TOWARDS NOZZLE VOLUME      |                      |  EXCLUDED FROM NOZZLE VOLUME       |
   |  total_credit_issued_litres        |                      |  (Prevents False Surplus / Excess) |
   +------------------------------------+                      +------------------------------------+
```

---

### Scenario A: Petrol on Balance (`Balanced Slip` / Prepaid Balance Claim)

#### 1. Real-World Business Context:
A corporate vehicle arrived on a prior date with a 100 Litre voucher. The vehicle tank was full after taking only 60 Litres. The station issued an official **Balance Voucher** for the remaining 40 Litres. The customer company was already billed (or prepaid) for the entire 100 Litres on the original date.

#### 2. What Happens at the Dispenser Today:
The driver returns today with the Balance Voucher to claim the remaining 40 Litres:
- The nozzle pump runs and dispenses **40.00 Litres** of physical fuel into the vehicle.
- The physical nozzle meter counter advances by **+40.00 Litres**.
- The cashier generates a credit record with `slip_type = 'Balanced Slip'`, referencing `ref_slip_no = 'ORIG-100'`.
- Customer financial charge is **`Rs. 0.00`** because the customer already paid on the original invoice.

#### 3. How the Report and Test Case Act (`test_08_balanced_slip_handling.php`):
- **If the report ignored this slip because `amount == 0`**:
  The report would compare 40 Litres dispensed by the meter against 0 Litres settled, displaying a **false Shortage of -40.00 Litres**!
- **How the Report Correctly Acts**:
  1. The report queries `issue_quantity` (40.00 Ltr) and includes it in `total_credit_issued_litres` and `total_settled_litres`.
  2. Billed charge is `Rs. 0.00`, displayed with the badge `<span class="badge badge-warning text-dark"><i class="fas fa-balance-scale mr-1"></i>Balance Claimed</span>` and sub-text `Prepaid Balance Claimed (Rs. 0.00)`.
  3. **Reconciliation Invariant**:
     $$\text{Meter Net Sale (40 Ltr)} - \text{Total Settled (40 Ltr)} = \mathbf{0.00\text{ Ltr Variance (Reconciled!)}}$$
- **Test Case Validations**:
  - `slip_type == 'Balanced Slip'`
  - `charge_amount == 0.00` (Prepaid)
  - `total_credit_issued_litres == 40.00` (Meter throughput satisfied)
  - `volume_variance == 0.00` (Zero variance)

---

### Scenario B: Temp Receive (`wasoli` Loan Chit Attached)

#### 1. Real-World Business Context:
Two weeks ago, a driver needed emergency fuel without a voucher or cash. Management authorized a **Temporary Loan Slip** for 25 Litres (`slip_type = 'Temporary Slip'`). That 25 Litres flowed through the nozzle **two weeks ago**.

#### 2. What Happens at the Dispenser Today:
The driver arrives today with an official corporate voucher for 50 Litres:
- **Loan Settlement (`wasoli`)**: 25 Litres of the voucher is used to pay off the old temporary loan chit (`wasoli = 25.00 Ltr`, `temp_slip_no = 'TMP-88'`).
- **Fresh Fuel Pumped Today (`issue_quantity`)**: The driver only takes **15.00 Litres** of fresh fuel today.
- **Remaining Balance (`balance_1`)**: The remaining 10 Litres is left as balance (`50 - 25 - 15 = 10 Ltr`).
- **Financial Billing**: The customer ledger is charged for the voucher quota + settled loan amount.

#### 3. How the Report and Test Case Act (`test_09_temp_receive_settlement.php`):
- **If the report erroneously used total quota (`quantity = 50 Ltr`) or added `wasoli` (25 Ltr) to today's volume**:
  The report would report that 50 Ltr or 75 Ltr was pumped today, when the physical nozzle totalizer only turned by 15.00 Ltr! This would create an **artificial, false Surplus of +35 Ltr or +60 Ltr**!
- **How the Report Correctly Acts**:
  1. The nozzle report's settlement engine uses strictly `issue_quantity = 15.00 Ltr` for today's volumetric audit.
  2. Historical loan fuel (`wasoli = 25.00 Ltr`) is **strictly excluded** from today's physical nozzle throughput.
  3. The credit table displays the badge `<span class="badge badge-info"><i class="fas fa-link mr-1"></i>Settled Loan #TMP-88 (25.00 Ltr)</span>` so auditors can immediately verify why the voucher amount is higher than the physical fuel pumped today.
  4. **Reconciliation Invariant**:
     $$\text{Today's Meter Net (15 Ltr)} - \text{Today's Issued Fuel (15 Ltr)} = \mathbf{0.00\text{ Ltr Variance}}$$
- **Test Case Validations**:
  - `wasoli == 25.00`
  - `total_credit_issued_litres == 15.00` (Fresh fuel only)
  - `volume_variance == 0.00` (No double-counting of past loan fuel)

---

### Scenario C: Temporary Loan Slip (`Temporary Slip`)

#### 1. Real-World Business Context:
A trusted fleet vehicle arrives after bank hours without cash or corporate slip. The station supervisor issues fuel on a **Temporary Loan Chit** (`slip_type = 'Temporary Slip'`).

#### 2. What Happens at the Dispenser Today:
- The pump runs and dispenses **25.00 Litres** into the vehicle.
- The physical nozzle meter counter advances by **+25.00 Litres**.
- `tbl_meter_reading_credit_sales` records `slip_type = 'Temporary Slip'`, `issue_quantity = 25.00`, `is_returned = 0`.

#### 3. How the Report and Test Case Act (`test_10_temporary_loan_slip.php`):
- **If the report excluded temporary loan slips**:
  The report would show 25 Litres flowed out of the nozzle but 0 Litres settled $\rightarrow$ causing a **false Shortage of -25.00 Litres**!
- **How the Report Correctly Acts**:
  1. The report includes `issue_quantity = 25.00 Ltr` in `total_credit_issued_litres`.
  2. Displays badge `<span class="badge badge-danger"><i class="fas fa-hand-holding mr-1"></i>Loan Slip</span>`.
  3. Reconciles physical fuel throughput: $25\text{ Ltr Meter} - 25\text{ Ltr Settled} = \mathbf{0.00\text{ Ltr Variance}}$.
- **Test Case Validations**:
  - `slip_type == 'Temporary Slip'`
  - `is_returned == 0`
  - `total_credit_issued_litres == 25.00`
  - `volume_variance == 0.00`

---

### Scenario D: Standard Corporate Voucher (`Permanent Slip`)

#### 1. Real-World Business Context:
A corporate vehicle presents an authorized monthly fuel chit for 100 Litres (`slip_type = 'Permanent Slip'`). The vehicle takes 80 Litres, leaving 20 Litres balance.

#### 2. How the Report and Test Case Act (`test_07_credit_sales_aggregation.php`):
- Physical volume dispensed today = `issue_quantity = 80.00 Ltr`.
- Billed quota = `quantity = 100.00 Ltr` at rate Rs. 200 = `amount = Rs. 20,000.00`.
- The report credits `80.00 Ltr` towards physical nozzle volume.
- Displays `<span class="badge badge-light border text-muted">Remaining: 20.00 Ltr</span>`.

---

## 3. Concrete End-to-End Walkthrough: Partial Fill (Day 1) vs. Claiming Balance (Day 2)

### The Business Example:
- **Corporate Voucher**: Authorized for **50.00 Litres** @ **Rs. 200.00 / Litre** = **Rs. 10,000.00** total value.
- **Day 1**: Driver arrives, tank is full after taking **30.00 Litres**. The station issues a Balance Slip for the remaining **20.00 Litres**.
- **Day 2**: Driver returns with the Balance Slip to pump and claim the remaining **20.00 Litres**.

---

### Step 1: What Appears in the Nozzle Report on Day 1 (30 Ltr Taken / 20 Ltr Balance)

#### A. Top KPI Cards on Day 1:
- ⛽ **Net Sale (Meter) Card**: **`30.00 Ltr`** | `Rs. 6,000.00` (Gross Dispensed: 30.00 Ltr)
- 📑 **Credit Sales Card**: **`30.00 Ltr`** | `Rs. 10,000.00` (1 Slip Recorded)
  *(Primary volumetric figure reflects the 30.00 Ltr physical throughput delivered today)*
- 💵 **Cash Sales Card**: `0.00 Ltr` | `Rs. 0.00`
- 💳 **Card Sales Card**: `0.00 Ltr` | `Rs. 0.00`

#### B. Itemized Credit Slips Table on Day 1:
| Slip No | Slip Type & Settlement | Customer Account | Vehicle No | Rate | Fuel Issued (Ltr) | Billed Quota (Ltr) | Billed Amount (Rs.) |
| :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **SL-101** | `[Permanent Slip]`<br>`[Remaining: 20.00L]` | Al-Rehman Transport | TRK-992 | Rs. 200.00 | **`30.00 Ltr`** *(in green)* | `50.00 Ltr` *(muted)* | **`Rs. 10,000.00`** |

#### C. Reconciliation Audit Box on Day 1:
```text
  PHYSICAL METER VOLUME :   30.00 Ltr   (Pump nozzle dispensed 30 Ltr)
  SETTLED FUEL VOLUME   :   30.00 Ltr   (From issue_quantity)
  VOLUMETRIC VARIANCE   :    0.00 Ltr   [Fully Reconciled (100% Balanced)]
```
*Note*: The remaining 20.00 Litres is **still in the underground tank**, so it is strictly not counted in Day 1's nozzle throughput, avoiding a false surplus of +20 Ltr.

---

### Step 2: What Appears in the Nozzle Report on Day 2 (User Claims Balance Today)

When the driver claims the 20 Litres of balance today:

#### A. Top KPI Cards on Day 2:
```
+-----------------------------------+   +-----------------------------------+   +-----------------------------------+
|    [ 1. NET SALE (METER) CARD ]   |   |     [ 2. CREDIT SALES CARD ]      |   |       [ 3. CARD SALES CARD ]      |
|                                   |   |                                   |   |       (Bank / POS Swipes)         |
|         20.00 Ltr                 |   |          20.00 Ltr                |   |          0.00 Ltr                 |
|       Rs. 4,000.00                |   |        Rs. 4,000.00               |   |          Rs. 0.00                 |
|   Gross Dispensed: 20.00 Ltr      |   |        1 Slip(s) Recorded         |   |          0 Swipes                 |
+-----------------------------------+   +-----------------------------------+   +-----------------------------------+
```

1. **⛽ Net Sale (Meter) Card**:
   - Volume: **`20.00 Ltr`** (Closing Meter minus Opening Meter = 20.00 Ltr).
   - Gross Revenue: **`Rs. 4,000.00`** ($20\text{ Ltr} \times \text{Rs. } 200$).
2. **📑 Credit Sales Card**:
   - Volume: **`20.00 Ltr`**  
     *(Accounts for the 20 Litres that physically pumped out of the nozzle into the vehicle).*
   - Amount Subtext: **`Rs. 4,000.00`** *(Fuel value, marked as Prepaid in slip ledger)*.
   - Slip Count: `1 Slip(s) Recorded`.
3. **💳 Card Sales Card (Bank/POS Machines)**:
   - Volume: **`0.00 Ltr`** | Amount: **`Rs. 0.00`**.  
     *(A Balanced Slip is a fuel voucher claim, not a bank debit/credit card swipe. Card machine sales are unaffected).*
4. **💵 Cash Sales Card**:
   - Volume: **`0.00 Ltr`** | Amount: **`Rs. 0.00`** *(No cash collected at pump; prepaid on Day 1)*.

#### B. Itemized Credit Slips Table on Day 2:
| Slip No | Slip Type & Settlement | Customer Account | Vehicle No | Rate | Fuel Issued (Ltr) | Billed Quota (Ltr) | Billed Amount (Rs.) |
| :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **BAL-202** | `[Balanced Slip]`<br>`From #SL-101`<br>`Prepaid (Rs. 0 Charge)` | Al-Rehman Transport | TRK-992 | Rs. 200.00 | **`20.00 Ltr`** *(in green)* | `20.00 Ltr` | **`Rs. 4,000.00`**<br>*(Prepaid)* |

#### C. Reconciliation Audit Box on Day 2:
```text
  PHYSICAL METER VOLUME :   20.00 Ltr   (From Net Sale Meter Card)
  SETTLED FUEL VOLUME   :   20.00 Ltr   (From Credit Sales Card issue_quantity)
  VOLUMETRIC VARIANCE   :    0.00 Ltr   [Fully Reconciled (100% Balanced)]
```

#### Why the Credit Sales Card MUST Show 20.00 Ltr:
- If the Credit Sales Card displayed `0.00 Ltr` (because the customer paid Rs. 0.00 today):
  $$\text{Settled Volume (0 Ltr)} - \text{Meter Volume (20 Ltr)} = \mathbf{-20.00\text{ Ltr DEFICIT / SHORTAGE}}$$
  The station auditor would penalize the cashier for a missing 20 Litres of fuel!
- By tracking **`20.00 Ltr`** in the Credit Sales Card, the system proves that the fuel delivered by the nozzle matches the authorized Balanced Slip drop for drop.

---

### Step 3: Grand 2-Day Combined Audit Balance

| Day | Transaction Type | Physical Meter Net | Fuel Issued | Quota Billed | Customer Charge | Nozzle Variance |
| :--- | :--- | :---: | :---: | :---: | :---: | :---: |
| **Day 1** | Permanent Voucher (`SL-101`) | **30.00 Ltr** | **30.00 Ltr** | 50.00 Ltr | Rs. 10,000.00 | **0.00 Ltr** |
| **Day 2** | Balanced Claim (`BAL-202`) | **20.00 Ltr** | **20.00 Ltr** | 20.00 Ltr | Rs. 0.00 (Prepaid) | **0.00 Ltr** |
| **Total** | **Combined Two-Day Audit** | **50.00 Ltr** | **50.00 Ltr** | **50.00 Ltr** | **Rs. 10,000.00** | **0.00 Ltr** |

---

## 4. Database Isolation Rule
All tests in this suite connect strictly to the dedicated test database **`ppms_test`** via `tests/config.php`. **Production database `ppms` is never touched.**

---

## 5. Execution Instructions

### A. Run All Tests in this Suite:
```bash
d:\xampp\php\php.exe tests/run_all_tests.php --suite=daily-nozzle-report
```

### B. Run Both Suites (Cash Automation + Daily Nozzle Report):
```bash
d:\xampp\php\php.exe tests/run_all_tests.php
```

### C. Run Any Individual Test Case:
```bash
# Test 01: Mandatory Nozzle Filter
d:\xampp\php\php.exe tests/daily-nozzle-report/test_01_mandatory_nozzle_filter.php

# Test 08: Balanced Slip (Petrol on Balance - Prepaid)
d:\xampp\php\php.exe tests/daily-nozzle-report/test_08_balanced_slip_handling.php

# Test 09: Temp Receive Settlement (wasoli loan chit)
d:\xampp\php\php.exe tests/daily-nozzle-report/test_09_temp_receive_settlement.php

# Test 12: Perfect Reconciliation
d:\xampp\php\php.exe tests/daily-nozzle-report/test_12_perfect_reconciliation.php

# Test 19: PDF Generator Rendering
d:\xampp\php\php.exe tests/daily-nozzle-report/test_19_pdf_generator_output.php
```

---

## 6. Test Cases Specification & Assertions

### Group 1: Filter Routing & Parameters
- **TC-NOZ-01 (`test_01_mandatory_nozzle_filter.php`)**: When `nozzle_id = 0`, report safely returns empty state, 0 metrics, and null selected nozzle.
- **TC-NOZ-02 (`test_02_default_date_behavior.php`)**: When `date` is blank, defaults strictly to current date `date('Y-m-d')`.
- **TC-NOZ-03 (`test_03_all_shifts_combined_agg.php`)**: When `shift_id = 0`, combines Shift 1 and Shift 2 readings and settlements.
- **TC-NOZ-04 (`test_04_single_shift_filtering.php`)**: When `shift_id = 1`, strictly excludes Shift 2 transactions.

### Group 2: Meter Readings & Financial Channels
- **TC-NOZ-05 (`test_05_meter_volume_and_revenue.php`)**: Physical meter Net Sale = Closing - Opening - Test. Calculates Gross Revenue and tracks min/max meter counters.
- **TC-NOZ-06 (`test_06_cash_sales_aggregation.php`)**: Aggregates cash sales from `tbl_meter_reading_cash_sales`.
- **TC-NOZ-07 (`test_07_credit_sales_aggregation.php`)**: Aggregates permanent voucher credit slips.
- **TC-NOZ-08 (`test_08_balanced_slip_handling.php`)**: **Balanced Slip (Petrol on Balance)**. Validates that physical litres are accounted for in nozzle settlement while customer charge is Rs. 0.00.
- **TC-NOZ-09 (`test_09_temp_receive_settlement.php`)**: **Temp Receive Settlement (`wasoli`)**. Validates that only fresh fuel pumped today (`issue_quantity`) is counted in nozzle throughput today, excluding historical `wasoli`.
- **TC-NOZ-10 (`test_10_temporary_loan_slip.php`)**: **Temporary Loan Slip**. Validates that loan fuel pumped today is counted in nozzle throughput.
- **TC-NOZ-11 (`test_11_card_sales_aggregation.php`)**: Aggregates POS machine card swipes, gross amount, service charges, and net bank deposit.

### Group 3: Reconciliation & Variance Analysis
- **TC-NOZ-12 (`test_12_perfect_reconciliation.php`)**: 1,000 Ltr Net Sale = 500 Ltr Cash + 300 Ltr Credit + 200 Ltr Card. Variance = 0.00 Ltr / Rs. 0.00.
- **TC-NOZ-13 (`test_13_volumetric_shortage_variance.php`)**: Settled volume 900 Ltr vs 1,000 Ltr meter = -100.00 Ltr shortage.
- **TC-NOZ-14 (`test_14_volumetric_excess_variance.php`)**: Settled volume 1,100 Ltr vs 1,000 Ltr meter = +100.00 Ltr surplus.

### Group 4: Maintenance Expenses & Isolation
- **TC-NOZ-15 (`test_15_nozzle_expense_filtering.php`)**: Itemized nozzle maintenance expenses strictly filtered by `nozzle_id`.
- **TC-NOZ-16 (`test_16_net_operating_yield.php`)**: Net Nozzle Operating Yield = Meter Gross Revenue - Nozzle Expenses.
- **TC-NOZ-17 (`test_17_multi_nozzle_isolation.php`)**: Nozzle #1 strictly isolates and ignores Nozzle #2 transactions on the same date/shift.
- **TC-NOZ-18 (`test_18_soft_deleted_records_exclusion.php`)**: Soft-deleted records (`deleted_at IS NOT NULL`) across all tables are strictly excluded.
- **TC-NOZ-19 (`test_19_pdf_generator_output.php`)**: End-to-end single nozzle PDF generation buffer test verifying HTML validity, company header, and settlement badges.

### Group 5: Station-Wide Aggregation & Comparative Performance (All Nozzles)
- **TC-NOZ-20 (`test_20_station_wide_all_nozzles_report.php`)**: Station-Wide All-Nozzles Performance & Reconciliation Audit (`nozzle_id = 0`). Tests multi-nozzle aggregation across meter readings, cash sales, credit vouchers, card POS, nozzle-specific vs station general expenses, variance reconciliation, station status, Net Station Yield, and PDF comparative audit matrix table generation.
