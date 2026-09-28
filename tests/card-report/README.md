# PPMS Card Machine Report Test Suite (`tests/card-report/`)

## 1. Overview
This test suite validates the **Card Machine Settlement Report** (`reports/card-report.php`), its **Print/PDF Companion** (`reports/generate-pdf-card-report.php`), and its business logic helper (`include/card_report_helper.php`).

The report provides executive aggregations of POS card terminal settlements without transaction-level clutter.

---

## 2. Invariants & Mathematical Formulations

1. **Volume Rollup**:
   $$\text{Total Fuel Volume} = \sum (\text{mrcs.quantity})$$

2. **Gross Sales Rollup**:
   $$\text{Total Gross Sales} = \sum (\text{mrcs.amount})$$

3. **Bank Service Fee (MDR Deduction)**:
   $$\text{Total Service Charges} = \sum (\text{mrcs.service\_charges})$$

4. **Net Card Revenue**:
   $$\text{Net Revenue} = \text{Total Gross Sales} - \text{Total Service Charges}$$

5. **Effective Payout Percentage**:
   $$\text{Effective Payout \%} = \left(\frac{\text{Total Net Revenue}}{\text{Total Gross Sales}}\right) \times 100$$

---

## 3. Running the Test Suite

```bash
# Run Card Report Suite:
d:\xampp\php\php.exe tests/run_all_tests.php --suite=card-report

# Run All System Test Suites:
d:\xampp\php\php.exe tests/run_all_tests.php
```

---

## 4. Test Cases Inventory

- **TC-CARD-01 (`test_01_filter_defaults_and_date_range.php`)**: Validates date boundary filtering (`from_date` to `to_date`), strictly excluding records outside the window.
- **TC-CARD-02 (`test_02_machine_filter_isolation.php`)**: Validates single machine isolation vs. all card machines aggregation into the summary matrix.
- **TC-CARD-03 (`test_03_shift_filter_isolation.php`)**: Validates shift isolation (Morning `shift_id = 1`, Evening `shift_id = 2`, and Combined `shift_id = 0`).
- **TC-CARD-04 (`test_04_aggregations_and_math_integrity.php`)**: Validates strict mathematical consistency across Gross Sales, Bank Fee Deductions, Net Revenue, and Payout %.
- **TC-CARD-05 (`test_05_soft_delete_exclusion.php`)**: Validates that records with `deleted_at IS NOT NULL` are excluded from all totals.
- **TC-CARD-06 (`test_06_pdf_generator_output.php`)**: End-to-end buffer rendering test of `reports/generate-pdf-card-report.php`.
