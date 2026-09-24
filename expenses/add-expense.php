<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Access check
check_access('expenses', 'add');

$message = '';
$expense_date_val   = date('Y-m-d');
$expense_type_val   = '';
$amount_val         = '';
$payment_method_val = 'Cash';
$bank_id_val        = '';
$reference_no_val   = '';
$notes_val          = '';
$nozzle_id_val      = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $expense_date   = mysqli_real_escape_string($connection, trim($_POST['expense_date']));
    $expense_type_id= mysqli_real_escape_string($connection, trim($_POST['expense_type_id']));
    $nozzle_id      = !empty($_POST['nozzle_id']) ? intval($_POST['nozzle_id']) : 0;
    $amount         = mysqli_real_escape_string($connection, trim($_POST['amount']));
    $payment_method = mysqli_real_escape_string($connection, trim($_POST['payment_method']));
    $bank_id        = !empty($_POST['bank_id']) ? mysqli_real_escape_string($connection, trim($_POST['bank_id'])) : "NULL";
    $reference_no   = mysqli_real_escape_string($connection, trim($_POST['reference_no']));
    $notes          = mysqli_real_escape_string($connection, trim($_POST['notes']));
    $created_by     = intval($_SESSION['loggedInUser'] ?? 0);

    $expense_date_val   = $expense_date;
    $expense_type_val   = $expense_type_id;
    $nozzle_id_val      = ($nozzle_id > 0) ? $nozzle_id : '';
    $amount_val         = $amount;
    $payment_method_val = $payment_method;
    $bank_id_val        = ($bank_id !== "NULL") ? $bank_id : '';
    $reference_no_val   = $reference_no;
    $notes_val          = $notes;

    // Verify if chosen category is Nozzle Expense
    $is_nozzle_expense = false;
    if (!empty($expense_type_id)) {
        $type_chk = mysqli_query($connection, "SELECT is_system, name FROM tbl_expense_types WHERE id = '$expense_type_id' LIMIT 1");
        $type_row = mysqli_fetch_assoc($type_chk);
        if ($type_row && ($type_row['is_system'] == 1 || strtolower(trim($type_row['name'])) === 'nozzle expense')) {
            $is_nozzle_expense = true;
        }
    }

    if ($is_nozzle_expense && $nozzle_id <= 0) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-1"></i> Please select which dispensing nozzle this expense belongs to.</div>';
    } elseif (!empty($expense_date) && !empty($expense_type_id) && is_numeric($amount) && $amount > 0) {
        $bank_sql_val   = ($payment_method !== 'Cash' && !empty($bank_id) && $bank_id !== "NULL") ? "'$bank_id'" : "NULL";
        $nozzle_sql_val = ($is_nozzle_expense && $nozzle_id > 0) ? "'$nozzle_id'" : "NULL";
        
        $sql = "INSERT INTO tbl_expenses (expense_date, expense_type_id, nozzle_id, amount, payment_method, bank_id, reference_no, notes, created_by) 
                VALUES ('$expense_date', '$expense_type_id', $nozzle_sql_val, '$amount', '$payment_method', $bank_sql_val, '$reference_no', '$notes', '$created_by')";
        
        if (mysqli_query($connection, $sql)) {
            header('Location: expenses-list.php?msg=added');
            exit;
        } else {
            $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-1"></i> Error saving expense: ' . mysqli_error($connection) . '</div>';
        }
    } else {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-1"></i> Please fill in all required fields with valid values.</div>';
    }
}

// Fetch active expense types
$types_res = mysqli_query($connection, "SELECT id, name, is_system FROM tbl_expense_types WHERE status = 'Active' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY name ASC");

// Fetch active banks
$banks_res = mysqli_query($connection, "SELECT id, name, account_no FROM tbl_banks WHERE deleted_at IS NULL ORDER BY name ASC");

// Fetch active nozzles with attached tank & item name
$nozzles_res = mysqli_query($connection, "
    SELECT n.id, n.name, t.tank_name, i.name as item_name 
    FROM tbl_nozzles n 
    LEFT JOIN tbl_tanks t ON n.tank_id = t.id 
    LEFT JOIN tbl_items i ON n.item_id = i.id 
    WHERE n.status = 'Active' AND (n.deleted_at IS NULL OR n.deleted_at = '0000-00-00 00:00:00') 
    ORDER BY n.name ASC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css" />
    <link rel="stylesheet" href="../include/style.css?v=1.0.1" />
    <style>
        .btn-primary {
            background-color: #04204e !important;
            background: var(--primary-gradient) !important;
            border: none !important;
            color: #fff !important;
        }
        .btn-primary:hover { opacity: 0.9; }
        .card-header-navy {
            background-color: #04204e !important;
            color: #fff !important;
        }
    </style>
    <title>PPMS - Record New Expense</title>
</head>
<body>
    <?php include('../include/navbar.php');?>

    <main class="main">
        <div class="container pt-4 pb-4">
            <?php echo $message; ?>

            <div class="row mb-3 align-items-center">
                <div class="col-md-6">
                    <h4><i class="fas fa-plus-circle mr-2" style="color: var(--primary-color);"></i>Record New Expense</h4>
                </div>
                <div class="col-md-6 text-right">
                    <a href="expenses-list.php" class="btn btn-secondary"><i class="fas fa-arrow-left mr-1"></i> Back to Expenses List</a>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header card-header-navy font-weight-bold">
                    <i class="fas fa-receipt mr-2"></i>Expense Details Form
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label class="font-weight-bold">Expense Date <span class="text-danger">*</span></label>
                                <input type="date" name="expense_date" class="form-control" value="<?php echo htmlspecialchars($expense_date_val); ?>" required>
                            </div>
                            
                            <div class="col-md-4 form-group">
                                <label class="font-weight-bold">Expense Category / Type <span class="text-danger">*</span></label>
                                <select name="expense_type_id" id="expense_type_id" class="form-control" required onchange="toggleNozzleField()">
                                    <option value="">-- Select Category --</option>
                                    <?php if ($types_res && mysqli_num_rows($types_res) > 0): ?>
                                        <?php while ($t = mysqli_fetch_assoc($types_res)): 
                                            $isNozCat = ($t['is_system'] == 1 || strtolower(trim($t['name'])) === 'nozzle expense');
                                        ?>
                                            <option value="<?php echo $t['id']; ?>" data-is-nozzle="<?php echo $isNozCat ? '1' : '0'; ?>" <?php echo ($expense_type_val == $t['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($t['name']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="col-md-4 form-group">
                                <label class="font-weight-bold">Amount (PKR) <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <div class="input-group-prepend"><span class="input-group-text">Rs.</span></div>
                                    <input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="0.00" value="<?php echo htmlspecialchars($amount_val); ?>" required>
                                </div>
                            </div>
                        </div>

                        <!-- Dispensing Nozzle Selection (Visible only for Nozzle Expense) -->
                        <div class="row" id="nozzleGroup" style="display: none;">
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold text-primary"><i class="fas fa-gas-pump mr-1"></i>Dispensing Nozzle <span class="text-danger">*</span></label>
                                <select name="nozzle_id" id="nozzle_id" class="form-control">
                                    <option value="">-- Select Dispensing Nozzle --</option>
                                    <?php if ($nozzles_res && mysqli_num_rows($nozzles_res) > 0): ?>
                                        <?php mysqli_data_seek($nozzles_res, 0); ?>
                                        <?php while ($noz = mysqli_fetch_assoc($nozzles_res)): ?>
                                            <option value="<?php echo $noz['id']; ?>" <?php echo ($nozzle_id_val == $noz['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($noz['name'] . ' (' . ($noz['tank_name'] ?? 'N/A') . ' - ' . ($noz['item_name'] ?? 'N/A') . ')'); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                                <small class="text-muted">Specify the physical nozzle that incurred this repair, service, or calibration expense.</small>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label class="font-weight-bold">Payment Method <span class="text-danger">*</span></label>
                                <select name="payment_method" id="payment_method" class="form-control" required onchange="toggleBankField()">
                                    <option value="Cash" <?php echo ($payment_method_val === 'Cash') ? 'selected' : ''; ?>>Cash</option>
                                    <option value="Bank Transfer" <?php echo ($payment_method_val === 'Bank Transfer') ? 'selected' : ''; ?>>Bank Transfer</option>
                                    <option value="Card" <?php echo ($payment_method_val === 'Card') ? 'selected' : ''; ?>>Card</option>
                                    <option value="Cheque" <?php echo ($payment_method_val === 'Cheque') ? 'selected' : ''; ?>>Cheque</option>
                                </select>
                            </div>

                            <div class="col-md-4 form-group" id="bankGroup" style="display: <?php echo ($payment_method_val !== 'Cash') ? 'block' : 'none'; ?>;">
                                <label class="font-weight-bold">Bank Account</label>
                                <select name="bank_id" class="form-control">
                                    <option value="">-- Select Bank --</option>
                                    <?php if ($banks_res && mysqli_num_rows($banks_res) > 0): ?>
                                        <?php while ($b = mysqli_fetch_assoc($banks_res)): ?>
                                            <option value="<?php echo $b['id']; ?>" <?php echo ($bank_id_val == $b['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($b['name'] . ' (' . ($b['account_no'] ?? 'N/A') . ')'); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="col-md-4 form-group">
                                <label class="font-weight-bold">Reference / Voucher No.</label>
                                <input type="text" name="reference_no" class="form-control" placeholder="e.g. VCH-00123" value="<?php echo htmlspecialchars($reference_no_val); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="font-weight-bold">Notes / Description</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="Enter any additional details or description"><?php echo htmlspecialchars($notes_val); ?></textarea>
                        </div>

                        <hr>

                        <div class="text-right">
                            <a href="expenses-list.php" class="btn btn-secondary mr-2"><i class="fas fa-times mr-1"></i> Cancel</a>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Save Expense</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
    <script>
        function toggleBankField() {
            var method = $('#payment_method').val();
            if (method === 'Cash') {
                $('#bankGroup').hide();
            } else {
                $('#bankGroup').show();
            }
        }

        function toggleNozzleField() {
            var selectedOption = $('#expense_type_id').find(':selected');
            var isNozzle = selectedOption.attr('data-is-nozzle');
            var optionText = $.trim(selectedOption.text()).toLowerCase();

            if (isNozzle == '1' || optionText.indexOf('nozzle expense') !== -1) {
                $('#nozzleGroup').show();
                $('#nozzle_id').prop('required', true);
            } else {
                $('#nozzleGroup').hide();
                $('#nozzle_id').prop('required', false).val('');
            }
        }

        $(document).ready(function() {
            toggleNozzleField();
            toggleBankField();
            $('#expense_type_id').on('change', toggleNozzleField);
            $('#payment_method').on('change', toggleBankField);
        });
    </script>
</body>
</html>
