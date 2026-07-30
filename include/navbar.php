<?php
// Detect directory level to adjust path prefix
$prefix = '';
if (!file_exists('include/navbar.php')) {
    $prefix = '../';
}
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
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownMenuLink" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <i class="fas fa-database mr-1"></i> Master
                </a>
                <div class="dropdown-menu" aria-labelledby="navbarDropdownMenuLink">
                    <a class="dropdown-item" href="<?php echo $prefix; ?>shifts/shifts-list.php">Shifts</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>items/items-list.php">Items</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>tanks/tanks-list.php">Tanks</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>roles/roles-list.php">Roles</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>nozzles/nozzles-list.php">Nozzles</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>staff/staff-list.php">Staff</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>card-machines/card-machines-list.php">Card Machines</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>banks/banks-list.php">Banks</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>dip-lookup/dip-lookup-list.php">Dip Lookup</a>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?php echo $prefix; ?>purchases/purchases-list.php"><i class="fas fa-shopping-cart mr-1"></i> Purchases</a>
            </li>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownMenuLinkLubricants" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <i class="fas fa-oil-can mr-1"></i> Stock & Lubricants
                </a>
                <div class="dropdown-menu" aria-labelledby="navbarDropdownMenuLinkLubricants">
                    <a class="dropdown-item" href="<?php echo $prefix; ?>lubricants/products-list.php">Products</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>lubricants/purchases-list.php">Purchases (Inflow)</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>lubricants/sales-list.php">Sales (Outflow)</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>lubricants/stock-report.php">Stock Report</a>
                </div>
            </li>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownMenuLink3" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <i class="fas fa-calculator mr-1"></i> Transactions
                </a>
                <div class="dropdown-menu" aria-labelledby="navbarDropdownMenuLink3">
                    <a class="dropdown-item" href="<?php echo $prefix; ?>meter-readings/meter-reading-list.php"><i class="fas fa-tachometer-alt mr-1"></i> Meter Reading</a>
                </div>
            </li>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownMenuLinkHR" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <i class="fas fa-users mr-1"></i> HR & Payroll
                </a>
                <div class="dropdown-menu" aria-labelledby="navbarDropdownMenuLinkHR">
                    <a class="dropdown-item" href="<?php echo $prefix; ?>staff/attendance-list.php"><i class="fas fa-calendar-check mr-1"></i> Staff Attendance</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>staff/leave-setup.php"><i class="fas fa-calendar-minus mr-1"></i> Leave Setup</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="<?php echo $prefix; ?>staff/salary-calculator.php"><i class="fas fa-money-check-alt mr-1"></i> Salary Calculator</a>
                </div>
            </li>
        </ul>
        <a href="<?php echo $prefix; ?>include/logout.php" class="btn btn-outline-primary mt-2 mt-lg-0 ml-lg-3">Logout</a>
    </div>
</nav>