# Items & Products Module Complete Documentation (`markdown/items.md`)

## 1. Overview
The **Items & Products** module manages the core product catalog (`tbl_items`) in the Petrol Pump Management System (PPMS). Items represent fuel types (e.g., Super Petrol, High Speed Diesel), lubricants, engine oils, and retail accessories. Each item defines its measurement unit, retail cash selling rate, credit selling rate, and supplier purchase rate.

---

## 2. Database Schema (`tbl_items`)

```sql
CREATE TABLE IF NOT EXISTS `tbl_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `cash_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `credit_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `purchase_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `unit` VARCHAR(32) NOT NULL DEFAULT 'Ltr',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 3. Unit Dropdown Specifications & Rules

### 1. Dropdown Selection (`Ltr`)
- Both Create (`items/add-item.php`) and Edit (`items/edit-item.php`) forms feature a standardized `<select name="unit" class="form-control" required>` dropdown with **`Ltr`** configured as the standard unit option.

### 2. Consistency across Tables
- The default unit across PPMS fuel items, tank storage capacities, dip logs, and purchase links standardizes on **`Ltr`**.

---

## 4. Rate & Pricing Structure

| Field | Type | Description |
|---|---|---|
| `cash_rate` | `DECIMAL(10,2)` | Selling rate per unit for cash sales and direct pump transactions |
| `credit_rate` | `DECIMAL(10,2)` | Selling rate per unit charged to credit billing customers |
| `purchase_rate` | `DECIMAL(10,2)` | Procurement rate per unit from fuel distributors and suppliers |

---

## 5. Role-Based Access Control (RBAC) & Soft Deletion

- **Permissions**:
  - `items.show`: View list of items (`items/items-list.php`).
  - `items.add`: Create new items (`items/add-item.php`).
  - `items.edit`: Modify item details and rates (`items/edit-item.php`).
  - `items.delete`: Soft-delete an item (`include/deleteitem.php`).
- **Soft Delete**:
  - Items are never permanently removed from the database; deletions set `deleted_at = NOW()`.
  - Queries filter active items with `(deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')`.

---

## 6. File Architecture

| File Path | Description |
|---|---|
| `items/items-list.php` | List of items/products with rates, unit, search, pagination, and action controls |
| `items/add-item.php` | Form to create a new item with unit dropdown defaulting to `Ltr` |
| `items/edit-item.php` | Form to update item details, rates, and unit dropdown with auto-selected current unit |
| `include/deleteitem.php` | Backend AJAX handler for soft-deleting items |
| `markdown/items.md` | Module specification and complete documentation (this file) |

---

## 7. UI Theme & Icon Standards

- **Theme Compliance (`markdown/theme.md`)**:
  - Primary color: `#04204e` (`var(--primary-color)`).
  - Primary buttons: `var(--primary-gradient)` (`.btn-primary`).
  - Table Header (`#itemsListTable thead th`): `#04204e`.
- **Icons (FontAwesome 5)**:
  - Items Module Header: `<i class="fas fa-boxes mr-2 text-primary"></i>`
  - Add New Item: `<i class="fas fa-plus"></i> Add New Item`
  - Save Item: `<i class="fas fa-save mr-1"></i> Save Item`
  - Cancel: `<i class="fas fa-times mr-1"></i> Cancel`
  - Delete Item: `<i class="fas fa-trash-alt text-danger"></i>`
