<?php
// Detect directory level to adjust path prefix
$prefix = '';
if (!file_exists('include/navbar.php')) {
    $prefix = '../';
}
require_once __DIR__ . '/permissions.php';
?>
<nav class="navbar navbar-expand-lg bg-dark navbar-dark px-lg-4 shadow-sm">
    <a class="navbar-brand font-weight-bold" href="<?php echo $prefix; ?>dashboard.php">PPMS</a>
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
                          has_permission('banks', 'show');
            if ($showMaster): 
            ?>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownMenuLink" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <i class="fas fa-database mr-1"></i> Master
                </a>
                <div class="dropdown-menu" aria-labelledby="navbarDropdownMenuLink">
                    <?php if (has_permission('shifts', 'show')): ?>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>shifts/shifts-list.php">Shifts</a>
                    <?php endif; ?>

                    <?php if (has_permission('items', 'show')): ?>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>items/items-list.php">Items</a>
                    <?php endif; ?>

                    <?php if (has_permission('tanks', 'show')): ?>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>tanks/tanks-list.php">Tanks</a>
                    <?php endif; ?>

                    <?php if (has_permission('roles', 'show')): ?>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>roles/roles-list.php"><i class="fas fa-user-shield mr-1 text-warning"></i> User Roles &amp; Permissions</a>
                    <?php endif; ?>

                    <?php if (has_permission('users', 'show')): ?>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>users/users-list.php"><i class="fas fa-users-cog mr-1 text-primary"></i> System Users</a>
                    <?php endif; ?>

                    <?php if (has_permission('nozzles', 'show')): ?>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>nozzles/nozzles-list.php">Nozzles</a>
                    <?php endif; ?>

                    <?php if (has_permission('staff', 'show')): ?>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>staff/staff-list.php">Staff</a>
                    <?php endif; ?>

                    <?php if (has_permission('card_machines', 'show')): ?>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>card-machines/card-machines-list.php">Card Machines</a>
                    <?php endif; ?>

                    <?php if (has_permission('banks', 'show')): ?>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>banks/banks-list.php">Banks</a>
                    <?php endif; ?>

                    <?php if (has_permission('tanks', 'show')): ?>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>dip-lookup/dip-lookup-list.php">Dip Lookup</a>
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
                    <a class="dropdown-item" href="<?php echo $prefix; ?>expenses/add-expense.php"><i class="fas fa-plus-circle mr-1"></i> Record New Expense</a>
                    <div class="dropdown-divider"></div>
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
                    <a class="dropdown-item" href="<?php echo $prefix; ?>lubricants/products-list.php">Products</a>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>lubricants/purchases-list.php">Purchases (Inflow)</a>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>lubricants/sales-list.php">Sales (Outflow)</a>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>lubricants/stock-report.php">Stock Report</a>
                </div>
            </li>
            <?php endif; ?>

            <!-- Transactions / Meter Readings -->
            <?php if (has_permission('meter_readings', 'show')): ?>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownMenuLink3" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <i class="fas fa-calculator mr-1"></i> Transactions
                </a>
                <div class="dropdown-menu" aria-labelledby="navbarDropdownMenuLink3">
                    <a class="dropdown-item" href="<?php echo $prefix; ?>meter-readings/meter-reading-list.php"><i class="fas fa-tachometer-alt mr-1"></i> Meter Reading</a>
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
        </ul>
        <a href="<?php echo $prefix; ?>include/logout.php" class="btn btn-outline-primary mt-2 mt-lg-0 ml-lg-3">Logout</a>
    </div>
</nav>