# Dashboard & Notification System Documentation (`markdown/dashboard.md`)

## 1. Overview
The **PPMS Dashboard** (`dashboard.php`) serves as the central control and monitoring interface. In addition to high-level metric summaries, the dashboard provides a **Real-Time Automated Low Stock Notification & Alerting System** that warns management when product inventory drops below designated reordering levels.

---

## 2. Low Stock Notification & Alert Architecture

### 1. Alert Trigger Logic
- Real-time stock evaluation compares cumulative purchases minus cumulative sales against each product's configured `reorder_level`:
  $$\text{Current Stock} = \sum(\text{Purchases}) - \sum(\text{Sales})$$
  $$\text{Trigger Alert if: } (\text{Current Stock} \le \text{reorder\_level} \text{ and } \text{reorder\_level} > 0) \lor (\text{Current Stock} \le 0)$$

### 2. Dashboard Components
1. **Low Stock Banner (`alert-danger`)**:
   - Prominently displayed at the top of the dashboard when $1$ or more products are below their reorder level.
   - Shows total count of depleted products and a direct link to the Stock Report.
2. **Metrics Widgets**:
   - **Total Registered Products** (Navy card)
   - **Low Stock Alerts / Inventory Status** (Red card when alerts exist, Green card when all healthy)
   - **Total Inventory Valuation** (Blue card)
3. **Interactive Low Stock Data Table**:
   - Rendered directly on the dashboard when low-stock items exist.
   - **Columns**: `#`, `Product Name`, `Current Stock` (Bold red badge), `Reorder Level`, `Deficit`, `Status` (`Out of Stock` or `Reorder Required`), `Action` (`[+ Add Purchase]`).
   - Quick purchase action button routes directly to `lubricants/add-purchase.php?product_id=X` for swift restocking.
4. **Healthy Inventory Confirmation**:
   - When all products are adequately stocked ($\text{Current Stock} > \text{reorder\_level}$), displays a green confirmation card indicating healthy inventory status.

---

## 3. UI Theme & Icon Standards

- **Alert Styling**: `border-left: 6px solid #dc3545`, soft red background `#fff5f5`.
- **Badges**:
  - Low Stock Badge: `<span class="badge badge-danger">X.XX</span>`
  - Reorder Required Status: `<span class="badge badge-danger"><i class="fas fa-exclamation-triangle mr-1"></i> Reorder Required</span>`
  - Out of Stock Status: `<span class="badge badge-dark"><i class="fas fa-times-circle mr-1 text-danger"></i> Out of Stock</span>`
- **Buttons**:
  - Add Purchase: `<a href="lubricants/add-purchase.php?product_id=X" class="btn btn-primary btn-sm"><i class="fas fa-plus mr-1"></i> Add Purchase</a>`
