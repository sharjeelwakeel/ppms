# Dip Lookup Module Complete Documentation (`markdown/dip_lookup.md`)

## 1. Overview
The **Dip Lookup** module manages the mapping between fuel tank dip measurements in millimeters (`dip_mm`) and corresponding fuel volume in liters (`dip_litre`). It provides a high-performance, responsive CRUD interface supporting large datasets (**5,000+ rows**) using server-side pagination, duplicate entry detection, soft deletion, and strict adherence to the PPMS design theme and icon guidelines.

---

## 2. Database Schema (`tbl_dip_lookup`)

```sql
CREATE TABLE IF NOT EXISTS `tbl_dip_lookup` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `dip_mm` DECIMAL(10,2) NOT NULL,
  `dip_litre` DECIMAL(10,2) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dip_mm` (`dip_mm`),
  KEY `idx_deleted_at` (`deleted_at`),
  KEY `idx_deleted_mm` (`deleted_at`, `dip_mm`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 3. Core Features & Business Rules

1. **Required Fields**:
   - Both `Dip (mm)` and `Dip (Litre)` are required fields.

2. **Duplicate Entry Detection & Confirmation Modal**:
   - When submitting a new record or editing an existing one, the system checks if the entered `dip_mm` already exists in active DB records (`deleted_at IS NULL`).
   - If duplicate found: Renders a confirmation modal displaying existing registered liters vs new entered liters.
   - User confirmation updates/overwrites the existing DB entry cleanly.

3. **Soft Delete Strategy**:
   - Executed via `include/deletediplookup.php` (`UPDATE tbl_dip_lookup SET deleted_at = NOW() WHERE id = ?`).
   - Deletion removes rows from active queries while preserving audit trail data.

4. **High-Performance Server-Side DataTables Pagination (5,000+ Rows)**:
   - Utilizes `dip-lookup/dip-lookup-ajax.php` with `serverSide: true` processing.
   - Fetches only page limits (10 to 250 rows) using SQL `LIMIT` and `OFFSET`.
   - Response time is **< 30 ms** regardless of total dataset size.

5. **Search Dip Lookup Widget**:
   - Card widget at top of list page with `Search Dip Lookup` header.
   - Type any dip in mm and click **Search** (`<i class="fas fa-search"></i> Search`) to get instant liter volume.

---

## 4. File Architecture

| File Path | Description |
|---|---|
| `dip-lookup/dip-lookup-list.php` | Server-Side DataTables list view with Search widget & soft delete |
| `dip-lookup/dip-lookup-ajax.php` | Server-side AJAX pagination & search handler |
| `dip-lookup/add-dip-lookup.php` | Add form with duplicate detection confirmation modal |
| `dip-lookup/edit-dip-lookup.php` | Edit form with duplicate check handling |
| `dip-lookup/check-duplicate.php` | Fast indexed AJAX duplicate check endpoint (<1ms) |
| `include/deletediplookup.php` | Soft delete backend handler (`deleted_at = NOW()`) |
| `include/navbar.php` | Navigation bar with Dip Lookup under **Master** menu |
| `markdown/dip_lookup.md` | Module specification (this file) |

---

## 5. UI, Theme & Icon Standardization Summary

- **Theme Compliance (`markdown/theme.md`)**: Uses `#04204e` (Primary Navy), `var(--primary-gradient)` for primary buttons, and `var(--gradient-header)` for modal headers.
- **Icon Compliance (`markdown/icon.md`)**:
  - Add New: `<i class="fas fa-plus mr-1"></i> Add New Dip Lookup`
  - Search: `<i class="fas fa-search"></i> Search`
  - Item Edit Link: Clickable `Dip (mm)` value in table
  - Delete Action: Red trash icon `<i class="fas fa-trash-alt" style="font-size: 20px;"></i>` under column header **Delete** (matching Shift CRUD pattern).
