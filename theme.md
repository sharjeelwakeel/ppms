# PPMS System Design Theme Guidelines (`theme.md`)

This document defines the official, mandatory design system, color palette, typography, and UI component standards for the **PPMS** application. All pages, modules, and components developed in this repository **MUST** adhere strictly to these theme standards.

---

## 1. Centralized CSS Theme Variables

All primary color definitions are centralized in `include/style.css`.

```css
:root {
    --primary-color: #04204e;       /* Deep Navy Blue - Core Brand Color */
    --primary-hover: #07347a;       /* Lighter Navy - Hover State */
    --primary-light: #e6f0fa;       /* Very Light Blue Tint - Backgrounds & Highlights */
    --primary-gradient: linear-gradient(135deg, var(--primary-color) 0%, #07347a 100%);
    --gradient-header: linear-gradient(135deg, var(--primary-color) 0%, #094096 100%);
}
```

---

## 2. Color Palette Reference

| Color Role            | Hex Code   | CSS Variable               | Usage Description                                    |
|-----------------------|------------|----------------------------|------------------------------------------------------|
| **Primary Navy**      | `#04204e`  | `var(--primary-color)`     | Main Brand, Navbar background, Table Headers, Headings |
| **Primary Hover**     | `#07347a`  | `var(--primary-hover)`     | Button & Link Hover States                           |
| **Primary Light**     | `#e6f0fa`  | `var(--primary-light)`     | Card Highlights, Badges, Light Backgrounds           |
| **Primary Gradient**  | *Gradient* | `var(--primary-gradient)`  | Primary Buttons (`.btn-primary`), Hero Callouts       |
| **Header Gradient**   | *Gradient* | `var(--gradient-header)`   | Modal Headers, Header Banners                        |
| **Text Dark**         | `#212529`  | N/A                        | Primary Body & Label Text                            |
| **Text Light**        | `#ffffff`  | N/A                        | Navbar Text, Primary Button Text, Table Header Text  |

---

## 3. Component Styling Rules

### A. Navigation Bar (`include/navbar.php`)
- **Class**: `navbar navbar-expand-lg bg-dark navbar-dark`
- **Background**: Overridden by `.bg-dark` to `#04204e`.
- **Dropdown Items**: Active/Hover background uses `var(--primary-color)` with white text.

### B. Primary Buttons (`.btn-primary`)
- **Background**: `var(--primary-gradient) !important`
- **Fallback**: `#04204e !important`
- **Border**: `none !important`
- **Text Color**: `#ffffff !important`
- **Shadow**: `0 4px 12px rgba(4, 32, 78, 0.2)`
- **Hover State**: `background: var(--primary-hover) !important; opacity: 0.9;`

### C. DataTables & Tables
- **Header (`table thead th`, `.table thead th`)**:
  - `background-color: #04204e !important;`
  - `color: #ffffff !important;`
- **Table Links**: Use `color: var(--primary-color); font-weight: bold;`

### D. Typography & Fonts
- **Font Family**: `'Roboto', sans-serif` (Google Font: weights 300, 400, 500, 700, 900)
- **Stylesheets Required on Every Page**:
  ```html
  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css">
  <link rel="stylesheet" href="../include/style.css?v=1.0.1">
  ```

---

## 4. Enforcement & Mandatory Guidelines for Developers

1. **Always Include `include/style.css`**: Every HTML/PHP file **MUST** include `<link rel="stylesheet" href=".../include/style.css?v=1.0.1">` AFTER the Bootstrap CSS link.
2. **Never Use Generic Blue (`#007bff`)**: Do not use standard Bootstrap default blue (`#007bff`) for branding or primary buttons. Always use `var(--primary-color)` or `var(--primary-gradient)`.
3. **Consistent Icons**: Use FontAwesome 5 (`fas fa-*`) icons for all actions (e.g. `<i class="fas fa-plus mr-1"></i> Add New`, `<i class="fas fa-edit"></i> Edit`, `<i class="fas fa-trash-alt"></i> Delete`, `<i class="fas fa-save mr-1"></i> Save`).
4. **Theme Consistency Across All Modules**: All new modules (Dip Lookup, Banks, Tanks, Staff, Items, etc.) must follow these exact color and component definitions without deviation.
