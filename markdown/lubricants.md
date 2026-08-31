# Lubricants Module Documentation (`markdown/lubricants.md`)

## 1. Overview
The **Lubricants Module** manages product catalogs, stock purchases, sales transactions (Cash & Credit), product audit ledgers, and inventory reordering levels for the Petrol Pump Management System (PPMS).

---

## 2. Integer Quantities & Reordering Level Tracking

- **Whole Integer Quantities**: All product transactions (`tbl_lubricant_purchases.quantity`, `tbl_lubricant_sales.quantity`, `tbl_lubricant_products.reorder_level`) operate as **integers** (`INT(11)`) without decimal values.
- **Stock Inflow / Outflow & Deficit Formula**:
  $$\text{Current Stock} = \sum(\text{Purchases}) - \sum(\text{Sales})$$
  $$\text{Deficit} = \max(0, \text{reorder\_level} - \text{Current Stock})$$
- **Low Stock Evaluation**:
  - When $\text{Current Stock} \le \text{reorder\_level}$:
    - Highlighted in **bold red** across the **Stock Report** (`lubricants/stock-report.php`).
    - Triggers **Alert Notifications** on the main **Dashboard** (`dashboard.php`).

---

## 3. Related Documentation
- [`markdown/products.md`](products.md) - Product Catalog, Transactions & Schema Specification
- [`markdown/dashboard.md`](dashboard.md) - Dashboard Notification & Alert Specification
- [`markdown/theme.md`](theme.md) - Design System & UI Rules
