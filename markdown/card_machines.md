# Card Machines Module Complete Documentation (`markdown/card_machines.md`)

## 1. Overview
The **Card Machines** module manages Point of Sale (POS) card processing terminals (`tbl_card_machines`) provided by commercial banks (e.g., Meezan Bank, HBL, MCB). It tracks machine names, associated contact personnel, contact numbers, and bank service commission charges (`charges_percentage`).

---

## 2. Database Schema (`tbl_card_machines`)

```sql
CREATE TABLE IF NOT EXISTS `tbl_card_machines` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `charges_percentage` DECIMAL(8,4) NOT NULL DEFAULT 0.0000,   -- 4 decimal places precision (e.g. 0.3456%)
  `contact_person_name` VARCHAR(128) NOT NULL,
  `contact_person_number` VARCHAR(32) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 3. Service Charges Specification (4 Decimal Places)

### 1. High-Precision Fee Tracking
- Bank POS merchant discount rates (MDR) and card swipe service fees often require micro-percentage precision (e.g., `0.3456%`, `1.2500%`, `0.0023%`).
- Both Create (`card-machines/add-card-machine.php`) and Edit (`card-machines/edit-card-machine.php`) forms utilize:
  ```html
  <input type="number" step="0.0001" min="0" max="100" name="charges_percentage" class="form-control" placeholder="e.g. 0.3456" value="0.0000" required>
  ```
- **Step Increment**: `0.0001` allowing input of exact fractional percentages with 4 decimal digits.

### 2. Service Charge Calculation Formula
When recording card transactions in daily meter readings (`meter-readings/add-meter-reading.php`):
$$\text{Service Charges} = \text{Card Amount} \times \left(\frac{\text{charges\_percentage}}{100}\right)$$
$$\text{Net Bank Receivable} = \text{Card Amount} - \text{Service Charges}$$

*Example*:
- Card Sale Amount: Rs. 100,000.00
- Card Machine Fee: `0.3456%`
- Service Charges Deducted: $\text{Rs. } 100,000 \times \frac{0.3456}{100} = \text{Rs. } 345.60$
- Net Bank Amount: $\text{Rs. } 100,000 - 345.60 = \text{Rs. } 99,654.40$

---

## 4. File Architecture

| File Path | Description |
|---|---|
| `card-machines/card-machines-list.php` | List of all card machines showing name, 4-decimal charges %, contact person, and status |
| `card-machines/add-card-machine.php` | Form to register a new card machine with 4-decimal `charges_percentage` input |
| `card-machines/edit-card-machine.php` | Form to edit machine name, 4-decimal fee percentage, and contact details |
| `include/deletecardmachine.php` | Backend AJAX handler for soft-deleting card machines (`deleted_at = NOW()`) |
| `meter-readings/add-meter-reading.php` | Integrates card machines to calculate per-swipe bank service charges and net amounts |
| `markdown/card_machines.md` | Module specification and complete documentation (this file) |

---

## 5. UI Theme & Icon Standards

- **Theme Compliance (`markdown/theme.md`)**:
  - Primary color: `#04204e` (`var(--primary-color)`).
  - Primary buttons: `var(--primary-gradient)` (`.btn-primary`).
  - Table Header (`#cardMachinesTable thead th`): `#04204e`.
- **Icons (FontAwesome 5)**:
  - Card Machines Header: `<i class="fas fa-credit-card mr-2 text-primary"></i>`
  - Add Card Machine: `<i class="fas fa-plus mr-1"></i> Add Card Machine`
  - Save Machine: `<i class="fas fa-save mr-1"></i> Save Machine`
  - Cancel: `<i class="fas fa-times mr-1"></i> Cancel`
  - Delete Machine: `<i class="fas fa-trash-alt text-danger"></i>`
