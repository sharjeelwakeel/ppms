# Daily Nozzle Report Module Complete Documentation (`markdown/nozzle_report.md`)

## 1. Overview
The **Daily Nozzle Report** (`reports/nozzle-report.php`) and its companion printable A4 PDF generator (`reports/generate-pdf-nozzle-report.php`) provide petrol pump owners, auditors, and station managers with a 360-degree operational and financial audit of an individual dispensing nozzle (`tbl_nozzles`).

It cross-reconciles:
1. **Physical Meter Readings**: Opening totalizer, closing totalizer, test fuel deductions, and net litres dispensed.
2. **Payment Channel Settlements**:
   - **Cash Sales**: Volume and cash collected (`tbl_meter_reading_cash_sales`).
   - **Credit Sales**: Fuel issued and billable customer slips (`tbl_meter_reading_credit_sales`).
   - **Card Sales**: Volume, gross terminal amount, service fee deductions, and net bank deposits (`tbl_meter_reading_card_sales`).
3. **Audit Reconciliation & Variance**: Side-by-side verification between physical fuel dispensed through the nozzle and recorded payment settlements.
4. **Equipment Expenses**: Maintenance, repair, calibration, and operating costs recorded on behalf of this nozzle (`tbl_expenses.nozzle_id = :id`).
5. **Net Nozzle Operating Yield**: Gross fuel revenue generated minus all nozzle-related expenses.

---

## 2. Filter Rules & Form Behavior

| Parameter | Type | Validation Rule | Default Behavior |
| :--- | :--- | :--- | :--- |
| **Nozzle (`nozzle_id`)** | Integer (FK) | **MANDATORY / REQUIRED** | If omitted, the report does not execute; an informative prompt instructs the user: *"Please Select a Dispensing Nozzle to View Report"*. |
| **Shift (`shift_id`)** | Integer (FK) | **OPTIONAL** | If empty (`0`), queries aggregate across **both / all shifts** for that day/range. If a specific shift is chosen, queries filter strictly to that shift. |
| **Date Range (`from_date`, `to_date`)** | Date (`YYYY-MM-DD`) | Standard | Defaults to the current date (`date('Y-m-d')`). Supports analyzing a single day or a custom multi-day timeframe. |

---

## 3. Mathematical Equations & Audit Reconciliation

For the selected nozzle across the date range and optional shift:

### 1. Physical Fuel Dispensed (Meter Counter)
$$\text{Opening Meter} = \min(\text{last\_reading}), \quad \text{Closing Meter} = \max(\text{current\_reading})$$
$$\text{Gross Dispensed (Ltr)} = \sum (\text{current\_reading} - \text{last\_reading})$$
$$\text{Testing Volume (Ltr)} = \sum \text{test\_reading}$$
$$\mathbf{Total\ Net\ Sale\ (Ltr)} = \sum \text{net\_sale} = \text{Gross Dispensed} - \text{Testing Volume}$$
$$\mathbf{Gross\ Meter\ Revenue\ (Rs.)} = \sum (\text{net\_sale} \times \text{price})$$

### 2. Payment Settlement Aggregations
- **Cash Sales**:
  $$\text{Cash Litres} = \sum \text{quantity}, \quad \mathbf{Cash\ Amount\ (Rs.)} = \sum \text{amount}$$
- **Credit Sales**:
  $$\text{Credit Issued Litres} = \sum \text{issue\_quantity}, \quad \mathbf{Credit\ Amount\ (Rs.)} = \sum \text{amount}$$
- **Card Machine Sales**:
  $$\text{Card Litres} = \sum \text{quantity}, \quad \text{Gross Card} = \sum \text{amount}$$
  $$\text{Bank Charges} = \sum \text{service\_charges}, \quad \mathbf{Net\ Bank\ Deposit\ (Rs.)} = \sum \text{net\_amount}$$

### 3. Settlement Reconciliation & Variance Detection
$$\text{Total Settled Litres} = \text{Cash Litres} + \text{Credit Litres} + \text{Card Litres}$$
$$\text{Total Settled Amount (Rs.)} = \text{Cash Amount} + \text{Credit Amount} + \text{Card Amount}$$
$$\mathbf{Volume\ Variance\ (\Delta L)} = \text{Total Settled Litres} - \text{Total Net Sale (Meter)}$$
$$\mathbf{Financial\ Variance\ (\Delta Rs.)} = \text{Total Settled Amount} - \text{Gross Meter Revenue}$$

- $\Delta = 0$: Reconciled (Exact match).
- $\Delta \ne 0$: Variance detected and highlighted in amber/red.

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

1. **Top Header & Meta Badges**: Deep Navy theme `#04204e`, Title, active Nozzle name, Tank name, Product name, Running Meter counter, Export PDF button, Print button, Reset button.
2. **Filter Card**: Nozzle (Required dropdown), Shift (Optional dropdown), From Date, To Date, Submit button.
3. **6 Responsive KPI Cards Grid**:
   - ⛽ **Net Sale (Meter)**: Litres & Gross Revenue.
   - 💵 **Cash Sales**: Litres & Rupees.
   - 📑 **Credit Sales**: Issued Litres & Rupees.
   - 💳 **Card Sales**: Litres, Gross Rupees, & Net Deposit.
   - 🛠️ **Nozzle Expenses**: Maintenance & repair costs.
   - 📈 **Net Nozzle Yield**: Net financial contribution.
4. **Audit Reconciliation Box**: Side-by-side volume comparison, total receipts, and variance indicator badge.
5. **5 Itemized Detail Tables**:
   - Physical Shift Meter Readings
   - Cash Sales Breakdown
   - Credit Sales Slips
   - Card Machine / POS Transactions
   - Equipment Maintenance & Nozzle Expenses

---

## 6. PDF Companion (`reports/generate-pdf-nozzle-report.php`)

- Print-ready A4 CSS layout (`@media print`).
- Station branding header from `get_station_settings($connection)`.
- Concise executive audit tables matching web KPIs.
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

## 8. File Manifest

| File Path | Description |
| :--- | :--- |
| `reports/nozzle-report.php` | Main interactive web report with filter bar, KPI cards, audit comparison, and 5 itemized tables. |
| `reports/generate-pdf-nozzle-report.php` | Dedicated print-ready and PDF export companion with station branding and signature blocks. |
| `include/cash_automation_helper.php` | Reactive cash sales synchronization helper across all transaction modules. |
| `cash-sales/ajax-check-existing-cash.php` | Real-time AJAX endpoint detecting existing cash entries. |
| `include/navbar.php` | Reports menu navigation item for Daily Nozzle Report (`fas fa-gas-pump`). |
| `markdown/nozzle_report.md` | Living technical specification and architectural documentation (this file). |
