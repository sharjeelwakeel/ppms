# Customer Credit & Fuel Ledger Report Module

The **Customer Credit & Fuel Ledger Report** (`reports/customer-report.php`) and its PDF companion (`reports/generate-pdf-customer-report.php`) provide comprehensive audit-grade tracking of customer credit slips, fuel quotas, loan chits, and financial receivables.

This module strictly implements the business logic defined in [`credit_sales.md`](credit_sales.md), ensuring an unambiguous separation between **Financial Receivables (Rupees)** and **Fuel Quota Obligations (Litres)** with 100% anti-double-counting guarantees.

---

## 1. Core Architecture & Two Ledger Dimensions

The report bifurcates every transaction into two independent but correlated dimensions:

```mermaid
graph TD
    A[Customer Credit Transaction] --> B[Dimension 1: Financial Ledger]
    A --> C[Dimension 2: Fuel Quota Ledger]
    B --> D[Permanent Slips: Billed Receivables]
    B --> E[Temporary Slips: Settled in Perm or Open Loan]
    B --> F[Balanced Slips: Free / Pre-paid Rs. 0.00]
    C --> G[Quota Created: +balance_1 + balance_2]
    C --> H[Quota Claimed: -dispensed litres on Balanced Slips]
    C --> I[Net Fuel Pump Must Deliver: Max 0, Quota - Claimed]
```

### Dimension 1: Financial Ledger (Rupees)
- **Goal**: Answer *"How much money does this customer owe the petrol pump?"*
- Calculates the true billed receivable:
  $$\text{Total Invoiced Receivable} = \sum \text{Permanent Slip Charge Amounts}$$
- **Anti-Double-Counting Guarantee**:
  - When a Permanent Slip settles a Temporary Slip (Scenario 2), the loan petrol charge $(\text{wasoli} \times \text{temp\_rate})$ is billed directly on that Permanent Slip.
  - The settled Temporary Slip's own line item displays `Settled in Slip #<id>` with **Rs. 0.00** charge. The customer is never double-billed for the same petrol.
- **Balanced Slips** represent previously paid fuel claims and always carry **Rs. 0.00** charge.
- **Open Temporary Slips** represent physical loan petrol awaiting a permanent slip. They display **Rs. 0.00** in current billed receivables and are surfaced in an executive alert box as pending loan liabilities.

### Dimension 2: Fuel Quota Ledger (Litres)
- **Goal**: Answer *"How many litres of pre-billed petrol does the pump still owe the vehicle?"*
- When a customer purchases a voucher with remaining quota (e.g. 56 Ltr voucher with 30 Ltr pumped into tank and 26 Ltr stamped on the slip as balance):
  - **Quota Created**: $+(\text{balance\_1} + \text{balance\_2})\text{ Ltr}$ recorded on Permanent Slips.
  - **Quota Claimed**: $-(\text{quantity})\text{ Ltr}$ drawn against the quota on subsequent Balanced Slips.
  - **Net Fuel Pump Must Deliver**:
    $$\text{Remaining Balance} = \max(0, \sum \text{Quota Created} - \sum \text{Quota Claimed})$$
  - If the customer over-draws quota, an overdraw warning is displayed rather than allowing negative delivery liability.

---

## 2. Granular Filtering & Streamlined Filter Sequence

The report form provides intuitive, sequential filtering arranged logically from broad period to specific account:

1. **1st: Date Range (`from_date` & `to_date`)**: Limits records based on the physical credit slip date (`mrcs.slip_date`).
2. **2nd: Customer (`customer_id`)**: Selects a specific customer account or views all customers.
3. **3rd: Vehicle No (`vehicle_number`)**: Filters for a specific vehicle registration plate (e.g. `LEA-1234`).
4. **Action Buttons**: Instant search submission and filter reset.

```mermaid
graph LR
    A["1. Date Range (From/To Date)"] --> B["2. Customer Account"]
    B --> C["3. Vehicle Number"]
    C --> D["Clean Filtered Ledger View"]
```

When filtered, the slips table and customer reconciliation cards compute seamlessly over the specified criteria without unnecessary overhead or over-engineering.

---

## 3. Four Operational Scenarios in Action

The report handles all 4 transaction types identified in [`credit_sales.md`](credit_sales.md):

### Scenario 1: Standard Permanent Slip (Normal Credit Sale)
- **Description**: Customer arrives with a fresh voucher, fills fuel, and is invoiced.
- **Ledger Impact**:
  - **Issued (Ltr)**: Voucher capacity (or pumped volume if no split balance).
  - **Pumped (Ltr)**: Volume dispensed into tank (`quantity`).
  - **Balance Quota**: If voucher is not fully pumped, shows badge `+X.XX Ltr Quota`.
  - **Must Pay**: Full slip charge $\text{charge\_amount} = \text{issue\_quantity} \times \text{sale\_rate}$.

### Scenario 2: Permanent Slip Settling a Temporary Slip (Wasoli)
- **Description**: Customer arrives with a permanent slip that settles an earlier loan chit (e.g. wasoli of 10.53 Ltr from Slip #4010).
- **Ledger Impact**:
  - **Settling Permanent Slip**:
    - Displays green link badge: `🔗 Settles Temp #4010 (10.53 Ltr @ Rs. 285.00)`.
    - **Must Pay**: Includes the settled loan charge $(\text{wasoli} \times \text{temp\_rate})$ in its `charge_amount`.
  - **Settled Temporary Slip**:
    - Displays status badge: `✅ Settled in Slip #<id>`.
    - **Must Pay**: Shows `Rs. 0.00` with subtext `Billed on Slip #<id>` to prevent double charging.

### Scenario 3: Balanced Slip (Quota Claim)
- **Description**: Vehicle returns to collect fuel from a balance stamped on an earlier Permanent Slip.
- **Ledger Impact**:
  - **Slip Type Badge**: `Balanced Slip` (Info / Cyan badge).
  - **Pumped (Ltr)**: Volume pumped into the vehicle tank.
  - **Balance Quota**: Shows red reduction badge `-X.XX Ltr Claimed`.
  - **Must Pay**: **`Rs. 0.00`** with subtext `Pre-paid Quota Claim`.

### Scenario 4: Open Temporary Slip (Pending Loan Chit)
- **Description**: Driver took loan fuel on a temporary chit and the permanent voucher has not yet been processed.
- **Ledger Impact**:
  - **Slip Type Badge**: `Temporary Slip` (Warning badge).
  - **Temp. Receive Status**: `⏳ Open Loan Chit`.
  - **Must Pay**: **`Rs. 0.00`** in current ledger column with subtext `Pending Voucher`.
  - **Executive Alert Box**: Flashed at the top of the customer's ledger detailing the exact pending volume and estimated Rupees awaiting invoicing.

---

## 4. Ledger Table Columns Specification

The itemized report table renders 12 standardized columns:

| # | Column Name | Source Field / Formula | Description |
|---|---|---|---|
| 1 | `#` | Row counter | Sequential row index |
| 2 | `Slip Date` | `slip_date` | Date printed on the credit voucher |
| 3 | `Slip No` | `slip_number` | Physical paper slip number |
| 4 | `Slip Type` | `slip_type` | `Permanent Slip`, `Balanced Slip`, or `Temporary Slip` with settlement tags |
| 5 | `Vehicle No` | `vehicle_number` | Registration plate (e.g. `LE-1234`, `LES-5678`) |
| 6 | `Nozzle / Fuel` | `fuel_name` & `nozzle_name` | Fuel grade (Super / Diesel) and physical nozzle |
| 7 | `Rate` | `sale_rate` | Historical unit price per litre |
| 8 | `Issued (Ltr)` | `issue_quantity` | Capacity printed on voucher |
| 9 | `Pumped (Ltr)` | `quantity` | Physical volume dispensed through nozzle |
| 10 | `Balance Quota` | `balance_1 + balance_2` or `-quantity` | Quota generated (`+`) or quota claimed (`-`) |
| 11 | `Temp. Receive` | `wasoli` & settlement status | Loan volume and whether settled or open |
| 12 | `Must Pay (Rs.)` | `charge_amount` | Invoiced receivable billed to customer |

---

## 5. Two-Panel Reconciliation Summary & Grand Totals

Directly beneath each customer's itemized slips table, **Card 2** displays two synchronized audit panels:

### Panel A: Financial Statement (Rupees)
Audits the exact billed receivables and collections:
- **Permanent Slips (Billed Invoices)**: Total billed charges across all permanent vouchers (including settled loans).
- **Balanced Slips (Claimed Fuel Quota)**: `Rs. 0.00 (Pre-paid)`.
- **Settled Temporary Slips**: `Rs. 0.00 (Billed in Permanent Slips)`.
- **Open Temporary Slips**: Flagged as pending loan liability (Estimated Rs.) if any exist.
- **👉 TOTAL INVOICED RECEIVABLE (MUST COLLECT)**:
  $$\mathbf{Rs.\; \text{Permanent Charges}}$$

### Panel B: Fuel Quota Reconciliation (Litres)
Reconciles physical fuel balance owed to the customer:
- **Permanent Slips**: Quota created ($+ \text{balance\_1} + \text{balance\_2}$).
- **Balanced Slips**: Quota settled ($- \text{dispensed quantity}$).
- **Price Fluctuation Impact**: Litres adjusted if rate changed.
- **Total Physical Petrol Pumped**: Direct permanent + balanced delivered + temporary loan.
- **⛽ NET PETROL VOLUME PUMP MUST DELIVER**:
  $$\mathbf{\max(0,\; \text{Quota Created} - \text{Quota Settled})\text{ Ltr}}$$
- **Overdraw Detection**: If claimed litres exceed recorded quota, flags an explicit overdraw badge: `⚠️ Quota Overdrawn: X.XX Ltr`.

### Grand Summary Across All Customers
At the bottom of the report, an executive summary card aggregates:
1. **Total Fuel Dispensed**: Sum of all physical litres pumped.
2. **Total Balance Left**: Total quota litres pump must deliver across all accounts.
3. **Open Loan Fuel**: Total temporary loan litres awaiting permanent vouchers.
4. **Total Invoiced To Collect**: Total cash/bank receivables across all accounts.

---

## 6. PDF Statement Generator Parity

The print-ready PDF generator (`reports/generate-pdf-customer-report.php`) shares 100% computational and stylistic parity with the web interface:
- **Strict Theme Adherence**: Deep navy primary headers (`#04204e`), clean borders, and monospace numeric alignments.
- **Filter Date Range Subtitle**: Displays `Date Filter: DD-MM-YYYY to DD-MM-YYYY` in the letterhead when active.
- **Slips Table Parity**: Identical 12-column itemized breakdown.
- **Settlement Statement Parity**: Financial receivables and fuel quota volume reconciliation.
- **Signature Section**: Includes 3 formal sign-off boxes at the footer:
  1. *Prepared By (Pump Manager)*
  2. *Verified By (Accounts)*
  3. *Customer Signature*
- **Auto-Print Trigger**: Automatically invokes `window.print()` upon document load for one-click printing or PDF saving.

---

## 7. Verification and Integration Rules

1. **RBAC Protection**: Both `customer-report.php` and `generate-pdf-customer-report.php` enforce `check_access('reports', 'view')`.
2. **Auxiliary Column Auto-Migration**: If older database schemas lack `settled_in_slip_id` or `temp_rate`, the report auto-executes idempotent `ALTER TABLE` checks to prevent SQL failures.
3. **Multi-Vehicle Aggregation**: Customers with fleets (multiple vehicle numbers) are grouped by `customer_id`. Subtotals and ledgers reflect the customer's aggregate balance while identifying each vehicle per line.

