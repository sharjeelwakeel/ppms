# Customer Overall Monthly Credit Bill Module Specification

## 1. Overview & Purpose
The **Customer Overall Monthly Credit Bill** module (`reports/customer-monthly-bill.php` and `reports/generate-pdf-customer-monthly-bill.php`) provides an exact-match electronic statement and printable credit bill replicating the official paper vouchers issued by petroleum dealers (specifically styled after Khurram Petroleum Service & CNG / Pakistan State Oil Ltd.).

The report aggregates credit sales across both **Fuel** (`tbl_meter_reading_credit_sales`) and **Lubricants/Products** (`tbl_lubricant_sale_invoices` + `tbl_lubricant_sales`), unified under a single customer billing statement.

---

## 2. Key Business Rules & Architecture
1. **Strict Outstanding-Only Architecture (Pure Demand Bill)**:
   - This report serves as an official demand bill sent to clients requesting payment.
   - It **strictly and exclusively** includes **unpaid and partially paid vouchers** where money is owed:
     $$(\text{payment\_status} \neq \text{'Paid'} \quad \text{OR} \quad (\text{charge\_amount} - \text{paid\_amount}) > 0.005)$$
   - Slips that have already been settled or fully paid (`paid_amount >= charge_amount`) are **permanently excluded** from the bill.
   - There are no toggles or options to show paid slips in this report. Historical audit and receipt records belong in Accounts.
   - For partial payments, the billed line amount reflects strictly the net balance due ($charge - paid$).
2. **Customer Dues Badge & Summary**:
   - The customer filter dropdown displays each customer's total unpaid amount and unpaid voucher count.
   - Active accounts with zero outstanding balance are labeled as `(All Paid - Rs. 0.00 Due)`.
3. **Sequential Bill Numbering**:
   - Every issued bill is assigned a unique sequential bill number (auto-incrementing from baseline `64343`).
   - The bill number persists for the same customer, billing period, and vehicle in `tbl_customer_monthly_bills`.
   - Users can optionally override or specify a custom bill number.
4. **Exact Amount-to-Words English Conversion**:
   - Converts monetary totals into standard English words:
     `133886.61` $\rightarrow$ `( RUPEES ONE HUNDRED THIRTY-THREE THOUSAND EIGHT HUNDRED EIGHTY-SIX AND 61 / 100 Only )`.

---

## 3. Database Schema

### Table: `tbl_customer_monthly_bills`
Stores generated bill numbers, customer mapping, date ranges, and snapshot totals:
```sql
CREATE TABLE IF NOT EXISTS `tbl_customer_monthly_bills` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `bill_no` VARCHAR(32) NOT NULL,
    `customer_id` INT(11) NOT NULL,
    `from_date` DATE NOT NULL,
    `to_date` DATE NOT NULL,
    `vehicle_number` VARCHAR(64) DEFAULT NULL,
    `attention_to` VARCHAR(255) DEFAULT NULL,
    `total_coupons` INT(11) NOT NULL DEFAULT 0,
    `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_cust_dates` (`customer_id`, `from_date`, `to_date`),
    KEY `idx_bill_no` (`bill_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 4. Voucher Layout & UI Structure

### Header & Station Letterhead
- Fetched dynamically from `tbl_settings` via `get_station_settings($connection)`.
- Includes Station Name (bold uppercase serif), Tagline, Address, City, Phone, and the billing date range.

### Customer & Vehicle Meta Box (2-Column Grid)
| Column 1 (Left) | Column 2 (Right) |
| :--- | :--- |
| **A/c No**: Customer ID | **Vehicle**: Reg Number (e.g. `BRM 2779`) |
| **To**: Attention line (e.g. `The Vice Chancellor,`) | **Bill No.**: e.g. `64343` |
| **Name**: Customer Account Name | *(blank)* |

### Main Transaction Grid
- Columns: `S.No.` | `Date` (DD/MM/YYYY) | `Coupon` | `Description` | `Quantity` | `Rate` | `Amount`.
- **Description Mapping**:
  - For Fuel: Product name from `tbl_items.name` (e.g. `Action+ Diesel`).
  - For Lubricants / Products: Prioritizes Category Name from `tbl_product_categories.name` (e.g. `Deo 6000 4L`), falling back to `tbl_lubricant_products.name` if unassigned. This ensures bills faithfully display commercial product categories as printed on official vouchers.

### Category Summary Box (Bottom Left)
- Grouped rows:
  1. `Diesel`: Total liters pumped and net amount.
  2. `Petrol`: Total liters pumped and net amount (if applicable).
  3. `Others`: Lubricants and shop products total amount.
  4. `Total`: Grand total bill amount.

---

## 5. Security & RBAC
- Controller gates access using session verification and `check_access('reports', 'show')`.
- Soft-deleted records (`deleted_at IS NOT NULL`) are strictly excluded.
- Numeric parameters are cast explicitly using `intval()` and `floatval()`.
- SQL parameters are sanitized using `mysqli_real_escape_string()`.

---

## 6. Automated Test Suite
Located in `tests/customer-monthly-bill/`:
- `test_01_outstanding_filtering.php`: Validates exclusion of paid slips and inclusion of unpaid ones.
- `test_02_data_aggregation_and_math.php`: Verifies volumes, category summaries, and grand totals.
- `test_03_number_to_words_converter.php`: Validates English amount wording against the paper voucher format.
- `test_04_bill_number_persistence.php`: Validates sequential numbering starting at 64343.
- `test_05_soft_delete_exclusion.php`: Validates strict exclusion of soft-deleted records.
