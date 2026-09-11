<?php
// Detect directory level to adjust path prefix
$prefix = '';
if (!file_exists('include/navbar.php')) {
    $prefix = '../';
}
require_once __DIR__ . '/permissions.php';
?>
<style>
/* Main Navbar - Guaranteed Inline Layout on All Screens (>= 768px) */
@media (min-width: 768px) {
    .navbar.main-navbar,
    nav.navbar.main-navbar,
    .navbar.navbar-expand-md.main-navbar,
    .navbar.navbar-expand-lg.main-navbar {
        display: flex !important;
        flex-flow: row nowrap !important;
        flex-wrap: nowrap !important;
        align-items: center !important;
        justify-content: flex-start !important;
        padding: 0.28rem 0.4rem !important;
        width: 100% !important;
    }
    .navbar.main-navbar .navbar-brand {
        display: inline-flex !important;
        align-items: center !important;
        flex-shrink: 0 !important;
        white-space: nowrap !important;
        font-size: 1.05rem !important;
        margin-right: 0.45rem !important;
        padding: 0 !important;
    }
    .navbar.main-navbar .navbar-toggler {
        display: none !important;
    }
    .navbar.main-navbar .navbar-collapse,
    nav.navbar.main-navbar .navbar-collapse,
    .navbar.navbar-expand-md.main-navbar .navbar-collapse,
    .navbar.navbar-expand-lg.main-navbar .navbar-collapse {
        display: flex !important;
        flex-flow: row nowrap !important;
        flex-wrap: nowrap !important;
        flex-basis: auto !important;
        flex-grow: 1 !important;
        align-items: center !important;
        justify-content: space-between !important;
        width: auto !important;
        margin: 0 !important;
        padding: 0 !important;
        background: transparent !important;
        border: none !important;
        box-shadow: none !important;
        overflow: visible !important;
        min-width: 0 !important;
    }
    .navbar.main-navbar .navbar-nav {
        display: flex !important;
        flex-flow: row nowrap !important;
        flex-wrap: nowrap !important;
        align-items: center !important;
        margin-right: auto !important;
        margin-bottom: 0 !important;
        padding: 0 !important;
        flex-shrink: 1 !important;
        min-width: 0 !important;
    }
    .navbar.main-navbar .navbar-nav .nav-item {
        flex-shrink: 0 !important;
        width: auto !important;
        margin: 0 1px !important;
        padding: 0 !important;
    }
    .navbar.main-navbar .navbar-nav .nav-link {
        color: rgba(255, 255, 255, 0.88) !important;
        font-size: 0.77rem !important;
        font-weight: 500 !important;
        padding: 0.25rem 0.35rem !important;
        white-space: nowrap !important;
        display: inline-flex !important;
        align-items: center !important;
        border-radius: 4px !important;
        transition: all 0.15s ease !important;
    }
    .navbar.main-navbar .navbar-nav .nav-link i {
        font-size: 0.74rem !important;
        margin-right: 0.18rem !important;
    }
    .navbar.main-navbar .navbar-nav .nav-link:hover,
    .navbar.main-navbar .navbar-nav .nav-item.active .nav-link,
    .navbar.main-navbar .navbar-nav .nav-item.show .nav-link {
        color: #ffffff !important;
        background-color: rgba(255, 255, 255, 0.15) !important;
    }
    .navbar.main-navbar .nav-action-wrapper {
        margin-left: auto !important;
        margin-top: 0 !important;
        padding: 0 !important;
        border: none !important;
        width: auto !important;
        flex-shrink: 0 !important;
        display: flex !important;
        align-items: center !important;
    }
    .navbar.main-navbar .btn-logout {
        display: inline-flex !important;
        align-items: center !important;
        width: auto !important;
        white-space: nowrap !important;
        padding: 0.22rem 0.5rem !important;
        font-size: 0.76rem !important;
        border-radius: 4px !important;
        border: 1px solid rgba(255, 255, 255, 0.4) !important;
        color: #ffffff !important;
        background: transparent !important;
    }
    .navbar.main-navbar .btn-logout:hover {
        background-color: #ffffff !important;
        color: var(--primary-color) !important;
    }
}

/* Specific Ultra-Compact Scaling on Laptops & Tablets (768px - 1160px) */
@media (min-width: 768px) and (max-width: 1160px) {
    .navbar.main-navbar,
    nav.navbar.main-navbar {
        padding: 0.22rem 0.25rem !important;
    }
    .navbar.main-navbar .navbar-brand {
        font-size: 0.98rem !important;
        margin-right: 0.3rem !important;
    }
    .navbar.main-navbar .navbar-nav .nav-link {
        font-size: 0.72rem !important;
        padding: 0.2rem 0.25rem !important;
    }
    .navbar.main-navbar .navbar-nav .nav-link i {
        font-size: 0.68rem !important;
        margin-right: 0.12rem !important;
    }
    .navbar.main-navbar .btn-logout {
        padding: 0.18rem 0.4rem !important;
        font-size: 0.72rem !important;
    }
}

/* Mobile Phone Responsive Drawer (< 768px) */
@media (max-width: 767.98px) {
    .navbar.main-navbar {
        display: flex !important;
        flex-wrap: wrap !important;
        justify-content: space-between !important;
        align-items: center !important;
        padding: 0.4rem 0.8rem !important;
    }
    .navbar.main-navbar .navbar-brand {
        font-size: 1.1rem !important;
        white-space: nowrap !important;
    }
    .navbar.main-navbar .navbar-toggler {
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        border: 1.5px solid rgba(255, 255, 255, 0.4) !important;
        padding: 5px 9px !important;
        border-radius: 6px !important;
        background: rgba(255, 255, 255, 0.08) !important;
        cursor: pointer !important;
        margin-left: auto !important;
    }
    .navbar.main-navbar .navbar-collapse {
        flex-basis: 100% !important;
        width: 100% !important;
        background: #031a40 !important;
        border-radius: 8px !important;
        margin-top: 8px !important;
        padding: 10px 12px !important;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.45) !important;
        border: 1px solid rgba(255, 255, 255, 0.12) !important;
        max-height: calc(100vh - 70px) !important;
        overflow-y: auto !important;
    }
    .navbar.main-navbar .navbar-collapse:not(.show) {
        display: none !important;
    }
    .navbar.main-navbar .navbar-collapse.show {
        display: block !important;
    }
    .navbar.main-navbar .navbar-nav {
        flex-direction: column !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    .navbar.main-navbar .navbar-nav .nav-item {
        margin-bottom: 2px !important;
        width: 100% !important;
    }
    .navbar.main-navbar .navbar-nav .nav-link {
        color: rgba(255, 255, 255, 0.9) !important;
        font-size: 0.88rem !important;
        padding: 8px 10px !important;
        border-radius: 6px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        white-space: normal !important;
    }
    .navbar.main-navbar .navbar-nav .dropdown-menu {
        position: static !important;
        background: rgba(255, 255, 255, 0.07) !important;
        border: 1px solid rgba(255, 255, 255, 0.12) !important;
        border-radius: 8px !important;
        padding: 6px !important;
        margin: 4px 0 8px 8px !important;
        box-shadow: inset 0 2px 6px rgba(0, 0, 0, 0.2) !important;
        float: none !important;
        width: calc(100% - 8px) !important;
    }
    .navbar.main-navbar .navbar-nav .dropdown-item {
        color: rgba(255, 255, 255, 0.88) !important;
        font-size: 0.84rem !important;
        padding: 7px 10px !important;
        border-radius: 6px !important;
        display: flex !important;
        align-items: center !important;
    }
    .navbar.main-navbar .nav-action-wrapper {
        border-top: 1px solid rgba(255, 255, 255, 0.15) !important;
        padding-top: 10px !important;
        margin-top: 8px !important;
        width: 100% !important;
    }
    .navbar.main-navbar .btn-logout {
        width: 100% !important;
        padding: 8px 12px !important;
        font-size: 0.88rem !important;
        text-align: center !important;
        display: block !important;
        color: #ffffff !important;
    }
}
</style>
<nav class="navbar navbar-expand-md bg-dark navbar-dark px-2 shadow-sm main-navbar">
    <a class="navbar-brand font-weight-bold d-flex align-items-center" href="<?php echo $prefix; ?>dashboard.php">
        <i class="fas fa-gas-pump mr-2 text-warning"></i> PPMS
    </a>
    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNavDropdown" aria-controls="navbarNavDropdown" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNavDropdown">
        <ul class="navbar-nav mr-auto">
            <li class="nav-item">
                <a class="nav-link" href="<?php echo $prefix; ?>dashboard.php"><i class="fas fa-home mr-1"></i> Dashboard</a>
            </li>

            <!-- Master Menu -->
            <?php 
            $showMaster = has_permission('shifts', 'show') || has_permission('items', 'show') || 
                          has_permission('tanks', 'show') || has_permission('roles', 'show') || 
                          has_permission('users', 'show') || has_permission('nozzles', 'show') || 
                          has_permission('staff', 'show') || has_permission('card_machines', 'show') || 
                          has_permission('banks', 'show') || has_permission('customers', 'show');
            if ($showMaster): 
            ?>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownMenuLink" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <i class="fas fa-database mr-1"></i> Master
                </a>
                <div class="dropdown-menu" aria-labelledby="navbarDropdownMenuLink">
                    <?php if (has_permission('customers', 'show')): ?>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>customers/customers-list.php"><i class="fas fa-user-friends mr-1 text-muted"></i> Customers</a>
                    <?php endif; ?>

                    <?php if (has_permission('shifts', 'show')): ?>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>shifts/shifts-list.php"><i class="fas fa-clock mr-1 text-muted"></i> Shifts</a>
                    <?php endif; ?>

                    <?php if (has_permission('items', 'show')): ?>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>items/items-list.php"><i class="fas fa-cubes mr-1 text-muted"></i> Items</a>
                    <?php endif; ?>

                    <?php if (has_permission('tanks', 'show')): ?>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>tanks/tanks-list.php"><i class="fas fa-oil-can mr-1 text-muted"></i> Tanks</a>
                    <?php endif; ?>

                    <?php if (has_permission('roles', 'show')): ?>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>roles/roles-list.php"><i class="fas fa-user-shield mr-1 text-warning"></i> User Roles &amp; Permissions</a>
                    <?php endif; ?>

                    <?php if (has_permission('users', 'show')): ?>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>users/users-list.php"><i class="fas fa-users-cog mr-1 text-primary"></i> System Users</a>
                    <?php endif; ?>

                    <?php if (has_permission('nozzles', 'show')): ?>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>nozzles/nozzles-list.php"><i class="fas fa-gas-pump mr-1 text-muted"></i> Nozzles</a>
                    <?php endif; ?>

                    <?php if (has_permission('staff', 'show')): ?>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>staff/staff-list.php"><i class="fas fa-user-tie mr-1 text-muted"></i> Staff</a>
                    <?php endif; ?>

                    <?php if (has_permission('card_machines', 'show')): ?>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>card-machines/card-machines-list.php"><i class="fas fa-credit-card mr-1 text-muted"></i> Card Machines</a>
                    <?php endif; ?>

                    <?php if (has_permission('banks', 'show')): ?>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>banks/banks-list.php"><i class="fas fa-university mr-1 text-muted"></i> Banks</a>
                    <?php endif; ?>

                    <?php if (has_permission('tanks', 'show')): ?>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>dip-lookup/dip-lookup-list.php"><i class="fas fa-ruler-vertical mr-1 text-muted"></i> Dip Lookup</a>
                    <?php endif; ?>
                </div>
            </li>
            <?php endif; ?>

            <!-- Expenses Menu -->
            <?php if (has_permission('expenses', 'show')): ?>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownMenuLinkExpenses" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <i class="fas fa-receipt mr-1"></i> Expenses
                </a>
                <div class="dropdown-menu" aria-labelledby="navbarDropdownMenuLinkExpenses">
                    <a class="dropdown-item" href="<?php echo $prefix; ?>expenses/expenses-list.php"><i class="fas fa-list-alt mr-1"></i> View Expenses</a>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>expenses/expense-types-list.php"><i class="fas fa-tags mr-1"></i> Expense Categories</a>
                </div>
            </li>
            <?php endif; ?>

            <!-- Purchases Menu -->
            <?php if (has_permission('purchases', 'show')): ?>
            <li class="nav-item">
                <a class="nav-link" href="<?php echo $prefix; ?>purchases/purchases-list.php"><i class="fas fa-shopping-cart mr-1"></i> Purchases</a>
            </li>
            <?php endif; ?>

            <!-- Stock & Lubricants Menu -->
            <?php if (has_permission('items', 'show')): ?>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownMenuLinkLubricants" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <i class="fas fa-oil-can mr-1"></i> Stock &amp; Lubricants
                </a>
                <div class="dropdown-menu" aria-labelledby="navbarDropdownMenuLinkLubricants">
                    <a class="dropdown-item" href="<?php echo $prefix; ?>lubricants/products-list.php"><i class="fas fa-boxes mr-1 text-muted"></i> Products</a>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>lubricants/purchases-list.php"><i class="fas fa-arrow-down mr-1 text-success"></i> Purchases (Inflow)</a>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>lubricants/sales-list.php"><i class="fas fa-arrow-up mr-1 text-danger"></i> Sales (Outflow)</a>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>lubricants/stock-report.php"><i class="fas fa-file-invoice mr-1 text-info"></i> Stock Report</a>
                </div>
            </li>
            <?php endif; ?>

            <!-- Transactions Menu -->
            <?php 
            $showTransactions = has_permission('meter_readings', 'show') || has_permission('credit_sales', 'show') || has_permission('card_sales', 'show');
            if ($showTransactions): 
            ?>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownMenuLink3" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <i class="fas fa-calculator mr-1"></i> Transactions
                </a>
                <div class="dropdown-menu" aria-labelledby="navbarDropdownMenuLink3">
                    <?php if (has_permission('meter_readings', 'show')): ?>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>meter-readings/meter-reading-list.php"><i class="fas fa-tachometer-alt mr-1"></i> Meter Reading</a>
                    <?php endif; ?>
                    <?php if (has_permission('credit_sales', 'show')): ?>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>credit-sales/credit-sales-list.php"><i class="fas fa-file-invoice-dollar mr-1"></i> Credit Sale Reading</a>
                    <?php endif; ?>
                    <?php if (has_permission('card_sales', 'show')): ?>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>card-sales/card-sales-list.php"><i class="fas fa-credit-card mr-1"></i> Card Sale Reading</a>
                    <?php endif; ?>
                </div>
            </li>
            <?php endif; ?>

            <!-- Accounts Menu -->
            <?php if (has_permission('accounts', 'show') || has_permission('credit_sales', 'show')): ?>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownMenuLinkAccounts" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <i class="fas fa-wallet mr-1"></i> Accounts
                </a>
                <div class="dropdown-menu" aria-labelledby="navbarDropdownMenuLinkAccounts">
                    <a class="dropdown-item" href="<?php echo $prefix; ?>accounts/credit-sale-receivables.php"><i class="fas fa-hand-holding-usd mr-1 text-success"></i> Accounts Receivable (Credit Sale)</a>
                </div>
            </li>
            <?php endif; ?>

            <!-- HR & Payroll -->
            <?php if (has_permission('staff', 'show')): ?>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownMenuLinkHR" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <i class="fas fa-users mr-1"></i> HR &amp; Payroll
                </a>
                <div class="dropdown-menu" aria-labelledby="navbarDropdownMenuLinkHR">
                    <a class="dropdown-item" href="<?php echo $prefix; ?>staff/staff-roles-list.php"><i class="fas fa-id-badge mr-1 text-info"></i> Staff Designations</a>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>staff/attendance-list.php"><i class="fas fa-calendar-check mr-1"></i> Staff Attendance</a>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>staff/leave-setup.php"><i class="fas fa-calendar-minus mr-1"></i> Leave Setup</a>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>staff/salary-calculator.php"><i class="fas fa-money-check-alt mr-1"></i> Salary Calculator</a>
                </div>
            </li>
            <?php endif; ?>

            <!-- Reports Menu -->
            <?php if (has_permission('reports', 'show') || has_permission('customers', 'show') || has_permission('meter_readings', 'show')): ?>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownMenuLinkReports" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <i class="fas fa-chart-bar mr-1"></i> Reports
                </a>
                <div class="dropdown-menu" aria-labelledby="navbarDropdownMenuLinkReports">
                    <a class="dropdown-item" href="<?php echo $prefix; ?>reports/customer-report.php"><i class="fas fa-user-tag mr-1 text-primary"></i> Customer Report</a>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>lubricants/stock-report.php"><i class="fas fa-boxes mr-1 text-info"></i> Stock Report</a>
                </div>
            </li>
            <?php endif; ?>
        </ul>
        <div class="nav-action-wrapper">
            <a href="<?php echo $prefix; ?>include/logout.php" class="btn btn-outline-light btn-sm btn-logout font-weight-bold">
                <i class="fas fa-sign-out-alt mr-1"></i> Logout
            </a>
        </div>
    </div>
</nav>