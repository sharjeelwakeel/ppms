# Card Machine Settlement Report Module (`markdown/card_report.md`)

## 1. Overview & Purpose
The **Card Machine Settlement Report** (`reports/card-report.php`) and its companion print-ready A4 PDF export (`reports/generate-pdf-card-report.php`) provide station owners, accountants, and auditors with an executive financial summary of bank POS terminal card settlements.

Modeled after the physical end-of-day settlement receipt printed by bank POS card machines, this report focuses purely on **consolidated volume, swipes, gross sales, bank fee deductions, and net revenue**, without cluttering the screen with individual customer or vehicle transaction rows.

---

## 2. Core Mathematical Invariants

1. **Gross Card Sales (Swiped Amount)**:
   $$\mathbf{Total\ Gross\ Sales} = \sum (\text{mrcs.amount})$$

2. **Bank Service Fee (MDR Deduction)**:
   $$\mathbf{Total\ Service\ Charges} = \sum (\text{mrcs.service\_charges})$$

3. **Net Card Revenue (Bank Receivable Payout)**:
   $$\mathbf{Net\ Card\ Revenue} = \sum (\text{CASE WHEN mrcs.net\_amount} > 0 \text{ THEN mrcs.net\_amount ELSE (mrcs.amount} - \text{mrcs.service\_charges) END})$$
   $$\mathbf{Net\ Revenue} = \mathbf{Gross\ Sales} - \mathbf{Service\ Charges}$$

4. **Effective Fee & Payout Percentages**:
   $$\mathbf{Effective\ Fee\ \%} = \left(\frac{\mathbf{Total\ Service\ Charges}}{\mathbf{Total\ Gross\ Sales}}\right) \times 100$$
   $$\mathbf{Effective\ Payout\ \%} = \left(\frac{\mathbf{Total\ Net\ Revenue}}{\mathbf{Total\ Gross\ Sales}}\right) \times 100$$

---

## 3. Database Schema & Query Patterns

Data is aggregated from `tbl_meter_reading_card_sales`, joined with POS machines (`tbl_card_machines`) and station shifts (`tbl_shifts`):

```sql
SELECT 
    COUNT(mrcs.id) AS total_swipes,
    COALESCE(SUM(mrcs.quantity), 0) AS total_volume,
    COALESCE(SUM(mrcs.amount), 0) AS total_gross_amount,
    COALESCE(SUM(mrcs.service_charges), 0) AS total_service_charges,
    COALESCE(SUM(CASE WHEN mrcs.net_amount > 0 THEN mrcs.net_amount ELSE (mrcs.amount - mrcs.service_charges) END), 0) AS total_net_revenue
FROM tbl_meter_reading_card_sales mrcs
LEFT JOIN tbl_card_machines cm ON mrcs.card_machine_id = cm.id
LEFT JOIN tbl_shifts sh ON mrcs.shift_id = sh.id
WHERE mrcs.sale_date >= :from_date 
  AND mrcs.sale_date <= :to_date
  AND (mrcs.deleted_at IS NULL OR mrcs.deleted_at = '0000-00-00 00:00:00');
```

---

## 4. Multi-Dimensional Filter Capabilities

| Filter Field | Form Control | Default Value | Accepted Values |
| :--- | :--- | :--- | :--- |
| **From Date** | HTML5 `type="date"` | `Y-m-01` (1st of month) | Any valid `YYYY-MM-DD` |
| **To Date** | HTML5 `type="date"` | `Y-m-d` (Today) | Any valid `YYYY-MM-DD` |
| **Quick Presets** | One-click badges | Current Month | Today, Yesterday, Current Month, Last 30 Days |
| **Card Machine** | Dropdown | `0` (All Machines) | `0` or active `tbl_card_machines.id` |
| **Shift** | Dropdown | `0` (Both Morning & Evening) | `0` or active `tbl_shifts.id` |

---

## 5. Report Structure & Views

### A. Executive KPI Cards (Top Metrics)
1. **Total Fuel Sales Volume**: Total litres dispensed across POS transactions.
2. **Total Card Swipes**: Number of transactions processed.
3. **Total Gross Sales Amount (Rs.)**: Total sales volume swiped on machines.
4. **Total Bank Service Charges (Rs.)**: Total bank fees/commissions deducted, with effective fee %.
5. **Net Card Revenue (Rs.)**: Net money to be deposited by the bank into the station account, with effective payout %.

### B. POS Card Machine Summary Matrix
Aggregated by POS terminal (`mrcs.card_machine_id`):
- Card Machine / POS Terminal Name
- Bank Fee % (`charges_percentage`)
- Total Swipes
- Fuel Volume (Ltr)
- Gross Sales (Rs.)
- Service Fee Deducted (Rs.)
- Net Card Revenue (Rs.)
- Effective Payout %

### C. Daily Shift Settlement Rollup
Aggregated by `sale_date + shift_id + card_machine_id`:
- Date
- Shift Name (Morning / Evening)
- Card Machine
- Swipes
- Volume (Ltr)
- Gross Amount (Rs.)
- Service Fee (Rs.)
- Net Revenue (Rs.)
*(Zero individual customer/vehicle transaction rows — clean end-of-shift totals).*

---

## 6. Role-Based Access Control (RBAC) & Security

- Authentication required: `userloggedin()`.
- Permission check:
  ```php
  if (!has_permission('reports', 'show') && !has_permission('card_sales', 'show') && !has_permission('meter_readings', 'show')) {
      header('Location: ../dashboard.php');
      exit;
  }
  ```
- All queries strictly enforce soft-delete protection:
  ```sql
  AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
  ```

---

## 7. File Manifest

| File Path | Description |
| :--- | :--- |
| `include/card_report_helper.php` | Centralized data calculation and query helper engine. |
| `reports/card-report.php` | Main interactive web report with filters, KPI cards, and summary tables. |
| `reports/generate-pdf-card-report.php` | Dedicated print-ready and PDF export companion with station branding. |
| `include/navbar.php` | Navigation link in the Reports dropdown. |
| `tests/card-report/` | Dedicated automated test suite validating calculations and filters. |
| `markdown/card_report.md` | Living technical specification and architectural documentation (this file). |
