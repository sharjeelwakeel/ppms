# PPMS Dashboard & Analytics Specification (`markdown/dashboard.md`)

## 1. Overview & Purpose
The **PPMS Executive Dashboard** (`dashboard.php`) is the central intelligence and monitoring center of the Petrol Pump Management System. It provides real-time visibility into station revenue, dispenser meter throughput, and critical inventory alerts:
1. **7-Day Net Sales Interactive Bar Chart**: Daily net fuel sales revenue (PKR) tracking over the last 7 consecutive calendar days spanning full width (`col-12`).
2. **Focused Stock Restock Alerts**: Clean, distraction-free inventory alert section showing strictly the products requiring urgent restocking, displaying purely essential information without distracting buttons or clutter.

---

## 2. Key Business Rules & Calculation Formulas

### 1. 7-Day Net Sales Trend (Bar Chart Data)
- **Time Window**: 7 consecutive calendar days from $T-6$ to $T$ (Today).
- **Chronological Completeness**: All 7 days are strictly represented on the X-axis in chronological order. Days without closed readings are filled with `0.00` to prevent broken axes or misleading trendlines.
- **Metrics Tracked per Day**:
  1. **Daily Net Revenue (PKR)**: Total monetary value of fuel dispensed.
  2. **Daily Fuel Volume (Litres)**: Total net litres pumped across all nozzles.
  3. **Closed Shifts Count**: Number of shifts finalized on that day.
- **Layout**: Spans full width (`col-12`) for an executive, distraction-free visualization.

### 2. Focused Stock Restock Alerts (Alert & Restock Table Only)
- **Policy**: Avoid displaying redundant global inventory statistics on the operational dashboard. Focus management attention purely on **what needs to be reordered**.
- **Restock Condition**:
  $$\text{Restock Required if: } (\text{Current Stock} \le \text{reorder\_level} \text{ and } \text{reorder\_level} > 0) \lor (\text{Current Stock} \le 0)$$
  where $\text{Current Stock} = \sum(\text{Purchases}) - \sum(\text{Sales})$.
- **Display Rules (Pure Information Mode)**:
  - If 1 or more products meet the restock condition:
    - Display the high-priority **Stock Needs Restocking Alert Banner** (`alert-danger`).
    - Display the **Restock Action Table** listing *only* those deficit products with:
      - Product Name
      - Current Stock (styled badge)
      - Reorder Level
      - Deficit Units
      - Status (`Out of Stock` or `Reorder Required`)
  - If 0 products meet the restock condition:
    - Display a clean confirmation alert: `"All product inventory levels are healthy (No products currently require restocking)"`.

---

## 3. Database Schema & Query Standards

### Active Record Invariant
All calculations strictly enforce the soft-delete invariant:
```sql
(deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
```

### 7-Day Aggregation Queries (`include/dashboard_helper.php`)
```sql
-- 1. Daily Revenue & Shifts
SELECT 
    date,
    COUNT(id) AS shifts_count,
    SUM(grand_total) AS daily_revenue
FROM tbl_meter_readings
WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
  AND date BETWEEN :start_date AND :end_date
GROUP BY date;

-- 2. Daily Net Litres
SELECT 
    mr.date,
    SUM(mrd.net_sale) AS daily_litres
FROM tbl_meter_reading_details mrd
JOIN tbl_meter_readings mr ON mrd.meter_reading_id = mr.id
WHERE (mr.deleted_at IS NULL OR mr.deleted_at = '0000-00-00 00:00:00')
  AND mr.date BETWEEN :start_date AND :end_date
GROUP BY mr.date;
```

---

## 4. UI Design & Styling Tokens

- **Design System**: Strictly conforms to [`theme.md`](../theme.md).
- **Primary Navy Palette**:
  - Primary Base: `#04204e` (`var(--primary-color)`)
  - Primary Hover: `#07347a` (`var(--primary-hover)`)
  - Gradient: `linear-gradient(135deg, #04204e 0%, #07347a 100%)`
- **Chart.js Styling**:
  - Chart Type: `bar`
  - Bar Color: Deep navy gradient / `rgba(4, 32, 78, 0.85)` with hover `#07347a`
  - Rounded Bar Corners: `borderRadius: 6`
  - Tooltips: Formats revenue as `PKR X,XXX.XX` and volume as `X,XXX.XX Ltr`
  - Y-Axis: Formatted in Pakistani Rupee currency units (`PKR`)
- **Typography**: Google Font `'Roboto', sans-serif`.
- **Icons**: Standardized FontAwesome 5 icons (`fas fa-tachometer-alt`, `fas fa-gas-pump`, `fas fa-chart-bar`, `fas fa-exclamation-triangle`, `fas fa-check-circle`, `fas fa-shopping-cart`).

- **Offline / On-Premise Asset Policy**:
  - The charting engine is strictly hosted locally at [`include/js/chart.min.js`](../include/js/chart.min.js) (Chart.js v3.9.1 production bundle).
  - External CDN calls are eliminated for charting to ensure 100% offline uptime and intranet station compatibility.

---

## 5. Automated Test Suite
Test file: `tests/dashboard/test_dashboard_revenue_and_7day_chart.php`
- `TC-DSH-01`: Calculates Today's meter reading revenue and net litres accurately.
- `TC-DSH-02`: Validates 7-day chronological window completeness (7 continuous days).
- `TC-DSH-03`: Excludes soft-deleted meter readings and detail records from stats.
- `TC-DSH-04`: Validates restock-only product filter (only deficit products returned).
- `TC-DSH-05`: Validates local `include/js/chart.min.js` file presence and dashboard reference.
