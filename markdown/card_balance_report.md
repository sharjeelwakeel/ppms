# Card Sales Recovery & Outstanding Balance Report Module (`markdown/card_balance_report.md`)

## 1. Overview & Purpose
The **Card Sales Recovery & Outstanding Balance Report** (`reports/card-balance-report.php`) and its companion print-ready A4 PDF export (`reports/generate-pdf-card-balance-report.php`) provide station owners, accountants, and auditors with dedicated accounts receivable reconciliation for bank POS card machine settlements.

It answers the essential financial audit questions:
1. **Total Card Sales**: How much total fuel was sold through card machines?
2. **Net Expected from Bank**: How much should the bank deposit after deducting merchant fees?
3. **Amount Received ("How Much Get")**: How much money has actually been received into the station bank accounts?
4. **Outstanding Balance ("How Much Balance")**: How much money is still unpaid / pending from the bank?
5. **Recovery Rate %**: What percentage of card sales has been successfully collected?

---

## 2. Core Mathematical Invariants

1. **Total Gross Sales**:
   $$\mathbf{Total\ Gross\ Sales} = \sum (\text{s.amount})$$

2. **Total Bank MDR Service Fee**:
   $$\mathbf{Total\ Service\ Charges} = \sum (\text{s.service\_charges})$$

3. **Total Net Expected from Bank**:
   $$\mathbf{Total\ Net\ Expected} = \sum (\text{s.net\_amount}) = \mathbf{Total\ Gross\ Sales} - \mathbf{Total\ Service\ Charges}$$

4. **Amount Received ("How Much Get")**:
   $$\mathbf{Total\ Received} = \sum (\text{s.paid\_amount})$$

5. **Outstanding Balance ("How Much Balance")**:
   $$\mathbf{Total\ Balance\ Due} = \sum (\text{s.net\_amount} - \text{s.paid\_amount})$$

6. **Recovery Rate %**:
   $$\mathbf{Recovery\ Rate\ \%} = \left(\frac{\mathbf{Total\ Received}}{\mathbf{Total\ Net\ Expected}}\right) \times 100$$

7. **Settlement Status Logic**:
   - If $\text{Balance Due} \le 0.00$ and $\text{Net Expected} > 0.00 \rightarrow$ **`Paid`**
   - If $\text{Total Received} > 0.00$ and $\text{Balance Due} > 0.00 \rightarrow$ **`Partial`**
   - If $\text{Total Received} = 0.00 \rightarrow$ **`Unpaid`**

---

## 3. Database Schema & Query Patterns

Data is queried directly from `tbl_card_sale_settlements`, joined with `tbl_card_machines` and `tbl_shifts`:

```sql
SELECT 
    COUNT(s.id) AS total_batches,
    COALESCE(SUM(s.no_of_cards), 0) AS total_swipes,
    COALESCE(SUM(s.amount), 0) AS total_gross_amount,
    COALESCE(SUM(s.service_charges), 0) AS total_service_charges,
    COALESCE(SUM(s.net_amount), 0) AS total_net_expected,
    COALESCE(SUM(s.paid_amount), 0) AS total_paid_amount,
    COALESCE(SUM(s.net_amount - s.paid_amount), 0) AS total_balance_due
FROM tbl_card_sale_settlements s
LEFT JOIN tbl_card_machines cm ON s.card_machine_id = cm.id
LEFT JOIN tbl_shifts sh ON s.shift_id = sh.id
WHERE s.settlement_date >= :from_date 
  AND s.settlement_date <= :to_date
  AND (s.deleted_at IS NULL OR s.deleted_at = '0000-00-00 00:00:00');
```

---

## 4. Multi-Dimensional Filter Capabilities

| Filter Field | Form Control | Default Value | Accepted Values |
| :--- | :--- | :--- | :--- |
| **From Date** | HTML5 `type="date"` | `Y-m-01` (1st of month) | Any valid `YYYY-MM-DD` |
| **To Date** | HTML5 `type="date"` | `Y-m-d` (Today) | Any valid `YYYY-MM-DD` |
| **Quick Presets** | One-click badges | Current Month | Today, Yesterday, Current Month, Last 30 Days |
| **Card Machine** | Dropdown | `0` (All Machines) | `0` or active `tbl_card_machines.id` |
| **Shift** | Dropdown | `0` (Both Shifts) | `0` or active `tbl_shifts.id` |
| **Status** | Dropdown | `all` | `all`, `outstanding`, `paid`, `unpaid`, `partial` |

---

## 5. Report Structure & Views

### A. Executive KPI Cards (Top 5 Metrics)
1. 💳 **Total Card Sales**: Gross amount swiped across all POS terminals.
2. 🏦 **Net Expected**: Money expected from bank after deducting MDR fees.
3. 💰 **Amount Received (Get)**: Total money deposited into station accounts (Green).
4. ⏳ **Balance Due**: Uncollected money still owed by the bank (Red).
5. 📊 **Recovery Rate**: Collection percentage progress bar.

### B. POS Card Machine Recovery Summary Table
Rollup grouped by `s.card_machine_id`:
- Card Machine / POS Terminal Name
- Total Batches & Swipes
- Gross Sales (Rs.)
- Bank MDR Fee Deducted (Rs.)
- Net Expected from Bank (Rs.)
- **Amount Received / Get (Rs.)**
- **Outstanding Balance (Rs.)**
- Recovery %
- Status Badge (`Paid`, `Partial`, `Unpaid`)

### C. Daily Shift Settlement & Balance Rollup Table
Grouped by Date + Shift + Machine + Batch:
- Settlement Date
- Shift Name (`Morning` / `Evening`)
- Card Machine Name
- Batch No
- Cards Swiped
- Gross Amount (Rs.)
- Service Fee (Rs.)
- Net Expected (Rs.)
- **Amount Received / Get (Rs.)**
- **Balance Due (Rs.)**
- Settlement Status Badge

---

## 6. Role-Based Access Control (RBAC) & Security

- Authentication required: `userloggedin()`.
- Permission check:
  ```php
  if (!has_permission('reports', 'show') && !has_permission('card_sales', 'show') && !has_permission('accounts', 'show')) {
      header('Location: ../dashboard.php');
      exit;
  }
  ```
- All queries strictly enforce soft-delete protection:
  ```sql
  AND (s.deleted_at IS NULL OR s.deleted_at = '0000-00-00 00:00:00')
  ```

---

## 7. File Manifest

| File Path | Description |
| :--- | :--- |
| `include/card_balance_report_helper.php` | Calculation & query helper engine with self-healing schema migration. |
| `reports/card-balance-report.php` | Main interactive web report with filters, 5 KPI cards, and recovery tables. |
| `reports/generate-pdf-card-balance-report.php` | Dedicated print-ready and PDF export companion with station branding. |
| `include/navbar.php` | Reports dropdown navigation link. |
| `markdown/card_balance_report.md` | Living technical specification and architectural documentation (this file). |
| `tests/card-balance-report/` | Complete 7-test automated verification suite. |

---

## 8. Automated Test Suite (`tests/card-balance-report/`)

| Test Code | Script File | Description | Assertions |
| :--- | :--- | :--- | :--- |
| **TC-CBR-01** | `test_01_date_range_boundary_filtering.php` | Verifies date boundary window filtering and isolation. | 6 / 6 Passed |
| **TC-CBR-02** | `test_02_machine_filter_isolation.php` | Verifies single POS machine filter vs. all-machines aggregation matrix. | 10 / 10 Passed |
| **TC-CBR-03** | `test_03_shift_filter_isolation.php` | Verifies Morning (`shift_id = 1`) vs. Evening (`shift_id = 2`) vs. Both (`shift_id = 0`). | 17 / 17 Passed |
| **TC-CBR-04** | `test_04_recovery_and_balance_math_integrity.php` | Validates Net Expected, Paid Amount, Balance Due, and Recovery % across Paid, Partial, and Unpaid. | 15 / 15 Passed |
| **TC-CBR-05** | `test_05_status_filter_isolation.php` | Verifies payment status filters (`all`, `outstanding`, `paid`, `unpaid`, `partial`). | 20 / 20 Passed |
| **TC-CBR-06** | `test_06_soft_delete_exclusion.php` | Verifies that records with `deleted_at IS NOT NULL` are excluded from all totals. | 8 / 8 Passed |
| **TC-CBR-07** | `test_07_pdf_generator_output.php` | End-to-end buffer rendering test of `generate-pdf-card-balance-report.php`. | 10 / 10 Passed |

