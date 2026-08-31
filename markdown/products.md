# Lubricant Products & Inventory Module Documentation (`markdown/products.md`)

## 1. Overview
The **Lubricant Products & Inventory** module manages engine oils, lubricants, greases, and auxiliary stock items (`tbl_lubricant_products`). It tracks item pricing, dynamic stock inflows/outflows in **whole integer quantities**, automated integer **Reordering Level Thresholds** with proactive Dashboard alerts, **Partial Payment Disbursements** (`tbl_lubricant_purchase_payments`) from bank accounts, and **Comprehensive Sales Revenue Analysis** across date ranges.

---

## 2. Database Schemas

### 1. Products Table (`tbl_lubricant_products`)
```sql
CREATE TABLE IF NOT EXISTS `tbl_lubricant_products` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,       -- Selling Price per unit
  `reorder_level` INT(11) NOT NULL DEFAULT 0,         -- Integer minimum inventory threshold
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
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
| `lubricants/products-list.php` | Catalog of all lubricant products with integer reorder levels and selling prices |
| `lubricants/add-product.php` | Form to create a new product with selling price and integer reorder level |
| `lubricants/edit-product.php` | Form to update product details, pricing, and integer reorder level |
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
