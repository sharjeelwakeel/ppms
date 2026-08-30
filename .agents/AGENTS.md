# Project Rules & Design System Guidelines

## Theme & Color Consistency (`theme.md`)

- **Primary Color**: `#04204e` (`var(--primary-color)`)
- **Primary Hover**: `#07347a` (`var(--primary-hover)`)
- **Primary Gradient**: `linear-gradient(135deg, #04204e 0%, #07347a 100%)` (`var(--primary-gradient)`)
- **Primary Font**: `'Roboto', sans-serif`

### Mandatory UI Rules:
1. Every HTML/PHP page **MUST** include `include/style.css` after Bootstrap CSS.
2. All primary buttons (`.btn-primary`), navigation items, modal headers, and table headers (`table thead th`) **MUST** strictly use the deep navy color scheme defined in [`theme.md`](theme.md).
3. Do not use generic default Bootstrap blues (`#007bff`).
4. All icons across all pages and modules **MUST** strictly use the FontAwesome 5 icon set mapped in [`icon.md`](icon.md) (e.g. `fas fa-plus`, `fas fa-edit`, `fas fa-trash-alt`, `fas fa-save`, `fas fa-times`).
5. All layouts, navbars, and data tables **MUST** be fully responsive in tablet and laptop mode (`<= 1240px`) following the standards in [`theme.md`](theme.md) and `include/style.css`.
