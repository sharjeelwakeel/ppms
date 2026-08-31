# Lubricants Module Documentation (`markdown/lubricants.md`)

## 1. Overview
The **Lubricants Module** manages product catalogs, stock purchases with partial bank payments, sales transactions (Cash & Credit), product audit ledgers, inventory reordering levels, and **comprehensive stock & sales revenue reporting** for the Petrol Pump Management System (PPMS).

---

## 2. Stock Report & Revenue Tracking

- **Overall Sales Revenue**:
  - Displays total sales revenue $\sum(\text{tbl\_lubricant\_sales.amount})$ generated across the selected date range.
  - Subdivided into **Cash Sales Revenue** and **Credit Sales Revenue**.
- **Per-Product Sales Revenue**:
  - Each item row in the Stock Report displays the exact revenue generated from its sales during the period (`Sales Revenue (Rs.)`).
- **Inventory Balances & Valuation**:
  $$\text{Current Stock} = \sum(\text{Purchases}) - \sum(\text{Sales})$$
  $$\text{Stock Valuation} = \text{Current Stock} \times \text{Selling Price}$$
- **Reordering Thresholds**:
  - Highlights low stock in red and triggers alert banners on the main dashboard when $\text{Current Stock} \le \text{reorder\_level}$.

---

## 3. Related Documentation
- [`markdown/products.md`](products.md) - Product Catalog, Partial Payments, Revenue Formulas & Schema Specification
- [`markdown/purchase.md`](purchase.md) - Fuel Purchase & Bank Disbursement Specification
- [`markdown/dashboard.md`](dashboard.md) - Dashboard Notification & Alert Specification
- [`markdown/theme.md`](theme.md) - Design System & UI Rules
