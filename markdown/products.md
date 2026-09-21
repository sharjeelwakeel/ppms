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
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,       -- Selling Price per unit
  `reorder_level` INT(11) NOT NULL DEFAULT 0,         -- Integer minimum inventory threshold
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_subcategory_id` (`subcategory_id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

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

### 4. Sales Table (`tbl_lubricant_sales`)
```sql
CREATE TABLE IF NOT EXISTS `tbl_lubricant_sales` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `product_id` INT(11) NOT NULL,
  `quantity` INT(11) NOT NULL DEFAULT 0,              -- Integer whole unit quantity
  `rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_type` VARCHAR(32) NOT NULL DEFAULT 'Cash',
  `details` TEXT DEFAULT NULL,
  `date` DATE NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
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
| `lubricants/products-list.php` | Catalog of all lubricant products with category, subcategory, reorder level, and selling price |
| `lubricants/add-product.php` | Form to create a new product with category and dynamic cascading subcategory |
| `lubricants/edit-product.php` | Form to update product details, category, subcategory, and reorder level |
| `lubricants/purchases-list.php` | Inflow purchase list with Total Amount, Paid Amount, Remaining Balance, and Status |
| `lubricants/add-purchase.php` | Form to record stock inflows with optional initial bank payment disbursement |
| `lubricants/edit-purchase.php` | Manage purchase details, view financial KPIs, disburse partial bank payments, and view payment history |
| `include/deletelubricantpurchasepayment.php` | Soft-delete handler for partial payments with automatic status recalculation |
| `lubricants/sales-list.php` | Stock sales log formatted with integer quantities |
| `lubricants/add-sale.php` | Form to record stock outflows with integer unit quantity and stock validation |
| `lubricants/edit-sale.php` | Form to edit recorded stock sales |
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
