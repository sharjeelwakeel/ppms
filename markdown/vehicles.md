# Customer Vehicles Module Documentation (`markdown/vehicles.md`)

## 1. Overview
The **Customer Vehicles** module manages authorized vehicles (`tbl_customer_vehicles`) associated with client accounts (`tbl_customers`). It allows petrol pump stations to register vehicle details, license plate registration numbers, fleet numeric identifiers, fuel types (`Petrol` / `Diesel`), and maximum quota limits (`fuel_limit` in Litres).

---

## 2. Database Schema (`tbl_customer_vehicles`)

```sql
CREATE TABLE IF NOT EXISTS `tbl_customer_vehicles` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `customer_id` INT(11) NOT NULL,
  `vehicle_name` VARCHAR(128) NOT NULL,
  `reg_number` VARCHAR(64) NOT NULL,
  `numeric_number` VARCHAR(64) DEFAULT NULL,
  `fuel_limit` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `vehicle_type` ENUM('Petrol', 'Diesel') NOT NULL DEFAULT 'Petrol',
  `status` ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_reg_number` (`reg_number`),
  KEY `idx_numeric_number` (`numeric_number`),
  KEY `idx_vehicle_type` (`vehicle_type`),
  KEY `idx_status` (`status`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 3. Form Fields & Dropdown Specifications

| Field Name | Type | Form Input | Default Value | Options / Behavior |
|---|---|---|---|---|
| **Customer** | `INT(11)` | Dropdown Select (Required) | None / Pre-selected | Foreign key linking to `tbl_customers.id` |
| **Vehicle Name** | `VARCHAR(128)` | Text Input (Required) | None | Make & model (e.g. `Toyota Corolla`, `Hino 500`) |
| **Registration Number** | `VARCHAR(64)` | Text Input (Required) | None | Official license plate (e.g. `LEA-2024`, `KHI-8956`) |
| **Numeric Number** | `VARCHAR(64)` | Text Input | None | Fleet unit number / numeric suffix (e.g. `2024`, `8956`) |
| **Fuel Limit** | `DECIMAL(10,2)` | Number Input | `0.00` | Quota in Litres (`0.00` represents Unlimited) |
| **Vehicle Type** | `ENUM('Petrol', 'Diesel')` | Dropdown Select | **`Petrol`** | `Petrol`, `Diesel` |
| **Suspended / Status** | `ENUM('Active', 'Inactive')` | Dropdown Select | **`Active`** | `Active`, `Inactive` |

---

## 4. Role-Based Permissions Integration

The Customer Vehicles module is registered under the PPMS RBAC permission framework (`include/permissions.php`):
- **Module Slug**: `'vehicles'`
- **Module Name**: `'Customer Vehicles'`
- **Granular Actions**:
  - `can_show`: View vehicle lists (`customers/vehicles.php`)
  - `can_add`: Create new vehicle records (`customers/add-vehicle.php`)
  - `can_edit`: Update vehicle records (`customers/edit-vehicle.php`)
  - `can_delete`: Soft-delete vehicle records (`include/deletevehicle.php`)
- **Customer List Integration**: Each row in `customers/customers-list.php` features a **Vehicles (N)** button displaying the active vehicle count and linking directly to filtered vehicle management for that customer.

---

## 5. File Architecture

| File Path | Description |
|---|---|
| `customers/vehicles.php` | List of vehicles with filter support for single customer or all customers |
| `customers/add-vehicle.php` | Form to register a new vehicle with pre-selected customer support |
| `customers/edit-vehicle.php` | Form to update vehicle specs, registration number, and fuel quota |
| `include/deletevehicle.php` | Soft-delete handler setting `deleted_at = NOW()` |
| `markdown/vehicles.md` | Module technical specification and documentation (this file) |
