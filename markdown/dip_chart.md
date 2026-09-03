# Tank Dip Chart Module Complete Documentation (`markdown/dip_chart.md`)

## 1. Overview
The **Tank Dip Chart** module manages physical fuel inventory reconciliation for storage tanks (`tbl_tanks`) in the Petrol Pump Management System (PPMS). It reconciles actual physical tank volume measured in millimeters (`dip_mm`) against calculated book balance derived from nozzle sales (`tbl_meter_readings` & `tbl_meter_reading_details`) and tank stock additions (`addition`).

The module seamlessly integrates with existing master data tables, supports high-performance indexed lookups (**5,000+ rows** in `tbl_dip_lookup`), manages multi-nozzle meter readings via a child table (`tbl_tank_dip_meter_logs`), enforces popup modal warnings when meter readings or dip values are missing, and fully complies with PPMS theme and icon standards.

---

## 2. Database Schema

### Parent Header Table (`tbl_tank_dip_logs`)

```sql
CREATE TABLE IF NOT EXISTS `tbl_tank_dip_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `tank_id` INT(11) NOT NULL,
  `date` DATE NOT NULL,
  `shift_id` INT(11) NOT NULL,
  `dip_mm` DECIMAL(10,2) NOT NULL,
  `balance` DECIMAL(12,2) NOT NULL,               -- Physical volume in Liters from tbl_dip_lookup
  `addition` DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- Tank refill / purchase addition in Liters
  `usage_litre` DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- Formula: Sum of (Current Reading - Previous Reading)
  `book_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,-- Formula: Prev Book Bal - Usage + Addition (or balance if first record)
  `per_dip_gain_loss` DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- Formula: balance - book_balance
  `overall_gain_loss` DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- Formula: Current Per Dip Gain/Loss - Prev Per Dip Gain/Loss
  `accumulative_pmg` DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- Cumulative product sales volume
  `remarks` VARCHAR(512) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tank_id` (`tank_id`),
  KEY `idx_date_shift` (`date`, `shift_id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### Child Meter Detail Table (`tbl_tank_dip_meter_logs`)

```sql
CREATE TABLE IF NOT EXISTS `tbl_tank_dip_meter_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `dip_log_id` INT(11) NOT NULL,
  `nozzle_id` INT(11) NOT NULL,
  `reading` DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- Current meter reading volume for this nozzle
  PRIMARY KEY (`id`),
  KEY `idx_dip_log_id` (`dip_log_id`),
  KEY `idx_nozzle_id` (`nozzle_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 3. Core Features, Mathematical Formulas & Business Rules

### 1. Tank List Integration (Action Icon Button)
- In `tanks/tanks-list.php`, an **Actions** column presents a chart icon button for each tank:
  ```html
  <a href="dip-chart.php?tank_id=2" class="btn btn-sm btn-info text-white" title="View Dip Chart">
    <i class="fas fa-chart-line"></i> Dip Chart
  </a>
  ```

### 2. Date & Shift Inputs
- **Date**: Defaults to current date (`YYYY-MM-DD`), editable manually. Required.
- **Shift**: Dropdown populated from active shifts (`tbl_shifts WHERE status = 'Active'`). Required.

### 3. Multi-Nozzle Meter Readings & Usage Calculation Rule
- A tank can have 1, 2, 3, or more meters/nozzles attached (`tbl_nozzles WHERE tank_id = ? AND status = 'Active'`).
- On `add-dip-log.php` and `edit-dip-log.php`, each attached meter/nozzle card displays:
  - **Previous Reading ($P_i$)**: Fetched from previous day/log meter reading (or `last_reading` from `tbl_meter_reading_details` / nozzle `start_reading`).
  - **Current Reading ($C_i$)**: Input field (`nozzle_reading[nozzle_id]`) populated with closing meter reading from `tbl_meter_reading_details` or entered manually.
  - **Nozzle Net Usage**: $\text{Nozzle Usage}_i = \text{Current Reading}(C_i) - \text{Previous Reading}(P_i)$.
- **Total Tank Usage Formula**:
  $$\text{Usage (Ltrs)} = \sum_{i=1}^{k} \left( \text{Current Reading}(C_i) - \text{Previous Reading}(P_i) \right)$$
  *Example*: Tank with 2 nozzles:
  - Nozzle 1: Current (12,500) - Prev (10,000) = 2,500 Ltrs
  - Nozzle 2: Current (6,200) - Prev (5,000) = 1,200 Ltrs
  - **Total Usage** = $2,500 + 1,200 = 3,700$ Ltrs.

### 4. Book Balance Rules (Initial vs Sequential Calculation)
- **First Entry Ever for Tank** (No previous dip log exists):
  Set **Book Balance** equal to physical **`balance`** (derived from physical dip in mm via `tbl_dip_lookup`):
  $$\text{Book Balance} = \text{balance}$$
- **Sequential Entries** (Previous dip log exists):
  $$\text{Book Balance} = \text{Previous Book Balance} - \text{Usage} + \text{Addition}$$

### 5. Fast Dip Lookup (`balance`) & Missing Dip Modal
- Entering **Dip (mm)** triggers an asynchronous AJAX call to `dip-lookup/lookup-by-mm.php`.
- Sub-2ms indexed lookup (`SELECT dip_litre FROM tbl_dip_lookup WHERE dip_mm = ? AND deleted_at IS NULL LIMIT 1`).
- Sets physical volume in **`balance`** field.
- **Missing Dip Warning Modal**: Shows alert if `dip_mm` is not mapped in `tbl_dip_lookup`.

### 6. Per Dip Gain / Loss Formula
$$\text{Per Dip Gain/Loss} = \text{balance} - \text{Book Balance}$$

### 7. Overall Gain / Loss Formula
$$\text{Overall Gain/Loss} = \text{Current Per Dip Gain/Loss} - \text{Previous Per Dip Gain/Loss}$$

### 8. Accumulative PMG / Product Formula
$$\text{Accumulative PMG} = \text{Previous Accumulative PMG} + \text{Usage}$$

---

## 4. File Architecture

| File Path | Description |
|---|---|
| `tanks/tanks-list.php` | Tank list table featuring the Action column chart icon button (`<i class="fas fa-chart-line"></i>`) |
| `tanks/dip-chart.php` | Dip Chart dashboard for a specific tank with summary metric cards & DataTables log history |
| `tanks/add-dip-log.php` | Create Dip Chart log with per-nozzle meter reading inputs, net usage subtraction ($C_i - P_i$), and auto-calculations |
| `tanks/edit-dip-log.php` | Edit Dip Chart log entry with per-nozzle meter reading pre-filling and recalculation |
| `tanks/get-tank-meter-readings.php` | AJAX endpoint returning day-to-day `prev_reading`, `current_reading`, and `net_sale` from `tbl_daily_nozzle_readings` |
| `tanks/get-prev-dip-log.php` | AJAX endpoint returning previous dip log values for sequential calculation |
| `include/nozzle_daily_sync.php` | Centralized helper synchronizing daily nozzle meters across Add, Edit, and Delete |
| `markdown/daily_nozzle_readings.md` | Full architecture and lifecycle of day-to-day nozzle meter tracking |
| `dip-lookup/lookup-by-mm.php` | Sub-2ms indexed lookup for matching `dip_mm` to `balance` (`dip_litre`) |
| `include/deletediplog.php` | Soft delete backend handler (`UPDATE tbl_tank_dip_logs SET deleted_at = NOW() WHERE id = ?`) |
| `include/navbar.php` | Navigation bar link updates |
| `markdown/dip_chart.md` | Module specification and complete documentation (this file) |

---

## 5. UI, Theme & Icon Standardization Summary

- **Theme Compliance (`markdown/theme.md`)**:
  - Primary color: `#04204e` (`var(--primary-color)`).
  - Primary buttons: `var(--primary-gradient)` (`.btn-primary`).
  - Modal Header: `var(--gradient-header)` with white text and clean close button (`<i class="fas fa-times"></i>`).
  - DataTables Header (`#dipLogsTable thead th`): `#04204e`.
- **Icon Compliance (`markdown/icon.md`)**:
  - Dip Chart Action Button: `<i class="fas fa-chart-line"></i> Dip Chart`
  - Add New Dip Log: `<i class="fas fa-plus mr-1"></i> Add Dip Log`
  - Save / Submit: `<i class="fas fa-save mr-1"></i> Save Dip Log`
  - Cancel / Back: `<i class="fas fa-times mr-1"></i> Cancel`
  - Delete Action: Red trash icon `<i class="fas fa-trash-alt" style="font-size: 20px;"></i>`
