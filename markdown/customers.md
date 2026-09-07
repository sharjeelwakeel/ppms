# Customer Master Module Documentation (`markdown/customers.md`)

## 1. Overview
The **Customer Master** module manages registered client and company accounts (`tbl_customers`) for the Petrol Pump Management System (PPMS). It centralizes customer contact information, default rate tier classifications for fuel (`fuel_rate`) and non-fuel/lubricants (`other_rate`), and suspension management (`status`).

---

## 2. Database Schema (`tbl_customers`)

```sql
CREATE TABLE IF NOT EXISTS `tbl_customers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `address` TEXT DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `fuel_rate` ENUM('Cash', 'Credit') NOT NULL DEFAULT 'Cash',
  `other_rate` ENUM('Cash', 'Credit') NOT NULL DEFAULT 'Cash',
  `status` ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_fuel_rate` (`fuel_rate`),
  KEY `idx_other_rate` (`other_rate`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 3. Form Fields & Clean Dropdown Options

| Field Name | Type | Form Input | Default Value | Options |
|---|---|---|---|---|
| **Name** | `VARCHAR(255)` | Text Input (Required) | None | Customer / business name |
| **Phone** | `VARCHAR(50)` | Text Input | None | Phone number |
| **Address** | `TEXT` | Textarea | None | Address / location |
| **Fuel Rate** | `ENUM('Cash', 'Credit')` | Dropdown Select | **`Cash`** | `Cash`, `Credit` |
| **Other Rate** | `ENUM('Cash', 'Credit')` | Dropdown Select | **`Cash`** | `Cash`, `Credit` |
| **Suspended** | `ENUM('Active', 'Inactive')` | Dropdown Select | **`Active`** | `Active`, `Inactive` |

---

## 4. Rate Tier Classification & Transaction Rules (`fuel_rate` & `other_rate`)

In PPMS, registered customers can be billed under different pricing models depending on their commercial agreements:

### 1. Fuel Rate (`fuel_rate`)
- **Applies To**: All fuel products / items (`tbl_items`), such as Super Petrol, High Speed Diesel.
- **Values**:
  - **`Cash`**: The customer is charged the item's standard retail cash price (`tbl_items.cash_rate`).
  - **`Credit`**: The customer is charged the item's contracted credit selling price (`tbl_items.credit_rate`).
- **Transaction Enforcement**:
  - **Card Sales (`card-sales/`)**: When a transaction is marked with a customer or credit term, the fuel rate used to calculate sale volume and revenue is the item's **Credit Rate** (`sale_rate = credit_rate`). If cash terms, the sale rate is the item's **Cash Rate** (`sale_rate = cash_rate`).
  - **Credit Sales (`credit-sales/`)**: When a registered vehicle is entered, the system resolves the customer. If the customer's `fuel_rate == 'Credit'`, the slip rate automatically defaults to `item.credit_rate`. If `fuel_rate == 'Cash'`, it defaults to `item.cash_rate`.

### 2. Other Rate (`other_rate`)
- **Applies To**: Non-fuel items, including packaged lubricants, engine oils, and station accessories (`tbl_lubricant_products` / `tbl_products`).
- **Values**:
  - **`Cash`**: Non-fuel items are billed at the retail cash price.
  - **`Credit`**: Non-fuel items are billed at credit tier rates.

---

## 5. Role-Based Permissions Integration

The Customer Master is fully integrated with the PPMS RBAC permission framework (`include/permissions.php`):
- **Module Slug**: `'customers'`
- **Module Name**: `'Customer Master'`
- **Granular Actions**:
  - `can_show`: View customer directory (`customers/customers-list.php`)
  - `can_add`: Create new customer profile (`customers/add-customer.php`)
  - `can_edit`: Update customer profile (`customers/edit-customer.php`)
  - `can_delete`: Soft-delete customer record (`include/deletecustomer.php`)
- **Navigation Integration**: Visible under the **Master** menu in [`include/navbar.php`](../include/navbar.php) when the user has `show` access.

---

## 5. File Architecture

| File Path | Description |
|---|---|
| `customers/customers-list.php` | List of all registered customers with rate tiers, status badges, and action buttons |
| `customers/add-customer.php` | Form to create a new customer with clean `Cash`/`Credit` and `Active`/`Inactive` dropdowns |
| `customers/edit-customer.php` | Form to update customer contact info, pricing tiers, and active status |
| `include/deletecustomer.php` | Soft-delete handler setting `deleted_at = NOW()` |
| `reports/customer-report.php` | Comprehensive Customer Credit & Fuel Ledger Report with metric cards and slip drilldown |
| `markdown/customers.md` | Module technical specification and documentation (this file) |
| `markdown/customer_report.md` | Customer report technical specification and user guide |
