# Station Settings & PDF Branding Specification (`markdown/settings.md`)

## 1. Overview
The **Station Settings** module enables petrol pump operators and administrators to configure global station identity, contact details, physical address, registration numbers (NTN / License), footer policy notes, and logo assets.

These settings are automatically propagated across all system PDF exports, printable receipts, customer ledgers, meter reading reports, and voucher manifests.

---

## 2. Database Schema (`tbl_settings`)

```sql
CREATE TABLE IF NOT EXISTS `tbl_settings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `pump_name` VARCHAR(255) NOT NULL DEFAULT 'PPMS Petrol Pump',
  `tagline` VARCHAR(255) DEFAULT 'Petrol Pump Management System',
  `phone` VARCHAR(100) DEFAULT '',
  `email` VARCHAR(100) DEFAULT '',
  `address` TEXT DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT '',
  `ntn_no` VARCHAR(100) DEFAULT '',
  `license_no` VARCHAR(100) DEFAULT '',
  `receipt_footer` TEXT DEFAULT 'Thank you for your business! Fuel once sold will not be returned.',
  `logo_path` VARCHAR(255) DEFAULT '',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 3. Architecture & Helper Functions

### 1. Centralized Retrieval (`include/settings_helper.php`)
Any page or PDF generator retrieves sanitized station settings via `get_station_settings($connection)`:

```php
require_once __DIR__ . '/../include/settings_helper.php';

$station_settings = get_station_settings($connection);
$hasLogo = !empty($station_settings['logo_path']) && file_exists(__DIR__ . '/../' . $station_settings['logo_path']);
```

### 2. Smart Conditional Logo Rendering
- **When Logo Exists**: Renders logo in the header with a flex/table layout alongside the station name, tagline, address, and phone.
- **When No Logo Exists**: The station branding text naturally expands across the header without broken image icons or awkward empty placeholder gaps.

---

## 4. Navigation & User Profile Dropdown

- **Navbar Profile Dropdown**: Replacing the static logout button, clicking the user profile badge toggles a dropdown menu containing:
  1. Current logged-in user and role badge
  2. ⚙️ **Station Settings** (`settings/station-settings.php`)
  3. 🚪 **Logout** (`include/logout.php`)
- **Master Menu**: Kept clean for operational master records (Customers, Tanks, Items, Shifts, Nozzles, Staff, System Users, etc.). Station Settings is intentionally excluded from the Master menu and accessible exclusively via the User Profile dropdown.
- **RBAC Slug**: Permission checked via `has_permission('settings', 'show')` and `has_permission('settings', 'edit')` (with automatic bypass for Admin roles).

---

## 5. File Architecture

| File Path | Description |
|---|---|
| `include/settings_helper.php` | Self-healing migration helper & centralized settings retrieval API |
| `settings/station-settings.php` | Station branding management form with logo upload & validation |
| `include/navbar.php` | Dynamic user profile dropdown and Master menu integration |
| `meter-readings/generate-pdf-meter-reading.php` | Shift meter reading statement with dynamic letterhead |
| `reports/generate-pdf-customer-report.php` | Customer credit ledger report with dynamic station branding |
| `accounts/generate-pdf-receipt.php` | Payment receipt voucher with dynamic branding & policy footer |
| `credit-sales/generate-pdf-credit-sale.php` | Credit sales manifest with dynamic letterhead |
| `card-sales/generate-pdf-card-sale.php` | Card sales POS report with dynamic letterhead |
| `card-sales/generate-pdf-settlement.php` | Card batch settlement report with dynamic letterhead |
