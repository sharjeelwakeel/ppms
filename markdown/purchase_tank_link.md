# Purchase Tank Linking Specification (`markdown/purchase_tank_link.md`)

## 1. Overview
When purchasing fuel or products (`tbl_purchases`), the total purchased quantity must be allocated into physical storage tanks (`tbl_tanks`). The **Linked To** feature allows users to distribute a purchase order's total quantity across one or multiple tanks and tracks the remaining unallocated balance.

---

## 2. Database Schema (`tbl_purchase_tank_links`)

```sql
CREATE TABLE IF NOT EXISTS `tbl_purchase_tank_links` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `purchase_id` INT(11) NOT NULL,
  `tank_id` INT(11) NOT NULL,
  `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- Quantity stored in Liters
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_purchase_id` (`purchase_id`),
  KEY `idx_tank_id` (`tank_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 3. Business Logic & Calculation Formulas

### 1. Total Stored Quantity Across Tanks
$$\text{Total Stored Quantity} = \sum \text{quantity}_{\text{link}}$$

### 2. Remaining Unallocated Quantity
$$\text{Remaining Unallocated Quantity} = \text{Total Purchased Quantity} - \text{Total Stored Quantity}$$

### 3. Allocation Validation Rules
- Entered `quantity` MUST be greater than 0.
- Entered `quantity` MUST NOT exceed the `Remaining Unallocated Quantity`:
  $$\text{quantity} \le \text{Remaining Unallocated Quantity}$$
- If a user attempts to allocate more quantity than remaining, a validation warning is displayed and submission is blocked.

---

## 4. User Interface Workflow

1. **Purchases List (`purchases/purchases-list.php`)**:
   - Each purchase row includes a **Linked To** button (`.btn-info` with `<i class="fas fa-link"></i>`).
   - Displays a badge indicating allocation status: e.g. `Fully Linked`, `Partially Linked (X Ltr Left)`, or `Not Linked`.

2. **Linked To Management (`purchases/link-purchase-tank.php`)**:
   - **Header Card**: Displays Purchase ID, Item Name, Total Purchased Quantity, Total Stored Quantity, and Remaining Unallocated Quantity.
   - **Form Card**:
     - Tank Selector (`tank_id` dropdown).
     - Stored Quantity input (`quantity` in Liters).
     - Save Button ("Store to Tank").
   - **Allocation List Table**:
     - Lists all active tank allocations (`#`, `Tank Name`, `Quantity Stored (Ltr)`, `Date Linked`, `Delete Action`).
     - Removing an allocation row restores the quantity back to the remaining unallocated balance.

---

## 5. UI Theme & Design Guidelines

- **Theme Compliance (`markdown/theme.md`)**:
  - Primary color: `#04204e` (`var(--primary-color)`).
  - Linked To button: `linear-gradient(135deg, #17a2b8 0%, #117a8b 100%)`.
  - Header: `var(--gradient-header)`.
- **Icons (FontAwesome 5)**:
  - Linked To: `<i class="fas fa-link mr-1"></i>`
  - Tank: `<i class="fas fa-oil-can mr-1"></i>`
  - Delete Link: `<i class="fas fa-trash-alt text-danger"></i>`
