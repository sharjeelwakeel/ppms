# Project Rules & Guidelines

## 1. Theme & Color Consistency (`theme.md`)

- **Primary Color**: `#04204e` (`var(--primary-color)`)
- **Primary Hover**: `#07347a` (`var(--primary-hover)`)
- **Primary Gradient**: `linear-gradient(135deg, #04204e 0%, #07347a 100%)` (`var(--primary-gradient)`)
- **Primary Font**: `'Roboto', sans-serif`

### Mandatory UI Rules:
1. Every HTML/PHP page **MUST** include `include/style.css` after Bootstrap CSS.
2. All primary buttons (`.btn-primary`), navigation items, modal headers, and table headers (`table thead th`) **MUST** strictly use the deep navy color scheme defined in [`theme.md`](../theme.md).
3. Do not use generic default Bootstrap blues (`#007bff`).
4. All icons across all pages and modules **MUST** strictly use the FontAwesome 5 icon set mapped in [`icon.md`](../icon.md) (e.g. `fas fa-plus`, `fas fa-edit`, `fas fa-trash-alt`, `fas fa-save`, `fas fa-times`).
5. All layouts, navbars, and data tables **MUST** be fully responsive in tablet and laptop mode (`<= 1240px`) following the standards in [`theme.md`](../theme.md) and `include/style.css`.

---

## 2. Mandatory First Step: Read Architecture & Documentation First

> **CRITICAL RULE FOR ALL AGENTS**: 
> Before writing or modifying any code, creating plans, or performing database operations, you **MUST read [`architecture.md`](../architecture.md)** to understand the system design, senior coding patterns, and workflow constraints.

### Reference Documents:
- **Architecture & Senior Standards**: [`architecture.md`](../architecture.md) (Coding standards, security, RBAC, soft deletes, transactions, nozzle meter synchronization, multi-row spreadsheet pattern).
- **Design System**: [`theme.md`](../theme.md) (Navy palette, CSS tokens, responsive layout rules).
- **Icon Dictionary**: [`icon.md`](../icon.md) (Standardized FontAwesome 5 icons).
- **Module Specifications**: [`markdown/<module>.md`](../markdown/) (Living specs for each feature and business domain).

---

## 3. Core Agent Directives (Summary Checklist)

Do not duplicate the detailed technical specifications from [`architecture.md`](../architecture.md). Always follow these governing principles:

1. **Read Before Writing**: Always consult [`architecture.md`](../architecture.md) and the relevant module document in `markdown/` before proposing or making changes.
2. **Low Cognitive Load, DRY & KISS Code**: Follow Keep It Simple, Straightforward (no over-engineering or unnecessary cleverness), the flat-over-nested rule (guard clauses), keep controllers under 350 lines, and extract shared logic into `include/` helpers.
3. **Security & RBAC Gate**: Every controller and AJAX endpoint must enforce session authentication and `check_access($module_slug, $action)`.
4. **Data & Schema Integrity**: Use explicit numeric casting (`intval`, `floatval`), atomic transactions for multi-table writes, and soft deletes (`deleted_at = NOW()`) for all transactional records.
5. **Nozzle Meter Synchronization**: Ensure physical nozzle counters in `tbl_nozzles` and day-to-day snapshots in `tbl_daily_nozzle_readings` are kept synchronized on add, edit, and delete.
6. **Documentation Synchronization**: Whenever modifying schemas, business rules, or form workflows, update the corresponding file in `markdown/<module>.md` in parallel.
