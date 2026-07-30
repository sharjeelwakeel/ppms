# PPMS System Icon Guidelines (`icon.md`)

This document defines the standard FontAwesome 5 (`fas fa-*`) icon set for the **PPMS** application. To ensure visual consistency and overall similarity across all pages and modules, developers **MUST** strictly follow the standardized icon mapping defined below.

---

## 1. Action & CRUD Operations Icons (General Rules)

Every CRUD operation and button action must use the exact icon specified in this table:

| Action / Purpose       | FontAwesome Class            | Example HTML Snippet                                                      | Usage Context                                          |
|------------------------|------------------------------|---------------------------------------------------------------------------|--------------------------------------------------------|
| **Add New Record**     | `fas fa-plus`                | `<a href="..." class="btn btn-primary"><i class="fas fa-plus mr-1"></i> Add New</a>` | Top action buttons on list pages                       |
| **Edit Record**        | `fas fa-edit`                | `<a href="..." class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i> Edit</a>` | Row actions in tables, edit forms                      |
| **Delete Record**      | `fas fa-trash-alt`           | `<button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash-alt"></i> Delete</button>` | Row deletion actions, confirm delete buttons           |
| **Save / Submit**      | `fas fa-save`                | `<button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Save</button>` | Submit buttons on Create/Edit forms                   |
| **Cancel / Close**     | `fas fa-times`               | `<a href="..." class="btn btn-secondary"><i class="fas fa-times mr-1"></i> Cancel</a>` | Form cancel buttons, modal close icons                |
| **View / Details**     | `fas fa-eye`                 | `<a href="..." class="btn btn-sm btn-info"><i class="fas fa-eye mr-1"></i> View</a>` | Inspect detail page                                    |
| **Search / Filter**    | `fas fa-search`              | `<i class="fas fa-search"></i>`                                            | Filter bars, search inputs                            |
| **Calculate / Compute**| `fas fa-calculator`          | `<button class="btn btn-primary"><i class="fas fa-calculator mr-1"></i> Calculate</button>` | Salary calculator, Dip instant calculator             |
| **Success / Check**    | `fas fa-check-circle`        | `<i class="fas fa-check-circle mr-1"></i>`                                | Success alert banners, toast notifications             |
| **Warning / Alert**    | `fas fa-exclamation-triangle`| `<i class="fas fa-exclamation-triangle text-warning mr-2"></i>`           | Duplicate warning modals, critical alerts              |
| **Info / Help**        | `fas fa-info-circle`         | `<i class="fas fa-info-circle mr-1"></i>`                                 | Informational callout boxes, tooltips                  |
| **Logout**             | `fas fa-sign-out-alt`        | `<i class="fas fa-sign-out-alt mr-1"></i>`                                | User session logout button                             |

---

## 2. Specific Module & Navigation Icons

Each master module and navigation item must use its designated icon:

| Module / Area               | FontAwesome Class            | Navigation Label / Header Context                      |
|-----------------------------|------------------------------|--------------------------------------------------------|
| **Dashboard**               | `fas fa-home`                | `<i class="fas fa-home mr-1"></i> Dashboard`           |
| **Master Data**             | `fas fa-database`            | `<i class="fas fa-database mr-1"></i> Master`          |
| **Dip Lookup**              | `fas fa-ruler-vertical`      | `<i class="fas fa-ruler-vertical mr-1"></i> Dip Lookup`|
| **Tanks**                   | `fas fa-gas-pump`            | `<i class="fas fa-gas-pump mr-1"></i> Tanks`           |
| **Nozzles**                 | `fas fa-gas-pump`            | `<i class="fas fa-gas-pump mr-1"></i> Nozzles`         |
| **Shifts**                  | `fas fa-clock`               | `<i class="fas fa-clock mr-1"></i> Shifts`             |
| **Items**                   | `fas fa-boxes`               | `<i class="fas fa-boxes mr-1"></i> Items`              |
| **Roles**                   | `fas fa-user-shield`         | `<i class="fas fa-user-shield mr-1"></i> Roles`        |
| **Staff / HR**              | `fas fa-users`               | `<i class="fas fa-users mr-1"></i> HR & Payroll`       |
| **Staff Attendance**        | `fas fa-calendar-check`      | `<i class="fas fa-calendar-check mr-1"></i> Attendance`|
| **Leave Setup**             | `fas fa-calendar-minus`      | `<i class="fas fa-calendar-minus mr-1"></i> Leave`     |
| **Salary Calculator**       | `fas fa-money-check-alt`     | `<i class="fas fa-money-check-alt mr-1"></i> Salary`   |
| **Card Machines**           | `fas fa-credit-card`         | `<i class="fas fa-credit-card mr-1"></i> Card Machines`|
| **Banks**                   | `fas fa-university`          | `<i class="fas fa-university mr-1"></i> Banks`         |
| **Purchases**               | `fas fa-shopping-cart`       | `<i class="fas fa-shopping-cart mr-1"></i> Purchases`  |
| **Stock & Lubricants**      | `fas fa-oil-can`             | `<i class="fas fa-oil-can mr-1"></i> Stock & Lubricants`|
| **Meter Readings**          | `fas fa-tachometer-alt`      | `<i class="fas fa-tachometer-alt mr-1"></i> Meter`    |

---

## 3. Standardization Rules for Developers

1. **FontAwesome 5 Solid**: Always use `fas fa-*` prefix (FontAwesome 5 Solid). Do not mix FontAwesome 4 or old `fa fa-*` syntax.
2. **Icon Spacing**: Add `mr-1` or `mr-2` margin right class when an icon is placed next to text inside buttons, headings, or menu links (e.g. `<i class="fas fa-plus mr-1"></i> Add`).
3. **Action Consistency**: Never substitute `fas fa-trash-alt` with `fa-remove` or `fa-close`. Always use `fas fa-trash-alt` for deletion.
4. **Button + Icon Alignment**: All primary action buttons **MUST** include their corresponding icon for high visual polish.
