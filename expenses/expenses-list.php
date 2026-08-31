<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Access check
check_access('expenses', 'show');

$canAdd    = has_permission('expenses', 'add');
$canEdit   = has_permission('expenses', 'edit');
$canDelete = has_permission('expenses', 'delete');

$alert_message = '';

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'added') {
        $alert_message = '<div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle mr-1"></i> Expense recorded successfully!<button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button></div>';
    } elseif ($_GET['msg'] === 'updated') {
        $alert_message = '<div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle mr-1"></i> Expense updated successfully!<button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button></div>';
    } elseif ($_GET['msg'] === 'deleted') {
        $alert_message = '<div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle mr-1"></i> Expense deleted successfully!<button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button></div>';
    } elseif ($_GET['msg'] === 'error') {
        $alert_message = '<div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-exclamation-triangle mr-1"></i> An error occurred processing your request.<button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button></div>';
    }
}

// Fetch active categories for filter dropdown
$types_res = mysqli_query($connection, "SELECT id, name FROM tbl_expense_types WHERE status = 'Active' AND deleted_at IS NULL ORDER BY name ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.20/css/jquery.dataTables.min.css" />
    <link rel="stylesheet" href="../include/style.css?v=1.0.2" />
    <style>
        .btn-primary {
            background-color: #04204e !important;
            background: var(--primary-gradient) !important;
            border: none !important;
            color: #fff !important;
            font-weight: 500;
        }
        .btn-primary:hover { opacity: 0.9; }
        .btn-outline-primary {
            color: #04204e !important;
            border: 1.5px solid #04204e !important;
            background-color: transparent !important;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        .btn-outline-primary:hover {
            background-color: #04204e !important;
            background: var(--primary-gradient) !important;
            color: #ffffff !important;
            box-shadow: 0 4px 12px rgba(4, 32, 78, 0.2);
        }
        #expenseTable thead th {
            background-color: #04204e !important;
            background: var(--primary-color) !important;
            color: #fff !important;
        }
        .kpi-card {
            border-left: 4px solid var(--primary-color);
            background: #f8f9fa;
        }
    </style>
    <title>PPMS - Expenses List</title>
</head>
<body>
    <?php include('../include/navbar.php');?>

    <main class="main">
        <div class="container pt-4 pb-4">
            <?php echo $alert_message; ?>

            <div class="row mb-3 align-items-center">
                <div class="col-md-6">
                    <h4><i class="fas fa-receipt mr-2" style="color: var(--primary-color);"></i>Expenses Management</h4>
                </div>
                <div class="col-md-6 text-right">
                    <a href="expense-types-list.php" class="btn btn-outline-primary mr-2 font-weight-bold shadow-sm"><i class="fas fa-tags mr-1"></i> Expense Categories</a>
                    <?php if ($canAdd): ?>
                    <a href="add-expense.php" class="btn btn-primary font-weight-bold shadow-sm"><i class="fas fa-plus-circle mr-1"></i> Record New Expense</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- KPI Summary Cards -->
            <div class="row mb-4">
                <div class="col-md-4 mb-2 mb-md-0">
                    <div class="card shadow-sm kpi-card">
                        <div class="card-body py-3">
                            <div class="text-muted small font-weight-bold text-uppercase">Total Expenses</div>
                            <div class="h4 font-weight-bold m-0 text-danger" id="kpiTotal">Rs. 0.00</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-2 mb-md-0">
                    <div class="card shadow-sm kpi-card" style="border-left-color: #28a745;">
                        <div class="card-body py-3">
                            <div class="text-muted small font-weight-bold text-uppercase">Cash Expenses</div>
                            <div class="h4 font-weight-bold m-0 text-success" id="kpiCash">Rs. 0.00</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm kpi-card" style="border-left-color: #17a2b8;">
                        <div class="card-body py-3">
                            <div class="text-muted small font-weight-bold text-uppercase">Bank / Online Expenses</div>
                            <div class="h4 font-weight-bold m-0 text-info" id="kpiBank">Rs. 0.00</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters Bar -->
            <div class="card shadow-sm mb-4">
                <div class="card-body py-3 bg-light">
                    <form id="filterForm" class="form-row align-items-center">
                        <div class="col-md-3 my-1">
                            <label class="sr-only">From Date</label>
                            <div class="input-group">
                                <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-calendar"></i></span></div>
                                <input type="date" id="filterFromDate" class="form-control" placeholder="From Date">
                            </div>
                        </div>
                        <div class="col-md-3 my-1">
                            <label class="sr-only">To Date</label>
                            <div class="input-group">
                                <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-calendar"></i></span></div>
                                <input type="date" id="filterToDate" class="form-control" placeholder="To Date">
                            </div>
                        </div>
                        <div class="col-md-3 my-1">
                            <select id="filterType" class="form-control">
                                <option value="">All Categories</option>
                                <?php if ($types_res && mysqli_num_rows($types_res) > 0): ?>
                                    <?php while ($t = mysqli_fetch_assoc($types_res)): ?>
                                        <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-3 my-1 d-flex">
                            <button type="button" id="btnFilter" class="btn btn-primary btn-block mr-2"><i class="fas fa-filter mr-1"></i> Filter</button>
                            <button type="button" id="btnReset" class="btn btn-secondary"><i class="fas fa-redo"></i></button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Data Table -->
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="expenseTable" class="table table-bordered table-hover w-100">
                            <thead>
                                <tr>
                                    <th width="5%">#</th>
                                    <th width="12%">Date</th>
                                    <th width="18%">Category</th>
                                    <th width="15%">Amount</th>
                                    <th width="18%">Payment Method</th>
                                    <th width="12%">Ref / Voucher</th>
                                    <th>Notes</th>
                                    <th width="10%">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.20/js/jquery.dataTables.min.js"></script>
    <script>
        var table;

        $(document).ready(function() {
            table = $('#expenseTable').DataTable({
                "processing": true,
                "serverSide": true,
                "ajax": {
                    "url": "expenses-ajax.php",
                    "type": "POST",
                    "data": function(d) {
                        d.from_date = $('#filterFromDate').val();
                        d.to_date   = $('#filterToDate').val();
                        d.type_id   = $('#filterType').val();
                    },
                    "dataSrc": function(json) {
                        if (json.totalSum !== undefined) {
                            $('#kpiTotal').text('Rs. ' + json.totalSum);
                            $('#kpiCash').text('Rs. ' + json.cashSum);
                            $('#kpiBank').text('Rs. ' + json.bankSum);
                        }
                        return json.data;
                    }
                },
                "pageLength": 25,
                "order": [[ 1, "desc" ]]
            });

            $('#btnFilter').on('click', function() {
                table.ajax.reload();
            });

            $('#btnReset').on('click', function() {
                $('#filterFromDate').val('');
                $('#filterToDate').val('');
                $('#filterType').val('');
                table.ajax.reload();
            });
        });

        function deleteExpense(id) {
            if (confirm("Are you sure you want to delete this expense record?")) {
                window.location.href = "../include/deleteexpense.php?id=" + id;
            }
        }
    </script>
</body>
</html>
