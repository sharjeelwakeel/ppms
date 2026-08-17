# Expenses Management Module Documentation

This document provides technical documentation and reference details for the **Expenses Management Module** in PPMS.

---

## 1. Overview & Architecture

The Expenses module allows users to define custom **Expense Categories / Types** and record daily **Expense Entries** with dates, amounts, payment methods, bank link options, voucher reference numbers, and notes.

### Navigation Position
The module dropdown is located directly **to the left of Purchases** in `include/navbar.php`:
```
[Dashboard]  [Master]  [Expenses ▾]  [Purchases]  [Stock & Lubricants ▾]  [Transactions ▾]  [HR & Payroll ▾]
```

---

## 2. Database Schema

### Table: `tbl_expense_types`
Stores expense category classifications (e.g. Electricity, Maintenance, Office Supplies, Fuel Expense).

| Field | Type | Description |
| :--- | :--- | :--- |
| `id` | `INT(11)` (PK, AUTO_INC) | Primary Key |
| `name` | `VARCHAR(100)` | Name of the expense category |
| `description` | `VARCHAR(255)` | Optional details or category purpose |
| `status` | `ENUM('Active','Inactive')` | Active or Inactive status |
| `created_at` | `TIMESTAMP` | Timestamp when record was created |
| `updated_at` | `TIMESTAMP` | Timestamp when record was last updated |
| `deleted_at` | `DATETIME` | Soft delete timestamp (NULL if active) |

### Table: `tbl_expenses`
Stores daily logged expenses.

| Field | Type | Description |
| :--- | :--- | :--- |
| `id` | `INT(11)` (PK, AUTO_INC) | Primary Key |
| `expense_date` | `DATE` | Date of the expense |
| `expense_type_id` | `INT(11)` (FK) | References `tbl_expense_types.id` |
| `amount` | `DECIMAL(12,2)` | Expense amount in PKR |
| `payment_method` | `VARCHAR(50)` | Payment mode: `Cash`, `Bank Transfer`, `Card`, `Cheque` |
| `bank_id` | `INT(11)` (FK, NULL) | References `tbl_banks.id` (if non-cash) |
| `reference_no` | `VARCHAR(100)` | Receipt, invoice, or voucher reference number |
| `notes` | `TEXT` | Detailed notes or description |
| `created_by` | `INT(11)` | References `tbl_accounts.id` |
| `created_at` | `TIMESTAMP` | Record creation timestamp |
| `updated_at` | `TIMESTAMP` | Record update timestamp |
| `deleted_at` | `DATETIME` | Soft delete timestamp (NULL if active) |

---

## 3. Module File Inventory

| File Path | Description |
| :--- | :--- |
| [expenses/expense-types-list.php](file:///c:/xampp/htdocs/ppms/expenses/expense-types-list.php) | Category management page with Add/Edit modal dialogs |
| [expenses/add-expense.php](file:///c:/xampp/htdocs/ppms/expenses/add-expense.php) | Form to record a new daily expense |
| [expenses/edit-expense.php](file:///c:/xampp/htdocs/ppms/expenses/edit-expense.php) | Form to edit an existing expense record |
| [expenses/expenses-list.php](file:///c:/xampp/htdocs/ppms/expenses/expenses-list.php) | Server-side DataTables list view with KPI summary cards & filters |
| [expenses/expenses-ajax.php](file:///c:/xampp/htdocs/ppms/expenses/expenses-ajax.php) | AJAX server-side pagination, searching, and filtering handler |
| [include/deleteexpensetype.php](file:///c:/xampp/htdocs/ppms/include/deleteexpensetype.php) | Soft delete handler for expense categories |
| [include/deleteexpense.php](file:///c:/xampp/htdocs/ppms/include/deleteexpense.php) | Soft delete handler for expense entries |

---

## 4. Permissions & Access Control

Registered in `include/permissions.php` under key `'expenses' => 'Expenses Management'`:
- **`show`**: Access expense list & category views.
- **`add`**: Access expense creation & category creation.
- **`edit`**: Access expense modification & category editing.
- **`delete`**: Soft delete permissions.
