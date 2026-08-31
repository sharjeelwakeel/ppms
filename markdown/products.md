# Lubricant Products & Inventory Module Documentation (`markdown/products.md`)

## 1. Overview
The **Lubricant Products & Inventory** module manages engine oils, lubricants, greases, and auxiliary stock items (`tbl_lubricant_products`). It tracks item pricing, dynamic stock inflows/outflows in **whole integer quantities**, and automated integer **Reordering Level Thresholds** with proactive low stock alert notifications on the Dashboard.

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
  `payment_status` VARCHAR(32) NOT NULL DEFAULT 'Paid',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### 3. Sales Table (`tbl_lubricant_sales`)
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
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 3. Integer Quantity & Reordering Logic

### 1. Integer Unit Quantities
- **Rule**: All product transactions (Stock Inflow / Purchases, Stock Outflow / Sales, Reorder Levels, and Current Stock) operate on **whole integer units** (e.g., `1`, `10`, `50`, `100`), without decimal fractions.
- **Form Inputs**:
  - `add-product.php` & `edit-product.php`: `<input type="number" step="1" min="0" name="reorder_level" placeholder="e.g. 10" required>`
  - `add-purchase.php` & `edit-purchase.php`: `<input type="number" step="1" min="1" name="quantity" placeholder="e.g. 10" required>`
  - `add-sale.php` & `edit-sale.php`: `<input type="number" step="1" min="1" name="quantity" placeholder="e.g. 1" required>`

### 2. Real-Time Stock & Low Stock Evaluation Formula
For each product:
$$\text{Total Inflow (Units)} = \sum(\text{tbl\_lubricant\_purchases.quantity})$$
$$\text{Total Outflow (Units)} = \sum(\text{tbl\_lubricant\_sales.quantity})$$
$$\text{Current Stock} = \text{Total Inflow} - \text{Total Outflow}$$

**Low Stock Alert Condition**:
$$\text{Is Low Stock} = (\text{Current Stock} \le \text{reorder\_level} \text{ and } \text{reorder\_level} > 0) \lor (\text{Current Stock} \le 0)$$

---

## 4. File Architecture

| File Path | Description |
|---|---|
| `lubricants/products-list.php` | Catalog of all lubricant products with integer reorder levels and selling prices |
| `lubricants/add-product.php` | Form to create a new product with selling price and integer reorder level |
| `lubricants/edit-product.php` | Form to update product details, pricing, and integer reorder level |
| `lubricants/purchases-list.php` | Stock intake / purchases log formatted with integer quantities |
| `lubricants/add-purchase.php` | Form to record stock inflows with integer unit quantity |
| `lubricants/edit-purchase.php` | Form to edit recorded stock purchases |
| `lubricants/sales-list.php` | Stock sales log formatted with integer quantities |
| `lubricants/add-sale.php` | Form to record stock outflows with integer unit quantity and stock validation |
| `lubricants/edit-sale.php` | Form to edit recorded stock sales |
| `lubricants/stock-report.php` | Comprehensive stock report highlighting integer quantities, stock levels, and low-stock alerts |
| `lubricants/get-product-ledger.php` | Modal AJAX product ledger displaying chronological integer stock inflows and outflows |
| `dashboard.php` | Main system dashboard with automated low-stock banners, warning counts, and quick-purchase actions |
| `markdown/products.md` | Module specification and complete documentation (this file) |

---

## 5. UI Theme & Icon Standards

- **Theme Compliance (`markdown/theme.md`)**:
  - Primary color: `#04204e` (`var(--primary-color)`).
  - Primary buttons: `var(--primary-gradient)` (`.btn-primary`).
  - Table Header (`thead th`): `#04204e`.
- **Icons (FontAwesome 5)**:
  - Products Module Header: `<i class="fas fa-boxes mr-2 text-primary"></i>`
  - Add Product: `<i class="fas fa-plus mr-1"></i> Add New Product`
  - Save Product: `<i class="fas fa-save mr-1"></i> Save Product`
  - Cancel: `<i class="fas fa-times mr-1"></i> Cancel`
  - Low Stock Warning: `<i class="fas fa-exclamation-triangle text-danger"></i>`
