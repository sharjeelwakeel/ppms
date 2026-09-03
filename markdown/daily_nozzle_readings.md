# Daily Nozzle Readings & Dip Chart Synchronization

## 1. Overview & Purpose

In petrol pump management, reconciling physical storage tank levels (via Tank Dips) against dispensed fuel requires **accurate, historical day-to-day nozzle meter readings**.

While `tbl_nozzles` stores the single current running meter position, the **Daily Nozzle Readings Snapshot Table (`tbl_daily_nozzle_readings`)** records the exact:
1. **Opening Meter Reading**
2. **Closing Meter Reading**
3. **Total Dispensed Fuel Volume (Litres)**

for every active nozzle across each **Date** and **Shift**, providing a full audit trail and driving the Tank Dip Reconciliation Chart.

---

## 2. Database Architecture

### Table: `tbl_daily_nozzle_readings`

```sql
CREATE TABLE IF NOT EXISTS `tbl_daily_nozzle_readings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `date` DATE NOT NULL,
  `shift_id` INT(11) NOT NULL DEFAULT 0,
  `nozzle_id` INT(11) NOT NULL,
  `tank_id` INT(11) NOT NULL DEFAULT 0,
  `opening_reading` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `closing_reading` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `dispensed_litres` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `source` ENUM('meter_reading', 'card_sale', 'manual_dip', 'auto_sync') NOT NULL DEFAULT 'auto_sync',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_date_shift_nozzle` (`date`, `shift_id`, `nozzle_id`),
  KEY `idx_date` (`date`),
  KEY `idx_nozzle_id` (`nozzle_id`),
  KEY `idx_tank_id` (`tank_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 3. Real-Time Synchronization Lifecycle

All operations (**Add**, **Edit**, and **Delete**) across sales and dip modules are synchronized in real-time via [`include/nozzle_daily_sync.php`](../include/nozzle_daily_sync.php):

### A. Meter Readings (`meter-readings/`)
1. **Add Meter Reading (`add-meter-reading.php`)**:
   - Upserts into `tbl_daily_nozzle_readings` for that `(date, shift_id, nozzle_id)` with `opening_reading = last_reading`, `closing_reading = current_reading`, and `dispensed_litres = net_sale`.
2. **Edit Meter Reading (`edit-meter-reading.php`)**:
   - Updates `opening_reading`, `closing_reading`, and `dispensed_litres` to reflect modified meter numbers.
3. **Delete Meter Reading (`include/deletemeterreading.php`)**:
   - Cleans up daily nozzle entries (`DELETE FROM tbl_daily_nozzle_readings WHERE date = '$date' AND shift_id = '$shift_id' AND source = 'meter_reading'`).

### B. Card Sales (`card-sales/`)
Card sales calculate fuel quantity ($\text{qty} = \frac{\text{amount}}{\text{rate}}$) and adjust daily readings:
1. **Add Card Sale (`add-card-sale.php`)**:
   - Increments `closing_reading` and `dispensed_litres` by `+qty` in `tbl_daily_nozzle_readings`.
2. **Edit Card Sale (`edit-card-sale.php`)**:
   - Reverts previously logged fuel quantity (`-prev_qty`) and adds updated quantity (`+new_qty`).
3. **Delete Card Sale (`include/deletecardsale.php`)**:
   - Soft-deleting card sales rolls back dispensed volume (`-total_qty`) from both `tbl_nozzles` and `tbl_daily_nozzle_readings`.

### C. Credit Sales (`credit-sales/`)
Credit sales track fuel quantities across individual customer slips:
1. **Add Credit Sale (`add-credit-sale.php`)**:
   - For each slip, dispensed volume ($+\text{qty}$) advances the nozzle's running meter in `tbl_nozzles` and updates `tbl_daily_nozzle_readings`.
2. **Edit Credit Sale (`edit-credit-sale.php`)**:
   - Reverts previous slip volumes ($-\text{old\_qty}$) and applies new slip volumes ($+\text{new\_qty}$) atomically.
3. **Delete Credit Sale (`include/deletecreditsale.php`)**:
   - Soft-deleting credit slips by date/shift or single slip ID automatically deducts the exact fuel volume (`-total_qty`) from `tbl_nozzles` and `tbl_daily_nozzle_readings`.

### D. Tank Dip Logs (`tanks/`)
1. **Add / Edit Dip Log (`add-dip-log.php`, `edit-dip-log.php`)**:
   - Saves nozzle meter readings into `tbl_tank_dip_meter_logs` and synchronizes `tbl_daily_nozzle_readings` with `closing_reading` and `dispensed_litres`.

---

## 4. Tank Dip Chart Integration

### 1. Auto-Fill API (`tanks/get-tank-meter-readings.php`)
When an operator selects a **Date** and **Shift** in the Dip Log form:
1. Checks `tbl_daily_nozzle_readings` for that exact date and shift.
2. If found:
   - **Previous Reading** = `opening_reading`
   - **Current Reading** = `closing_reading`
   - **Usage** = `dispensed_litres`
3. If not found:
   - Falls back to the latest previous day's closing reading as **Previous Reading** and the live `tbl_nozzles.start_reading` as **Current Reading**.
4. The operator can review, confirm, or adjust readings directly.

### 2. Dip Chart View (`tanks/dip-chart.php`)
The Dip Logs data table displays a dedicated **Nozzle Meters** column featuring styled badges showing the exact reading of each nozzle on that day:
```html
<span class="badge badge-light border text-dark font-weight-normal mr-1 mb-1">
  <strong>Nozzle A:</strong> 4,000.00
</span>
```

---

## 5. File Architecture

| File Path | Description |
|---|---|
| `include/nozzle_daily_sync.php` | Centralized helper functions for synchronizing daily nozzle meters |
| `meter-readings/add-meter-reading.php` | Syncs opening, closing, and net sales on new shift entry |
| `meter-readings/edit-meter-reading.php` | Syncs modified meter readings to daily ledger |
| `include/deletemeterreading.php` | Cleans up daily readings when meter reading record is deleted |
| `card-sales/add-card-sale.php` | Increments daily nozzle closing reading and dispensed volume |
| `card-sales/edit-card-sale.php` | Reverts old card sale litres and applies new litres |
| `include/deletecardsale.php` | Reverts card sale litres on date or single transaction deletion |
| `tanks/get-tank-meter-readings.php` | JSON API feeding exact day-to-day readings into Dip Log forms |
| `tanks/add-dip-log.php` | Dip entry form pre-filling from daily nozzle snapshots |
| `tanks/edit-dip-log.php` | Dip edit form updating dip meters and daily ledger |
| `tanks/dip-chart.php` | Visual reconciliation chart displaying daily nozzle meter readings |
