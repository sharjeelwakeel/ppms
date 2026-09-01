# Nozzles Module Complete Documentation (`markdown/nozzles.md`)

## 1. Overview
The **Nozzles** module manages fuel dispensing guns (`tbl_nozzles`) mounted on dispensing units and connected to fuel storage tanks (`tbl_tanks`). Each nozzle tracks its physical identifier, attached tank, dispensing fuel product (`item_id`), status (`Active` / `Inactive`), and running meter start reading (`start_reading`).

---

## 2. Database Schema (`tbl_nozzles`)

```sql
CREATE TABLE IF NOT EXISTS `tbl_nozzles` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `tank_id` INT(11) NOT NULL,
  `item_id` INT(11) NOT NULL,
  `start_reading` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tank_id` (`tank_id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 3. Meter Reading Validation Rules & Defaults

### 1. Default Value on Creation (`add-nozzle.php`)
- When creating a new nozzle, the **Current Reading** (`start_reading`) field is initialized with **`0.00`** by default.
- Creation requires non-negative values ($\text{start\_reading} \ge 0.00$).

### 2. Current Reading Lock on Edit (`edit-nozzle.php`)
- When editing a nozzle, the **Current Reading** (`start_reading`) field is locked to **read-only** mode with informative status guidance (`<i class="fas fa-lock mr-1"></i> Running meter reading is updated automatically from Meter Readings.`).
- This prevents manual tampering of the running meter from the Nozzles master form, ensuring that nozzle readings can only be changed/advanced sequentially through **Meter Readings** (`meter-readings/add-meter-reading.php`).

---

## 4. Tank & Item Association Rules

- **Auto-Sync**: When a Tank is selected from the dropdown, the frontend and backend automatically link the nozzle to that tank's configured fuel product (`tbl_tanks.item_id`).
- **Server Validation**: The backend overrides and confirms the `item_id` from `tbl_tanks` on form submission to guarantee relational integrity.

---

## 5. Running Meter Synchronization

- Whenever a sales shift or daily meter reading is finalized (`meter-readings/add-meter-reading.php`), the closing meter reading is automatically recorded to update `tbl_nozzles.start_reading`:
  ```sql
  UPDATE tbl_nozzles SET start_reading = '$current_reading' WHERE id = '$nozzle_id';
  ```
- This ensures sequential meter reading calculations for next shift / dip log reconciliation.

---

## 6. File Architecture

| File Path | Description |
|---|---|
| `nozzles/nozzles-list.php` | List of all nozzles with attached tank, product item, timestamps, and action controls |
| `nozzles/add-nozzle.php` | Form to create a nozzle with default `0.00` current reading and auto-tank item binding |
| `nozzles/edit-nozzle.php` | Form to edit nozzle details with $\ge$ previous reading validation (allowing equal values) |
| `include/deletenozzle.php` | Backend AJAX handler for soft-deleting nozzles (`deleted_at = NOW()`) |
| `markdown/nozzles.md` | Module specification and complete documentation (this file) |

---

## 7. UI Theme & Icon Standards

- **Theme Compliance (`markdown/theme.md`)**:
  - Primary color: `#04204e` (`var(--primary-color)`).
  - Primary buttons: `var(--primary-gradient)` (`.btn-primary`).
  - Table Header (`#nozzlesListTable thead th`): `#04204e`.
- **Icons (FontAwesome 5)**:
  - Nozzles Module Header: `<i class="fas fa-burn mr-2 text-primary"></i>`
  - Add New Nozzle: `<i class="fas fa-plus mr-1"></i> Add New Nozzle`
  - Save Nozzle: `<i class="fas fa-save mr-1"></i> Save Nozzle`
  - Cancel: `<i class="fas fa-times mr-1"></i> Cancel`
  - Delete Nozzle: `<i class="fas fa-trash-alt text-danger"></i>`
