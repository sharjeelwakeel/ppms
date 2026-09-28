# PPMS Card Sales Recovery & Outstanding Balance Report Test Suite (`tests/card-balance-report/`)

## 1. Overview
This test suite validates the **Card Sales Recovery & Outstanding Balance Report** (`reports/card-balance-report.php`), its **Print/PDF Companion** (`reports/generate-pdf-card-balance-report.php`), and its business logic helper (`include/card_balance_report_helper.php`).

The report addresses the financial executive question: **"How much amount get from card sale, and how much balance is still pending from the bank?"**

---

## 2. Invariants & Mathematical Formulations

1. **Net Expected Amount**:
   $$\text{Net Expected} = \sum (\text{Gross Sales Amount} - \text{Bank Service Charges})$$

2. **Amount Received ("How Much Get")**:
   $$\text{Total Paid Amount} = \sum (\text{paid\_amount})$$

3. **Outstanding Balance Pending ("How Much Balance")**:
   $$\text{Total Balance Due} = \sum (\text{net\_amount} - \text{paid\_amount})$$

4. **Accounting Reconciliation Invariance**:
   $$\text{Total Net Expected} = \text{Total Paid Amount} + \text{Total Balance Due}$$

5. **Bank Recovery Percentage**:
   $$\text{Recovery Rate \%} = \left(\frac{\text{Total Paid Amount}}{\text{Total Net Expected}}\right) \times 100$$

---

## 3. Running the Test Suite

```powershell
# Run Card Balance Report Suite:
d:\xampp\php\php.exe tests/run_all_tests.php --suite=card-balance-report

# Run All System Test Suites (Isolated ppms_test DB):
d:\xampp\php\php.exe tests/run_all_tests.php
```

---

## 4. Test Cases Inventory

- **TC-CBR-01 (`test_01_date_range_boundary_filtering.php`)**: Validates date boundary filtering (`from_date` to `to_date`), verifying that transactions outside the window are excluded.
- **TC-CBR-02 (`test_02_machine_filter_isolation.php`)**: Validates filtering by specific POS terminal (`card_machine_id`), as well as consolidated multi-terminal aggregation (`card_machine_id = 0`).
- **TC-CBR-03 (`test_03_shift_filter_isolation.php`)**: Validates shift isolation (Morning `shift_id = 1`, Evening `shift_id = 2`, and Combined `shift_id = 0`).
- **TC-CBR-04 (`test_04_recovery_and_balance_math_integrity.php`)**: Validates financial formulas across Paid, Partial, and Unpaid settlement statuses, checking accounting reconciliation invariance and recovery percentages.
- **TC-CBR-05 (`test_05_status_filter_isolation.php`)**: Validates filtering by payment status (`all`, `outstanding`, `paid`, `unpaid`, `partial`).
- **TC-CBR-06 (`test_06_soft_delete_exclusion.php`)**: Validates that records with `deleted_at IS NOT NULL` are completely excluded from KPI cards, machine matrices, and shift breakdown tables.
- **TC-CBR-07 (`test_07_pdf_generator_output.php`)**: End-to-end buffer rendering test of `reports/generate-pdf-card-balance-report.php`, validating clean HTML generation, station branding, KPI boxes, matrices, and auto-print triggers.
