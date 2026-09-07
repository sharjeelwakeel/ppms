# Items & Products Module Complete Documentation (`markdown/items.md`)

## 1. Overview
The **Items & Products** module manages the core product catalog (`tbl_items`) in the Petrol Pump Management System (PPMS). Items represent fuel types (e.g., Super Petrol, High Speed Diesel), lubricants, engine oils, and retail accessories. 

To support real-world fuel station operations where fuel tariffs change periodically, the system implements a **polymorphic 1-to-many pricing architecture** (`tbl_prices`). Each item maintains a full chronological price change history, while exactly **one price is marked active (`is_active = 1`)** at any given time. Active rates are simultaneously cached in `tbl_items` for zero-overhead, 100% backward compatibility with all daily sales, meter reading, and billing modules.

---

## 2. Database Schemas

### 2.1 Fuel Items Catalog (`tbl_items`)
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

### 2.2 Polymorphic Price History (`tbl_prices`)
The `tbl_prices` table is designed to be **polymorphic** (`table_name` and `table_id`), allowing it to serve both fuel items (`tbl_items`) and lubricant products (`tbl_lubricant_products` or future catalog entities) without duplicating pricing tables.

```sql
CREATE TABLE IF NOT EXISTS `tbl_prices` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `table_name` VARCHAR(64) NOT NULL DEFAULT 'tbl_items',
  `table_id` INT(11) NOT NULL,
  `cash_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `credit_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `purchase_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `effective_date` DATE NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_table_lookup` (`table_name`, `table_id`, `is_active`),
  KEY `idx_effective_date` (`effective_date`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 3. Polymorphic Pricing Architecture & Rules

### 1. 1-to-Many Relationship
- Each parent item in `tbl_items` can have multiple historical price points in `tbl_prices` (`table_name = 'tbl_items' AND table_id = <item_id>`).
- Exactly **one** record per item has `is_active = 1` representing the currently applicable selling and purchase tariffs.

### 2. Atomic Price Revision Workflow
When a price is revised (via modal or edit form):
1. An atomic database transaction or synchronized sequence is executed.
2. All existing active prices for that item are marked historical:
   ```sql
   UPDATE tbl_prices SET is_active = 0 
   WHERE table_name = 'tbl_items' AND table_id = ? AND is_active = 1
   ```
3. The new price tier is inserted with `is_active = 1`, recording the `effective_date` (when the new tariff takes effect) and audit `notes` (e.g. "OGRA fortnightly revision").
4. The cached rate columns on `tbl_items` (`cash_rate`, `credit_rate`, `purchase_rate`) are updated simultaneously to match the new active price.

### 3. Zero-Breakage Backward Compatibility
All existing transactions—such as card sales, credit sales slips, meter readings, shift reports, and supplier purchase orders—read from `tbl_items` without alteration or performance degradation.

---

## 4. Shared Price Helper (`include/price_helper.php`)

| Function | Signature | Description |
|---|---|---|
| `init_prices_table` | `init_prices_table($connection)` | Self-healing helper that ensures `tbl_prices` exists and automatically seeds any unseeded `tbl_items` records as active baselines. |
| `set_active_price` | `set_active_price($connection, $table_name, $table_id, $cash_rate, $credit_rate, $purchase_rate, $effective_date, $notes, $user_id)` | Atomically deactivates prior prices, inserts the new active price row, and updates cached rates in the parent entity table. |
| `get_price_history` | `get_price_history($connection, $table_name, $table_id)` | Returns all non-deleted historical and active price records ordered by `is_active DESC, effective_date DESC, id DESC`. |
| `soft_delete_price` | `soft_delete_price($connection, $price_id)` | Soft deletes a price record (`deleted_at = NOW()`). If the deleted price was active, promotes the latest remaining non-deleted price to active and syncs `tbl_items` cache. |

---

## 5. UI Features & Workflows

1. **Items List (`items/items-list.php`)**:
   - Displays current active rates with styled badges.
   - **Revise Price Modal** (`#updatePriceModal`): Quickly update cash, credit, and purchase rates with effective date and notes directly from the listing table without navigating away.
   - **Price History Modal** (`#priceHistoryModal`): Instant AJAX loading (`items/get-price-history.php`) showing full chronological timeline with active/past badges, effective dates, audit notes, and row-level soft delete buttons.
2. **Add Item (`items/add-item.php`)**:
   - Collects initial cash, credit, and purchase rates along with `effective_date` (defaults to current date) and optional `notes`.
   - Automatically initializes the item's baseline active record in `tbl_prices`.
3. **Edit Item (`items/edit-item.php`)**:
   - Detects if rates were modified; if changed, activates the new rate and archives the prior rate in `tbl_prices`.
   - Features an embedded **Price Change History** table card at the bottom of the form displaying the item's full pricing audit trail and soft delete action controls.

---

## 6. Role-Based Access Control (RBAC) & Soft Deletion

- **Permissions**:
  - `items.show`: View list of items (`items/items-list.php`) and price history modals.
  - `items.add`: Create new items (`items/add-item.php`).
  - `items.edit`: Modify item details and revise prices (`items/edit-item.php`, price revision modal).
  - `items.delete`: Soft-delete an item (`include/deleteitem.php`) or individual price records (`items/delete-price.php`).
- **Strict Soft Deletion Paradigm**:
  - **Zero Hard Deletes**: Neither items nor price points are ever hard deleted (`DELETE FROM tbl_prices` is strictly prohibited).
  - **Soft Delete Execution**: Soft deletion executes `UPDATE tbl_prices SET deleted_at = NOW(), is_active = 0 WHERE id = ?`.
  - **Active Price Promotion & Recovery**: If the deleted price was marked active (`is_active = 1`), the system automatically promotes the latest non-deleted remaining price (`ORDER BY effective_date DESC, id DESC LIMIT 1`) to `is_active = 1` and synchronizes the cached rates on `tbl_items`.
  - **Cascading Soft Delete**: When an entire item is soft-deleted via `include/deleteitem.php`, all associated records in `tbl_prices` are concurrently soft-deleted (`deleted_at = NOW()`).
  - **Query Filter**: All queries across the system filter out soft-deleted prices using `(deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')`.

---

## 7. File Architecture

| File Path | Description |
|---|---|
| `include/price_helper.php` | Shared polymorphic pricing helper (`init_prices_table`, `set_active_price`, `get_price_history`, `soft_delete_price`) |
| `items/get-price-history.php` | AJAX JSON endpoint returning full price history for an item |
| `items/delete-price.php` | AJAX endpoint for soft-deleting individual price records |
| `items/items-list.php` | Items catalog table with on-the-fly Price Revision & Price History modals |
| `items/add-item.php` | Form to create a new item, seeding initial active price in `tbl_prices` |
| `items/edit-item.php` | Form to edit item, revise rates, and view embedded price history table with delete actions |
| `include/deleteitem.php` | Backend AJAX handler for soft-deleting items and cascading to `tbl_prices` |
| `markdown/items.md` | Module specification and complete documentation (this file) |

---

## 8. UI Theme & Icon Standards

- **Theme Compliance (`markdown/theme.md`)**:
  - Primary color: `#04204e` (`var(--primary-color)`).
  - Primary buttons: `var(--primary-gradient)` (`.btn-primary`).
  - Table Headers: `#04204e`.
  - Active Price Badge: `badge badge-success` with `<i class="fas fa-check-circle mr-1"></i>`.
  - Past Price Badge: `badge badge-secondary` with `<i class="fas fa-history mr-1"></i>`.
- **Icons (FontAwesome 5)**:
  - Items Module Header: `<i class="fas fa-boxes mr-2 text-primary"></i>`
  - Add New Item: `<i class="fas fa-plus"></i>`
  - Edit Item: `<i class="fas fa-edit"></i>`
  - Price Revision: `<i class="fas fa-tag"></i>`
  - Price History: `<i class="fas fa-history"></i>`
  - Save Item / Update Price: `<i class="fas fa-save mr-1"></i>`
  - Cancel / Close: `<i class="fas fa-times mr-1"></i>`
  - Delete Item / Price: `<i class="fas fa-trash-alt text-danger"></i>`


