# Purchase & Payments Module Complete Documentation (`markdown/purchase.md`)

## 1. Overview
The **Purchase & Payments** module in the Petrol Pump Management System (PPMS) manages fuel and product procurement records (`tbl_purchases`) and tracks partial or full bank payment disbursements (`tbl_purchase_payments`). Payments are strictly recorded against bank accounts (`tbl_banks`) and are not attached to individual tanks.

---

## 2. Database Schemas

### Purchase Header Table (`tbl_purchases`)

```sql
CREATE TABLE IF NOT EXISTS `tbl_purchases` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `item_id` INT(11) NOT NULL,
  `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `date` DATE NOT NULL,
  `route` VARCHAR(255) DEFAULT NULL,
  `invoice_number` VARCHAR(128) DEFAULT NULL,
  `carriage_invoice_number` VARCHAR(128) DEFAULT NULL,
  `payment_status` ENUM('unpaid', 'in process', 'paid') NOT NULL DEFAULT 'unpaid',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_payment_status` (`payment_status`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### Purchase Payments Table (`tbl_purchase_payments`)

```sql
CREATE TABLE IF NOT EXISTS `tbl_purchase_payments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `purchase_id` INT(11) NOT NULL,
  `date` DATE NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `bank_id` INT(11) NOT NULL,                         -- Source bank account master
  `tank_id` INT(11) DEFAULT NULL,                      -- Omitted / Not attached to payments
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_purchase_id` (`purchase_id`),
  KEY `idx_bank_id` (`bank_id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 3. Core Business Rules & Formulas

### 1. Total Cost Calculation
$$\text{Total Cost} = \text{Quantity} \times \text{Unit Price}$$

### 2. Payment Disbursement & Status Automation
- Every payment recorded against a purchase inserts a row into `tbl_purchase_payments` with `date`, `amount`, and `bank_id`.
- Payments are **not attached to any tank**.
- Status is automatically updated based on cumulative payments ($\sum \text{amount}$):
  - **`unpaid`**: Total Paid = 0.00.
  - **`in process`**: 0.00 < Total Paid < Total Cost.
  - **`paid`**: Total Paid $\ge$ Total Cost.

### 3. Payment Removal & Recalculation
- Removing a payment soft-deletes the record in `tbl_purchase_payments` (`deleted_at = NOW()`) and automatically recalculates the cumulative paid total and updates `payment_status`.

---

## 4. File Architecture

| File Path | Description |
|---|---|
| `purchases/purchases-list.php` | List of all purchases with total cost, total paid, status badges, and action buttons |
| `purchases/add-purchase.php` | Create purchase record with item, quantity, unit price, date, route, and invoice numbers |
| `purchases/edit-purchase.php` | Edit purchase details, record bank partial payments (without tank attachment), and view payment history |
| `include/deletepurchase.php` | Soft-delete handler for purchase orders |
| `include/deletepurchasepayment.php` | Soft-delete handler for individual purchase payments with status recalculation |
| `markdown/purchase.md` | Module specification and complete documentation (this file) |

---

## 5. UI, Theme & Icon Guidelines

- **Theme Compliance (`markdown/theme.md`)**:
  - Primary color: `#04204e` (`var(--primary-color)`).
  - Primary buttons: `var(--primary-gradient)` (`.btn-primary`).
  - Status badges: Paid (`badge-success`), In Process (`badge-warning`), Unpaid (`badge-danger`).
- **Icons (FontAwesome 5)**:
  - Add Purchase: `<i class="fas fa-plus"></i>`
  - Edit Purchase & Payments: `<i class="fas fa-edit"></i>`
  - Delete Purchase / Payment: `<i class="fas fa-trash-alt text-danger"></i>`
  - Record Payment: `<i class="fas fa-check-circle mr-1"></i>`
  - Back: `<i class="fas fa-arrow-left"></i>`

---

## 6. Payment Deletion & Form Resubmission Architecture (Troubleshooting)

### Root Cause Analysis:
1. **Form Resubmission via `location.reload()`**:
   - When a user submitted the payment form (`POST`), the page was rendered under an active POST request.
   - If the user immediately deleted the payment, the AJAX success callback triggered `location.reload()`.
   - The browser reloaded by re-submitting the previous POST payload (`add_payment`), unintentionally re-inserting the exact payment that was just deleted.
2. **Missing Post-Redirect-Get (PRG) Handling**:
   - The form submission handled the database insert and rendered the page in the same lifecycle without redirecting to a clean GET request.

### Solution Applied:
1. **Post-Redirect-Get (PRG) Pattern**:
   - Both `purchases/edit-purchase.php` and `lubricants/edit-purchase.php` now redirect after processing `add_payment` and `update_purchase` (`header("Location: edit-purchase.php?id=$id&msg=payment_added"); exit;`).
2. **Clean GET Redirection on Deletion**:
   - In `deletePayment()`, upon receiving `'deleted'` from the server, the browser performs a clean GET navigation (`window.location.href = 'edit-purchase.php?id=' + id + '&msg=payment_deleted'`).
3. **Robust Soft-Delete Queries**:
   - Updated queries to treat both `NULL` and `'0000-00-00 00:00:00'` as non-deleted records (`deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00'`).
