# Card Machine Settlement Report Implementation Plan (`markdown/card_report_plan.md`)

## 1. Context & Objectives
- **Target Feature**: Dedicated Card Machine Settlement Report (`reports/card-report.php`) and PDF companion (`reports/generate-pdf-card-report.php`).
- **Core Concept**: Modeled after a physical bank POS terminal end-of-day settlement receipt, showing high-level executive numbers:
  - Total Fuel Sales Volume (Litres)
  - Total Swipes / Slips count
  - Total Gross Sales Amount (Rs.)
  - Total Bank Service Fee Deductions (Rs.)
  - Total Net Card Revenue received by station (Rs.)
- **Strict Exclusion**: **No raw transaction details** (no individual swipe chits, customer chits, or vehicle rows).

---

## 2. Filter Architecture Plan

1. **Date Range**:
   - `From Date` & `To Date` inputs.
   - Quick one-click preset buttons: **Today**, **Yesterday**, **Current Month**, and **Last 30 Days**.
   - Default when opening: Current Month-to-Date (`date('Y-m-01')` to `date('Y-m-d')`).

2. **Card Machine Filter**:
   - Dropdown options:
     - `-- All Card Machines --` (`card_machine_id = 0`)
     - Individual active POS machines from `tbl_card_machines` (e.g. Meezan Bank, HBL, Bank Alfalah).

3. **Shift Filter**:
   - Dropdown options:
     - `-- Both Morning & Evening (All Shifts) --` (`shift_id = 0`)
     - Individual active shifts loaded dynamically from `tbl_shifts` (`Morning`, `Evening`, etc.).

---

## 3. Implementation Steps & Execution Checklist

- [x] **Phase 1: Shared Helper Engine (`include/card_report_helper.php`)**
  - Implement `get_card_report_data($connection, $from_date, $to_date, $card_machine_id, $shift_id)`.
  - Implement `get_active_card_machines($connection)`.
  - Implement `get_active_shifts_list($connection)`.
  - Ensure strict soft-delete exclusion (`deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00'`).

- [x] **Phase 2: Interactive Web Controller (`reports/card-report.php`)**
  - Navy header `#04204e` with metadata and print button.
  - Quick preset buttons row + Filter form.
  - Top 5 Executive KPI cards.
  - Section A: POS Card Machine Performance Matrix (rollup per terminal with grand totals footer).
  - Section B: Daily Shift Settlement Rollup (rollup per date/shift/machine with grand totals footer).

- [x] **Phase 3: Print & PDF Companion (`reports/generate-pdf-card-report.php`)**
  - A4 printable statement with station branding from `include/settings_helper.php`.
  - Auto-print JavaScript trigger (`window.onload = function() { window.print(); };`).

- [x] **Phase 4: Navigation Bar Integration (`include/navbar.php`)**
  - Added `Card Machine Report` link under the **Reports** dropdown with icon `fas fa-credit-card text-primary`.

- [x] **Phase 5: Living Specification Documentation (`markdown/card_report.md`)**
  - Documented schema, formulas, filter parameters, and file manifest.

- [x] **Phase 6: Automated Test Suite (`tests/card-report/`)**
  - `TC-CARD-01`: Date range boundary filtering (**PASS**).
  - `TC-CARD-02`: Machine filter isolation & rollup (**PASS**).
  - `TC-CARD-03`: Shift isolation (Morning vs. Evening vs. Both) (**PASS**).
  - `TC-CARD-04`: Mathematical invariant consistency (**PASS**).
  - `TC-CARD-05`: Soft-delete exclusion audit (**PASS**).
  - `TC-CARD-06`: PDF generator clean HTML rendering (**PASS**).
