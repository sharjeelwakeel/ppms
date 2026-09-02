# Customer Credit & Fuel Ledger Report Module

The **Customer Credit & Fuel Ledger Report** (`reports/customer-report.php`) provides itemized credit slip tracking, quota balance settlement, debit/credit financial accounting, and PDF statement generation for the Petrol Pump Management System (PPMS).

---

## 1. Core Objectives & Business Rules

### Two-Tier Financial Calculation (`amount` vs `charge_amount`)
1. **`amount` (Nominal Fuel Value)**:
   - Tracks the gross market value of the fuel volume that physically leaves the pump nozzle ($\text{Quantity} \times \text{Sale Rate}$).
   - Ensures tank inventory and nozzle throughput match financial records.
2. **`charge_amount` (Customer Billable / Receivable Amount)**:
   - Tracks the exact amount the customer must pay to avoid double-billing.

### Slip Type Business Logic (Calculations Depend on QTY)
- **Calculation Rule**:
  - All fuel volume, charge amount, and ledger balances **always calculate on Qty** (`quantity`):
    $$\text{Charge Amount} = \text{Qty} \times \text{Sale Rate}$$
  - Fallback logic: if `quantity` is 0 or empty, falls back to `issue_quantity` or `wasoli`.
- **Permanent Slip (`Permanent Slip`)**:
  - Customer receives fuel on a new invoice.
  - Billed on **Qty**:
    $$\text{charge\_amount} = \text{Qty} \times \text{Sale Rate}$$
  - Quota balance (`balance_1 + balance_2`) is credited as fuel owed to the customer.
- **Balanced Slip (`Balanced Slip`)**:
  - Customer collects fuel from a previously remaining balance on an already-billed permanent slip.
  - **`charge_amount = Rs. 0.00`** (Free / Pre-charged).
  - Physical dispensed fuel (`quantity`) is deducted from the customer's balance quota.
- **Temporary Slip (`Temporary Slip` / `Tmp. Receive`) & Return Workflow**:
  - Customer took loan fuel on a temporary voucher chit and **did NOT pay on the spot**.
  - **Initial State (Pending Loan / Unpaid)**:
    - Customer owes money for this fuel.
    - **This is unpaid fuel that we MUST collect from the customer**:
      $$\text{Amount to Collect (Debit)} = \text{Tmp. Receive (wasoli)} \times \text{Sale Rate}$$
    - Added directly into the customer's total debit receivables (Must Collect).
  - **Settled State (Received Check Ticked)**:
    - When the customer returns / pays for the loan petrol, the operator checks **`[x] Received`** in Meter Reading Add/Edit, or clicks **"Mark Received"** directly in `customer-report.php`.
    - Status changes to **`Received`** (`is_returned = 1`, `returned_at = NOW()`).
    - The outstanding receivable drops to **`Rs. 0.00`** because the pump already collected the money/fuel!
    - In Card 2, it is credited under **Received Tmp. Receive (Loan Petrol Received / Settled)**.

---

## 2. Card 2: Financial Debit / Credit Settlement (Accounting Manner)

At the bottom of each customer's slip ledger, a **Double-Entry Debit / Credit Settlement Card** summarizes the customer's exact financial position and remaining fuel delivery obligation:

| Transaction Classification | Debit (Receivable to Collect) | Credit (Pre-Paid / Settled) |
|---|---|---|
| **Permanent Slips (Billed Fuel)** | $\sum(\text{Permanent Qty} \times \text{Rate})$ | — |
| **Balanced Slips (Claimed Fuel)** | — | **Rs. 0.00 (Settled / Free)** |
| **Not Received Tmp. Receive (Unpaid Loan Fuel — Must Collect)** | $\sum(\text{Tmp. Receive} \times \text{Rate})$ *(Unchecked / Not Received Slips)* | — |
| **Received Tmp. Receive (Loan Petrol Received / Settled)** | — | **$\sum(\text{Tmp. Receive} \times \text{Rate})$ (Collected)** |
| **👉 TOTAL AMOUNT WE NEED TO GET (MUST COLLECT)** | $\mathbf{Rs.\;(\text{Permanent Billed} + \text{Not Received Tmp. Receive})}$ | |
| **⛽ PETROL VOLUME WE MUST GIVE CUSTOMER** | $\mathbf{\max(0,\; \text{Permanent Quota} - \text{Balanced Slips Claimed})\text{ Ltr}}$ | |

---

## 3. 10 Critical Edge Cases Handled

1. **Over-draw of Fuel Balance**:
   - *Scenario*: Customer had 26 Ltr balance, but draws 30 Ltr on Balanced Slips.
   - *Handling*: Remaining balance is clamped with $\max(0,\; \text{Permanent Balances} - \text{Balanced Drawn})$. Overdraw volume is calculated and flagged with an alert badge: `⚠️ Quota Over-drawn by 4.00 Ltr`.
2. **Multiple Vehicles on Single Account**:
   - *Scenario*: Account has multiple vehicles (e.g. Car `LE-1234` and Truck `LES-5678`).
   - *Handling*: Ledger aggregates strictly by `account_number` (Customer ID). Each row displays its specific vehicle registration plate.
3. **Orphan Balanced Slip**:
   - *Scenario*: Operator issues a Balanced Slip without a preceding Permanent Slip balance quota.
   - *Handling*: Remaining fuel owed stays `0.00 Ltr` (no negative balance). The table indicates fuel claimed with `Rs. 0.00` charge.
4. **Temporary Slip Missing `wasoli`**:
   - *Scenario*: Operator selected Temporary Slip but left `wasoli` empty or zero.
   - *Handling*: Fallback logic: $\text{dispensed\_qty} = \text{wasoli} > 0 \;?\; \text{wasoli} : \text{quantity}$. If both 0, charges `Rs. 0.00` safely without PHP warnings.
5. **Fuel Price Fluctuations Across Slips**:
   - *Scenario*: Slip 1 rate = Rs. 280, Slip 2 rate = Rs. 290.
   - *Handling*: Every slip computes its exact financial receivable at its **historical transaction rate**: $\sum(\text{slip\_rate} \times \text{charge\_qty})$. Balanced slips stay Rs. 0.00.
6. **Permanent Slip Missing `issue_quantity`**:
   - *Scenario*: Operator filled `quantity = 56` and left `issue_quantity` empty.
   - *Handling*: Fallback logic: $\text{Charge Qty} = \text{issue\_quantity} > 0 \;?\; \text{issue\_quantity} : \text{quantity}$ ensures the customer is never under-charged.
7. **Split Balance Fields (`balance_1` and `balance_2`)**:
   - *Scenario*: Operator splits 26 into 20 and 6, or leaves one `NULL`.
   - *Handling*: Explicit float casting: `floatval(balance_1) + floatval(balance_2)` safely sums single, split, or `NULL` entries.
8. **Customer with Only Temporary Slips**:
   - *Scenario*: No permanent slips exist for the customer yet.
   - *Handling*: Petrol owed displays `0.00 Ltr`. Financial ledger shows only `[DEBIT] Under Tmp. Receive: Rs. X`.
9. **Full Balance Return (Zero Remaining)**:
   - *Scenario*: Customer had 26 Ltr balance and claimed all 26 Ltr.
   - *Handling*: Displays a clean green confirmation badge: `✅ 0.00 Ltr (All Quota Delivered)`.
10. **Mixed Fuels on Single Customer**:
    - *Scenario*: Customer purchased Super Petrol on Slip 1 and High Speed Diesel on Slip 2.
    - *Handling*: Ledger table displays the specific fuel grade and nozzle on each line. Financial Debit/Credit calculates total Rupees, while volumes reflect dispensed fuel.

---

## 4. Search-Driven Interface & PDF Export

- **Two Focused Filters Only**:
  - **Customer**: Dropdown of active customer accounts.
  - **Vehicle No**: Search input for vehicle registration plates.
  - **On-Demand Search**: The report does not query data until the user clicks **Search**.
- **PDF Export ([`reports/generate-pdf-customer-report.php`](generate-pdf-customer-report.php))**:
  - Dedicated print-ready document formatted for A4 portrait with petrol pump letterhead.
  - Itemized slips table with rates, issue quantities, quota credits/debits, and charge amounts.
  - Card 2 Financial Debit / Credit Settlement table.
  - Formal signature verification blocks (*Prepared By*, *Verified By*, *Customer Signature*).
  - Automatically invokes browser print-to-PDF on load.

---

## 5. File Architecture

| File Path | Description |
|---|---|
| `reports/customer-report.php` | Main Customer Credit & Fuel Ledger Report page with Card 2 settlement |
| `reports/generate-pdf-customer-report.php` | Print-ready PDF statement generator with signature blocks |
| `meter-readings/add-meter-reading.php` | Meter reading entry with slip type dynamic rules and charge calculation |
| `meter-readings/view-meter-reading.php` | Meter reading detail view with nominal amount and charge amount |
| `meter-readings/generate-pdf-meter-reading.php` | PDF export for shift meter readings |
| `include/navbar.php` | Navigation bar with Reports dropdown |
| `include/permissions.php` | RBAC module catalog with `'reports'` module |
| `markdown/customer_report.md` | Technical and operational specification (this file) |
