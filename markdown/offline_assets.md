# 100% Offline / Local Asset Architecture (`offline_assets.md`)

## 1. Overview
PPMS is engineered to operate 100% offline in isolated on-premise petrol station intranet environments with **zero external internet connectivity or CDN dependencies**.

All vendor stylesheets, scripts, icon sets, and webfonts are hosted directly in the local repository under `include/` and served locally.

---

## 2. Directory Structure

```
d:/xampp/htdocs/ppms/include/
├── css/
│   ├── all.min.css              # FontAwesome 5.11.2 Core Stylesheet
│   ├── bootstrap.min.css        # Bootstrap 4.3.1 Core Stylesheet
│   ├── jquery.dataTables.min.css# DataTables 1.10.20 CSS
│   ├── roboto.css               # Google Fonts Roboto (300, 400, 500, 700, 900) CSS
│   ├── sweetalert2.min.css      # SweetAlert2 v11 Core Stylesheet
│   └── style.css                # PPMS Master Design System Theme Tokens (#04204e)
├── js/
│   ├── bootstrap.bundle.min.js  # Bootstrap 4.3.1 with Popper embedded
│   ├── bootstrap.min.js         # Bootstrap 4.3.1 Core JS
│   ├── chart.min.js             # Chart.js v3.9.1 (Executive Dashboard Analytics)
│   ├── jquery.dataTables.min.js # DataTables 1.10.20 Core JS
│   ├── jquery.fancybox.min.js   # Fancybox 3.5.7 Image Lightbox
│   ├── jquery.min.js            # jQuery 3.5.1 Production Bundle
│   ├── popper.min.js            # Popper.js 1.14.7 Tooltip Engine
│   └── sweetalert2.all.min.js   # SweetAlert2 v11 All-in-one JS + Styles
└── webfonts/
    ├── fa-brands-400.ttf
    ├── fa-brands-400.woff
    ├── fa-brands-400.woff2
    ├── fa-regular-400.ttf
    ├── fa-regular-400.woff
    ├── fa-regular-400.woff2
    ├── fa-solid-900.ttf
    ├── fa-solid-900.woff
    ├── fa-solid-900.woff2
    └── roboto/
        ├── roboto-v1.woff2 ... roboto-v45.woff2 # WOFF2 binaries for Roboto font faces
```

---

## 3. Relative Path Resolution Standard

Pages in PPMS follow strict relative path resolution based on directory depth:

### Root Pages (`depth 0`)
*Examples*: `dashboard.php`, `index.php`, `unauthorized.php`
- Styles: `include/css/<asset>.css`
- Scripts: `include/js/<asset>.js`
- Design Tokens: `include/style.css`

### Module Subdirectory Pages (`depth 1`)
*Examples*: `customers/customers-list.php`, `meter-readings/add-meter-reading.php`, `staff/attendance-list.php`
- Styles: `../include/css/<asset>.css`
- Scripts: `../include/js/<asset>.js`
- Design Tokens: `../include/style.css`

---

## 4. Verification & Testing

The asset localization is verified continuously via automated test suite:
- **Test File**: `tests/core/test_offline_local_assets.php`
- **Runner**: Part of master test suite (`tests/run_all_tests.php`)
- **Key Assertions**:
  1. All 17 required CSS, JS, and WOFF2 asset files exist and are non-empty (>1KB).
  2. FontAwesome `all.min.css` resolves fonts via `../webfonts/`.
  3. Roboto `roboto.css` resolves fonts via `../webfonts/roboto/`.
  4. 0 external CDN links (`https?://`) exist across all 204+ application PHP files.
