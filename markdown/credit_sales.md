# Credit Sale Reading Module Complete Documentation (`markdown/credit_sales.md`)

## 1. Overview
The **Credit Sale Reading** module in the Petrol Pump Management System (PPMS) manages all fuel dispensed on credit across different voucher slip classifications (`Permanent Slip`, `Balanced Slip`, `Temporary Slip`). It supports customer vehicle recognition, fuel limit checks, historical slip date pricing from `tbl_prices`, dynamic calculation of uncollected balances, interactive **Temp. Receive** popup settlements, and price-adjusted **Balanced Slip** redemptions where entitled litres adjust based on current fuel market rates.

---

## 2. Database Schema (`tbl_meter_reading_credit_sales`)

All credit transactions are stored in `tbl_meter_reading_credit_sales`:

```sql
CREATE TABLE IF NOT EXISTS `tbl_meter_reading_credit_sales` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `nozzle_id` INT(11) NOT NULL,                        -- Attached nozzle ID (tbl_nozzles)
  `sale_date` DATE NOT NULL,                           -- Header shift / daily entry date (when fuel is dispensed)
  `slip_date` DATE NOT NULL,                           -- Physical voucher slip date (supports historical pricing)
  `shift_id` INT(11) NOT NULL DEFAULT 0,              -- Station shift ID (tbl_shifts)
  `slip_no` VARCHAR(64) NOT NULL,                      -- Voucher slip number (mandatory)
  `slip_type` ENUM('Permanent Slip','Balanced Slip','Temporary Slip') NOT NULL DEFAULT 'Permanent Slip',
  `account_number` VARCHAR(128) NOT NULL,              -- Customer ID (tbl_customers.id)
  `vehicle_number` VARCHAR(64) NOT NULL,              -- Registration number
  `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,      -- Physical litres pumped into car from nozzle
  `rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,          -- Sale rate per litre applied
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,        -- Gross fuel value (qty * rate)
  `charge_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- Amount billable to customer account
  `cash_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,     -- Baseline cash rate for audit
  `issue_quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,-- Slip authorized quota
  `balance_1` DECIMAL(12,2) NOT NULL DEFAULT 0.00,     -- Uncollected balance slot 1
  `balance_2` DECIMAL(12,2) NOT NULL DEFAULT 0.00,     -- Uncollected balance slot 2
  `wasoli` DECIMAL(12,2) NOT NULL DEFAULT 0.00,        -- Temp. Receive quantity
  `temp_slip_id` INT(11) DEFAULT NULL,                 -- Linked temporary slip ID settled
  `temp_slip_no` VARCHAR(64) DEFAULT NULL,             -- Linked temporary slip voucher number
  `temp_slip_date` DATE DEFAULT NULL,                  -- Date of linked temporary slip
  `temp_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,     -- Rate on linked temporary slip date
  `ref_slip_no` VARCHAR(128) DEFAULT NULL,             -- Prior slip voucher number drawn for balanced slip
  `ref_slip_date` DATE DEFAULT NULL,                  -- Prior slip date drawn
  `settled_in_slip_id` INT(11) DEFAULT NULL,           -- Permanent slip ID that settled this temporary slip
  `is_returned` TINYINT(1) NOT NULL DEFAULT 0,         -- 0 = Open/Unsettled, 1 = Settled
  `returned_at` DATETIME DEFAULT NULL,                 -- Timestamp when settled
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_slip_date` (`slip_date`),
  KEY `idx_shift_id` (`shift_id`),
  KEY `idx_slip_no` (`slip_no`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 3. The 4 Operational Scenarios & Real-World Examples

### 📘 Scenario 1: Permanent Slip (Without Temp. Receive)

#### Business Rule
When a customer presents an authorized voucher for an issued volume (`issue_qty`), but the vehicle's fuel tank fills at a lower volume (`qty`):
- **Customer Charge**: The customer is charged for the full voucher volume:
  $$\text{charge\_amount} = \text{issue\_qty} \times \text{rate}$$
- **Physical Nozzle Advance**: The nozzle meter advances strictly by the physical litres dispensed:
  $$\Delta\text{nozzle} = \text{qty}$$
- **Remaining Balance**: The uncollected litres remain on record for future collection:
  $$\text{balance} = \max(0, \text{issue\_qty} - \text{qty}) \quad (\text{stored in } \texttt{balance\_1})$$
- **Slip Date Pricing**:
  - If `slip_date < current_date`: System queries `tbl_prices` for the price effective on that historical voucher date.
  - If `slip_date >= current_date`: Uses the current active market price.
  - Rate tier policy (`tbl_customers.fuel_rate`: `Cash` vs `Credit`) is dynamically enforced.

#### Numerical Example:
- Voucher Slip `#SL-101` is issued for **50 Litres** (`issue_qty = 50.00`).
- The vehicle tank fills at **40 Litres** (`qty = 40.00`).
- Price on slip date is **Rs. 200.00 per Litre**.
- **Customer Charge**: $50 \times 200 = \mathbf{Rs.\;10,000.00}$.
- **Nozzle Meter Advance**: $\mathbf{+40.00\text{ Litres}}$.
- **Remaining Balance**: $50 - 40 = \mathbf{10.00\text{ Litres}}$ (saved in `balance_1`).

---

### 📗 Scenario 2: Permanent Slip (With Temp. Receive Settlement)

#### Business Rule
When a customer previously took fuel on loan (Temporary Slip) and now brings a Permanent Slip:
- In the row's `Temp. Receive` column, checking the link trigger opens the **Temp. Receive Lookup Modal**.
- The operator selects the unsettled Temporary Slip (by Slip No and Date).
- The system fetches the loaned litres (`temp_qty`) and the rate effective on that loan date (`temp_rate`).
- **Customer Charge Calculation**:
  $$\text{charge\_amount} = (\text{issue\_qty} \times \text{rate}_{\text{today}}) + (\text{temp\_qty} \times \text{temp\_rate})$$
- **Physical Nozzle Advance**: Only advances by today's pumped fuel (`qty`), because the loaned fuel (`temp_qty`) was already deducted from the station meter on the loan date:
  $$\Delta\text{nozzle} = \text{qty}$$
- **Settlement**: The temporary slip is marked `is_returned = 1`, `settled_in_slip_id = current_permanent_slip_id`, preventing double claims.

#### Numerical Example:
- **Two weeks ago (Aug 20)**: Driver took **25 Litres** on loan chit `#TMP-88`. Price on Aug 20 was **Rs. 240.00/L** (Prepaid Loan Value = $25 \times 240 = \text{Rs. } 6,000$).
- **Today (Sep 06)**: Company issues Permanent Slip `#SL-500` for **50 Litres** at today's rate of **Rs. 260.00/L**.
- Car takes **50 Litres** today (`qty = 50.00`, `issue_qty = 50.00`).
- **Total Charge**:
  $$(50 \times 260) + (25 \times 240) = 13,000 + 6,000 = \mathbf{Rs.\;19,000.00}$$
- **Nozzle Meter Advance Today**: $\mathbf{+50.00\text{ Litres}}$.

---

### 📙 Scenario 3: Balanced Slip (Merging Balance 1 & Balance 2 with Price Variation)

#### Business Rule
When a driver returns to claim uncollected balance fuel from a single prior Permanent Slip:
- Selecting `Balanced Slip` opens the **Claim Balance Slip Modal**.
- The operator enters the previous Permanent Slip No and Date.
- The system reads `balance_1` and `balance_2` on that slip and **merges them**:
  $$\text{Total Balance} = \text{balance\_1} + \text{balance\_2}$$
  $$\text{Prepaid Monetary Value} = \text{Total Balance} \times \text{Original Slip Rate}$$
- **Price-Variation Adjustment Formula**:
  $$\text{Adjusted Litres} = \frac{\text{Prepaid Monetary Value}}{\text{Current Petrol Price}}$$
- **Row Population**:
  - `qty`: Populated with `Adjusted Litres`.
  - `issue_qty`: Populated with `Adjusted Litres`.
  - `balance_1` & `balance_2`: Displayed from the original slip for audit reference.
  - `charge_amount`: Strictly $\mathbf{Rs.\;0.00}$ (customer already paid on original slip).
- **Physical Nozzle Advance**: Advances meter by `qty` (`Adjusted Litres`).
- The original slip's balance is marked as drawn.

#### Numerical Example:
- Prior Slip `#SL-101` (dated Aug 10, charged at **Rs. 200.00/L**) had:
  - `balance_1`: **40.00 Litres**
  - `balance_2`: **20.00 Litres**
  - **Merged Total Balance**: $40 + 20 = \mathbf{60.00\text{ Litres}}$.
  - **Prepaid Money Credit**: $60 \times 200 = \mathbf{Rs.\;12,000.00}$.
- **Case A: Today's Price INCREASED to Rs. 240.00 / Litre**:
  $$\text{Adjusted Litres} = \frac{\text{Rs. } 12,000}{\text{Rs. } 240} = \mathbf{50.00\text{ Litres}}$$
  - Driver pumps **50.00 Ltr**.
  - Customer Charge = **Rs. 0.00**.
  - Nozzle Meter Advance = **+50.00 Ltr**.
- **Case B: Today's Price DECREASED to Rs. 160.00 / Litre**:
  $$\text{Adjusted Litres} = \frac{\text{Rs. } 12,000}{\text{Rs. } 160} = \mathbf{75.00\text{ Litres}}$$
  - Driver pumps **75.00 Ltr**, Charge = **Rs. 0.00**, Nozzle Advance = **+75.00 Ltr**.
- **Case C: Today's Price UNCHANGED (Rs. 200.00 / Litre)**:
  $$\text{Adjusted Litres} = \frac{\text{Rs. } 12,000}{\text{Rs. } 200} = \mathbf{60.00\text{ Litres}}$$
  - When the effective rate is unchanged, the formula guarantees exact volume equality ($|P_{\text{current}} - P_{\text{original}}| < 0.001 \implies \text{adjusted\_litres} = \text{total\_balance}$).
- **Dual-Date Lookup & Modal Fluctuation Breakdown**:
  - The lookup endpoint `ajax-credit-slip-lookup.php?action=find_balance_slip` accepts two distinct dates:
    1. `orig_slip_date`: Historical date of the Permanent Slip (e.g. `2026-09-08`) used strictly to locate the record in `tbl_meter_reading_credit_sales`.
    2. `balance_slip_date`: Current transaction date when remaining balance is claimed (e.g. `2026-09-09`), used to query `tbl_prices` for the market rate on that day.
  - The **Claim Balance Modal** renders a prominent calculation card:
    - **Original Permanent Slip Details**: Date, Original Rate (Rs. 200), Quota Balance (26.00 Ltr), and Prepaid Value (Rs. 5,200.00).
    - **Claim Date Price**: Rate on collection date (Rs. 205.00 / Ltr).
    - **Dynamic Fluctuation Alert**:
      - *Price Increased*: Shows deducted litres (e.g. $-0.63$ Ltr) and net litres delivered (**25.37 Ltr**).
      - *Price Decreased*: Shows bonus litres (e.g. $+1.37$ Ltr) and net litres delivered (**27.37 Ltr**).
      - *Price Unchanged*: Shows exact remaining balance (**26.00 Ltr**).
    - **Customer Charge**: Strictly `Rs. 0.00` (pre-paid).
  - When applied, the row's `Quantity` and `Issue Quantity` are populated with the adjusted litres (e.g. `25.37`), the row rate is updated to the claim date price (e.g. `205.00`), and `Customer Charge` evaluates to `Rs. 0.00`.
- **Customer Report Ledger Reconciliation & Zero-Loan Guarantee**:
  - In `reports/customer-report.php` and `reports/generate-pdf-customer-report.php`, the ledger distinguishes between **Voucher Quota Settled** ($L_{\text{orig\_quota}}$, e.g. 26.00 Ltr) and **Physical Petrol Pumped** ($L_{\text{dispensed}}$, e.g. 20.80 Ltr).
  - When a Balanced Slip settles a voucher, the customer's pending quota balance drops to **0.00 Ltr** ($26.00 - 26.00 = 0.00$).
  - The volume variance ($\Delta L = L_{\text{orig\_quota}} - L_{\text{dispensed}}$) is shown on a dedicated **Price Fluctuation Impact** line (e.g. $-5.20$ Ltr price escalation absorption or $+6.50$ Ltr price drop gain).
  - Price fluctuation **never** touches loan metrics; loan fuel remains strictly **0.00 Ltr / Rs. 0.00** unless an unreturned Temporary Slip exists.

---

### 📕 Scenario 4: Temporary Slip (Loan Fuel)

#### Business Rule
When fuel is dispensed on loan without a formal voucher:
- Operator selects `Temporary Slip`.
- The inline `[ ] Received` checkbox is **completely removed** (settlement is governed strictly by Scenario 2).
- Dispensed litres are entered in `qty`.
- **Nozzle Meter Advance**: Physical fuel is deducted immediately:
  $$\Delta\text{nozzle} = \text{qty}$$
- **Customer Charge**: Set to $\mathbf{Rs.\;0.00}$ (billing deferred to Permanent Slip).
- The slip is saved with `is_returned = 0` and records the current price on that date.
- In Customer Report: Tracked under **Unsettled Loan Fuel** until a Permanent Slip settles it.

---

## 4. Summary Matrix of All Scenarios

| Scenario | Slip Type | Billed Charge Amount | Nozzle Meter Reading Advance | Temp. Receive Role | Balance Created / Drawn |
|---|---|---|---|---|---|
| **Scenario 1** | `Permanent Slip` | $\text{issue\_qty} \times \text{rate}$ | $\text{qty}$ | None (`0.00`) | Creates $\text{issue\_qty} - \text{qty}$ in `balance_1` |
| **Scenario 2** | `Permanent Slip` | $(\text{issue\_qty} \times \text{rate}) + (\text{temp\_qty} \times \text{temp\_rate})$ | $\text{qty}$ | Invoices prior loan chit via modal | Creates $\text{issue\_qty} - \text{qty}$ |
| **Scenario 3** | `Balanced Slip` | $\mathbf{Rs.\;0.00}$ | `Adjusted Litres` | Disabled (`0.00`) | Merges prior `balance_1 + balance_2`, adjusts for price |
| **Scenario 4** | `Temporary Slip` | $\mathbf{Rs.\;0.00}$ | $\text{qty}$ | Disabled (`0.00`) | Saved as open loan chit (`is_returned = 0`) |

---

## 5. UI Standardization (`Wasoli` $\rightarrow$ `Temp. Receive`)

Every appearance of the legacy term `Wasoli` or `Wasooli` across PPMS is standardized:
1. **Forms (`add-credit-sale.php` & `edit-credit-sale.php`)**:
   - Column header: `Temp. Receive`
   - Input field: `credit_wasoli[]` with tooltip *"Temporary slip quantity to settle"*
2. **Consolidated List & Modals (`credit-sales-list.php`)**:
   - Column header and modal detail cells: `Temp. Receive`
3. **Printable PDF (`generate-pdf-credit-sale.php` & `generate-pdf-customer-report.php`)**:
   - Table headers and summaries: `Temp. Receive (Settled Loan Fuel)`
4. **Customer Ledger (`customer-report.php`)**:
   - Clear distinction between billed permanent debits, settled temp receive fuel, and open loan chits.

---

## 6. Multi-Row Form Data Integrity & Linkage Preservation

1. **Strict Readonly State for Array Elements**:
   - Never use `.prop('disabled', true)` on dynamic table inputs whose names use array syntax (`credit_wasoli[]`, `credit_issue_quantity[]`). Disabled inputs are omitted by browsers during HTTP POST, which causes array element shifting across rows.
   - Inactive fields are styled as `readonly` with background `#e9ecef` and cursor `not-allowed`, ensuring all rows submit complete arrays with aligned indexes.
2. **Active Shift Re-linkage (Self-Healing)**:
   - When editing a shift where both a Temporary Slip and a Permanent Slip are saved together, the edit controller soft-deletes previous rows and generates new IDs.
   - The backend automatically re-resolves the newly inserted Temporary Slip ID by matching `slip_no` and customer account, updating `temp_slip_id` on the Permanent Slip, and marking the Temporary Slip `is_returned = 1`.
3. **UI Display Preservation**:
   - On form load, the edit controller populates `wasoli` from database records. If historical records have `temp_slip_id` or `temp_slip_no` present but `wasoli` is 0, the controller self-heals by fetching the quantity from the temporary slip.
   - The green settlement badge (`Settling #<slip_no> (<qty>L)`) and the `Temp. Receive` input are rendered and initialized in full sync.
4. **Shift Validation & Zero Data Loss Guarantee**:
   - Both `add-credit-sale.php` and `edit-credit-sale.php` mandate that an active Shift is chosen (`#shift_id`).
   - `validateCreditForm()` acts as a client-side gate before form dispatch: if `#shift_id` is unselected, submission is blocked with an alert and field focus, completely avoiding a page reload.
   - In the event of any server-side validation error, `$posted_rows` reconstructs all entered rows and values into the spreadsheet grid, guaranteeing that user input is never wiped out.
5. **Date-First & Row Slip-Date Pricing Precedence**:
   - Fuel rates on credit sale rows strictly depend on that row's individual **Slip Date (`.credit-slip-date`)** and the customer's tariff policy (`fuel_rate`: `Cash` vs `Credit`).
   - The transaction header date (`#sale_date`) only serves as the initial default date when appending a row; it does not override or constrain individual slip dates.
   - Selecting or changing a vehicle (`onCreditVehicleInput`), nozzle (`updateCreditItem`), or slip date (`onSlipDateChange`) delegates to `resolveCreditRowRate($row)`. This invokes `ajax-credit-slip-lookup.php?action=get_price_for_date` to query `tbl_prices` for the effective price on that row's slip date, applying the customer's tariff policy without overwriting with current day station rates.
   - Balanced Slips preserve the rate established by the claimed balance voucher and are protected from general price lookups.
   - Initial population of existing rows in edit mode uses `skipPriceFetch = true` to preserve saved database rates against asynchronous race conditions.
