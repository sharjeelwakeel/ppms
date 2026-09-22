# Lubricant Products & Inventory Module Documentation (`markdown/products.md`)

## 1. Overview
The **Lubricant Products & Inventory** module manages engine oils, lubricants, greases, and auxiliary stock items (`tbl_lubricant_products`). It tracks item pricing, dynamic stock inflows/outflows in **whole integer quantities**, automated integer **Reordering Level Thresholds** with proactive Dashboard alerts, **Partial Payment Disbursements** (`tbl_lubricant_purchase_payments`) from bank accounts, and **Comprehensive Sales Revenue Analysis** across date ranges.

---

## 2. Database Schemas

### 1. Product Categories Table (`tbl_product_categories`)
```sql
CREATE TABLE IF NOT EXISTS `tbl_product_categories` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### 2. Product Subcategories Table (`tbl_product_subcategories`)
```sql
CREATE TABLE IF NOT EXISTS `tbl_product_subcategories` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `category_id` INT(11) NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_status` (`status`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### 3. Products Table (`tbl_lubricant_products`)
```sql
CREATE TABLE IF NOT EXISTS `tbl_lubricant_products` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `category_id` INT(11) DEFAULT NULL,
  `subcategory_id` INT(11) DEFAULT NULL,
  `name` VARCHAR(128) NOT NULL,
  `cash_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,     -- Active Cash Rate per unit
  `credit_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,   -- Active Credit Rate per unit
  `purchase_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00, -- Active Supplier Purchase Cost per unit
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,         -- Cached selling price (synced to cash_rate for backward compatibility)
  `reorder_level` INT(11) NOT NULL DEFAULT 0,          -- Integer minimum inventory threshold
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_subcategory_id` (`subcategory_id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### 4. Polymorphic Price History (`tbl_prices`)
Product price revisions and rate tiers are tracked in the shared polymorphic `tbl_prices` table (`table_name = 'tbl_lubricant_products' AND table_id = <product_id>`). Each product maintains a full chronological audit log of all price changes, while exactly one active record (`is_active = 1`) defines the current tariffs. Active rates are simultaneously cached on `tbl_lubricant_products` for zero-overhead, backward-compatible reads across sales and reports.

### 2. Purchases Table (`tbl_lubricant_purchases`)
```sql
CREATE TABLE IF NOT EXISTS `tbl_lubricant_purchases` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `product_id` INT(11) NOT NULL,
  `quantity` INT(11) NOT NULL DEFAULT 0,              -- Integer whole unit quantity
  `purchase_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `date` DATE NOT NULL,
  `payment_status` VARCHAR(32) NOT NULL DEFAULT 'unpaid', -- unpaid | in process | paid
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_payment_status` (`payment_status`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### 3. Purchase Payments Table (`tbl_lubricant_purchase_payments`)
```sql
CREATE TABLE IF NOT EXISTS `tbl_lubricant_purchase_payments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `purchase_id` INT(11) NOT NULL,
  `date` DATE NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `bank_id` INT(11) NOT NULL,                         -- Source bank account master (tbl_banks)
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lubricant_purchase_id` (`purchase_id`),
  KEY `idx_bank_id` (`bank_id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### 4. Sale Invoices Master Table (`tbl_lubricant_sale_invoices`)
```sql
CREATE TABLE IF NOT EXISTS `tbl_lubricant_sale_invoices` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `invoice_no` VARCHAR(64) NOT NULL,
  `date` DATE NOT NULL,
  `shift_id` INT(11) NOT NULL DEFAULT 0,              -- Operating shift (tbl_shifts.id)
  `payment_type` VARCHAR(32) NOT NULL DEFAULT 'Cash', -- Cash | Card | Credit
  `card_machine_id` INT(11) DEFAULT NULL,             -- Selected POS machine (tbl_card_machines)
  `bank_id` INT(11) DEFAULT NULL,                     -- Destination bank account (tbl_banks)
  `details` TEXT DEFAULT NULL,                        -- Optional remarks
  `total_items` INT(11) NOT NULL DEFAULT 0,
  `total_quantity` INT(11) NOT NULL DEFAULT 0,
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_invoice_no` (`invoice_no`),
  KEY `idx_shift_id` (`shift_id`),
  KEY `idx_date` (`date`),
  KEY `idx_card_machine_id` (`card_machine_id`),
  KEY `idx_bank_id` (`bank_id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### 5. Sales Line Items Table (`tbl_lubricant_sales`)
```sql
CREATE TABLE IF NOT EXISTS `tbl_lubricant_sales` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` INT(11) DEFAULT NULL,                 -- Foreign key linking to master tbl_lubricant_sale_invoices
  `invoice_no` VARCHAR(64) DEFAULT NULL,             -- Redundant invoice number for high-performance direct indexing
  `product_id` INT(11) NOT NULL,
  `quantity` INT(11) NOT NULL DEFAULT 0,              -- Integer whole unit quantity
  `rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_type` VARCHAR(32) NOT NULL DEFAULT 'Cash',
  `details` TEXT DEFAULT NULL,
  `date` DATE NOT NULL,
  `shift_id` INT(11) NOT NULL DEFAULT 0,              -- Operating shift (tbl_shifts.id)
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_invoice_id` (`invoice_id`),
  KEY `idx_invoice_no` (`invoice_no`),
  KEY `idx_shift_id` (`shift_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 3. Financial & Revenue Formulas

### 1. Product Sales Revenue (Period)
$$\text{Product Revenue} = \sum_{\text{Period}}(\text{tbl\_lubricant\_sales.amount where product\_id} = p.id)$$
$$\text{Overall Sales Revenue} = \sum_{\text{Period}}(\text{tbl\_lubricant\_sales.amount})$$
$$\text{Overall Cash Sales Revenue} = \sum_{\text{Period}}(\text{tbl\_lubricant\_sales.amount where payment\_type} = \text{'Cash'})$$
$$\text{Overall Credit Sales Revenue} = \sum_{\text{Period}}(\text{tbl\_lubricant\_sales.amount where payment\_type} = \text{'Credit'})$$

### 2. Purchase Cost & Partial Payments
$$\text{Total Cost} = \text{Quantity} \times \text{Purchase Price}$$
$$\text{Total Paid} = \sum(\text{tbl\_lubricant\_purchase\_payments.amount})$$
$$\text{Remaining Balance} = \text{Total Cost} - \text{Total Paid}$$

**Payment Status Automation**:
- **`unpaid`**: Total Paid $= 0.00$
- **`in process`**: $0.00 < \text{Total Paid} < \text{Total Cost}$
- **`paid`**: Total Paid $\ge$ Total Cost

### 3. Inventory Stock Balances & Valuation
$$\text{Current Stock (Units)} = \sum(\text{Purchased}) - \sum(\text{Sold})$$
$$\text{Stock Valuation (Rs.)} = \text{Current Stock} \times \text{Selling Price}$$

---

## 4. File Architecture

| File Path | Description |
|---|---|
| `categories/categories-list.php` | Product categories list showing active subcategory & product counts |
| `categories/add-category.php` | Form to create a new high-level product category |
| `categories/edit-category.php` | Form to edit category details and active/inactive status |
| `categories/subcategories-list.php` | Product subcategories list with parent category filter and product counts |
| `categories/add-subcategory.php` | Form to create a subcategory linked to a parent category |
| `categories/edit-subcategory.php` | Form to edit subcategory details and parent category linkage |
| `categories/ajax-get-subcategories.php` | JSON endpoint returning active subcategories for a selected category |
| `include/deletecategory.php` | Soft-delete endpoint for categories with dependency guards |
| `include/deletesubcategory.php` | Soft-delete endpoint for subcategories with product dependency guards |
| `include/deleteproduct.php` | Soft-delete endpoint for products cascading to `tbl_prices` |
| `lubricants/products-list.php` | Catalog of all lubricant products with category, subcategory, reorder level, Cash Rate, Credit Rate, Purchase Rate, and modals for Price History and Price Revision |
| `lubricants/add-product.php` | Form to create a new product with category, subcategory, cash/credit/purchase rates, and automatic baseline active price seeding |
| `lubricants/edit-product.php` | Form to update product details, revise price tiers, and view embedded full price revision timeline card |
| `lubricants/get-price-history.php` | AJAX JSON endpoint returning full price history for a lubricant product |
| `lubricants/delete-price.php` | AJAX endpoint for soft-deleting individual product price records |
| `lubricants/purchases-list.php` | Inflow purchase list with Total Amount, Paid Amount, Remaining Balance, and Status |
| `lubricants/add-purchase.php` | Form to record stock inflows with automatic purchase_price pre-population from active product purchase_rate and optional initial bank payment disbursement |
| `lubricants/edit-purchase.php` | Manage purchase details with purchase_rate synchronization, view financial KPIs, disburse partial bank payments, and view payment history |
| `include/deletelubricantpurchasepayment.php` | Soft-delete handler for partial payments with automatic status recalculation |
| `lubricants/sales-list.php` | Sales invoices listing with item count, total quantity, grand total, and receipt breakdown modal |
| `lubricants/add-sale.php` | Spreadsheet-style multi-row sale creation form with live stock validation, dual rate auto-population, and overall totals |
| `lubricants/edit-sale.php` | Form to edit multi-product sale invoices with dynamic row manipulation and atomic synchronization |
| `lubricants/ajax-get-invoice-details.php` | AJAX JSON endpoint returning full itemized products, quantities, rates, and amounts for receipt modals |
| `include/deletelubricantsale.php` | Soft-delete endpoint for sale invoices and line items |
| `lubricants/stock-report.php` | Comprehensive stock report highlighting units, overall revenue, individual product revenue, and stock valuation |
| `lubricants/get-product-ledger.php` | Modal AJAX product ledger displaying chronological integer stock inflows and outflows |
| `dashboard.php` | Main system dashboard with automated low-stock banners and quick-purchase actions |
| `markdown/products.md` | Module specification and complete documentation (this file) |

---

## 5. Payment Deletion Architecture & PRG Flow

- **Post-Redirect-Get (PRG)**: Both purchase and payment forms redirect via `header("Location: edit-purchase.php?id=$id&msg=...")` after POST processing.
- **Client AJAX Redirection**: `deletePayment()` executes a clean GET redirect upon receiving `'deleted'`, preventing browser form resubmission artifacts.

---

## 6. Product Categories & Subcategories Hierarchy & Rules

1. **Master Classification Hierarchy**:
   - **Category**: Top-level grouping (e.g. Engine Oil, Brake Fluid, Greases, Radiator Coolants).
   - **Subcategory**: Secondary grouping linked to a specific Category (e.g. 20W-50, 10W-40, Synthetic, Mineral, DOT 3, DOT 4, Lithium Complex).
   - **Product (`tbl_lubricant_products`)**: Assigned to a `category_id` with an optional `subcategory_id`.

2. **Cascading Dropdowns**:
   - On `add-product.php` and `edit-product.php`, selecting a Category triggers an AJAX call to `categories/ajax-get-subcategories.php?category_id=X`.
   - The subcategory dropdown dynamically populates with active subcategories belonging strictly to that category.
   - Subcategory is optional on products; selecting only a Category is valid.

3. **Dependency Integrity Guards**:
   - **Category Deletion**: A Category cannot be deleted if active subcategories or active products are linked to it.
   - **Subcategory Deletion**: A Subcategory cannot be deleted if active products are linked to it.
   - Both use soft-delete (`deleted_at = NOW()`).

---

## 7. Product Dual Pricing (Cash & Credit Rates) & Price History Architecture

1. **Polymorphic Synergy**:
   - Products utilize the shared `tbl_prices` table with `table_name = 'tbl_lubricant_products'` and `table_id = <product_id>`.
   - Stores `cash_rate`, `credit_rate`, `purchase_rate`, `effective_date`, `is_active`, `notes`, `created_by`, `created_at`, `deleted_at`.
   - Exactly **one** record per product has `is_active = 1` representing current active tariffs.

2. **Cache Synchronization**:
   - `tbl_lubricant_products` caches `cash_rate`, `credit_rate`, `purchase_rate`, and `price = cash_rate`.
   - Guarantees 100% zero-breakage backward compatibility with legacy queries querying `price`.

3. **Atomic Price Revision Workflow**:
   - Revising a price via the modal on `products-list.php` or from `edit-product.php` atomically deactivates previous active prices (`is_active = 0`), inserts the new active price with `is_active = 1`, and synchronizes the cached rate columns in `tbl_lubricant_products`.

4. **Sales Rate Auto-Selection**:
   - In `add-sale.php` and `edit-sale.php`, product `<option>` elements include `data-cash-rate` and `data-credit-rate`.
   - Toggling the **Payment Type** (`Cash` vs. `Credit`) dynamically sets the unit sale price input to the active Cash Rate or active Credit Rate.

5. **Soft Deletion & Active Promotion**:
   - Soft deleting an active price (`soft_delete_price`) automatically promotes the most recent remaining non-deleted historical price to `is_active = 1` and synchronizes the parent cached rates.
   - Soft deleting a product via `include/deleteproduct.php` cascades soft-deletion to all associated price records in `tbl_prices`.

---

## 8. Multi-Product Sales Invoices & Real-Time Itemized UI Architecture

1. **Master-Detail Relationship**:
   - Master: `tbl_lubricant_sale_invoices` stores high-level transaction data (`invoice_no`, `date`, `payment_type`, `details`, `total_items`, `total_quantity`, `total_amount`).
   - Details: `tbl_lubricant_sales` stores each itemized product line with `invoice_id` and `invoice_no`.
   - Preserves 100% backward compatibility with `stock-report.php`, `get-product-ledger.php`, and `dashboard.php` which aggregate quantities directly from `tbl_lubricant_sales`.

2. **Spreadsheet Multi-Row UI (`add-sale.php` & `edit-sale.php`)**:
   - Cashiers can add multiple product lines in a single sale transaction.
   - Each row features dynamic product selection, live available stock counters, integer unit quantity, unit rate, and calculated line total.
   - **Automatic Row Expansion**: Selecting a product in the last row automatically appends a new blank row (`onProductSelect() -> if ($row.is(':last-child')) addNewProductRow()`), enabling rapid barcode/keyboard spreadsheet entry without needing to click "+ Add Product Line".
   - **Smart Trailing Row Pruning**: Trailing unselected blank rows are automatically pruned before form submission in `validateBeforeSubmit()` and ignored server-side, preventing HTML5 validation blocking or empty records in the database.
   - **Compact Cashier Ergonomics**: Uses slim 31px form inputs (`.form-control-compact`), condensed table cell padding (`.table-compact` 4px 6px), compact action buttons, and a streamlined summary bar to maximize vertical screen space, allowing 10+ line items to be visible simultaneously without scrolling.
   - **Streamlined Cash & Card Payment Modes**: Product sales are processed strictly on **Cash** and **Card** basis (Credit removed for simplified operational simplicity). Customer and vehicle fields are omitted; an optional **Remarks** input is provided for cashiers to enter any notes if needed.
   - **Dynamic Card Masking**: When **Card** payment is selected, a compact Card Details section unmasks with required dropdowns for **Card Machine / POS Terminal** (`tbl_card_machines`) and **Destination Bank Account** (`tbl_banks`). When **Cash** is selected, the section remains hidden and non-required, setting `card_machine_id` and `bank_id` to `NULL`.
   - Real-time live footer displays **Total Distinct Products**, **Overall Total Quantity (Units)**, and **Grand Total Amount (Rs.)**.

3. **Receipt & Invoice Breakdown Modal (`sales-list.php`)**:
   - `sales-list.php` lists sales grouped by invoice with clear payment badges (Cash vs. Card). Clicking the **Invoice #** directly opens the edit view (`edit-sale.php`), eliminating redundant action buttons.
   - Interactive **Receipt** button opens the `#viewInvoiceModal` which loads the itemized line items via AJAX (`ajax-get-invoice-details.php`), displays the POS machine and bank account for Card payments, and provides print-ready formatting (`window.print()`).
   - Atomic invoice deletion cascades soft-deletion across both the master invoice and all associated line items.
