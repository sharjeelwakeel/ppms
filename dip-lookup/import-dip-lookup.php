<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Check permissions
check_access('tanks', 'add');

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'seed_from_resources') {
        // Execute seeding directly from resources/tbl_dip_lookup_seed.sql
        $seedFile = __DIR__ . '/../resources/tbl_dip_lookup_seed.sql';
        if (file_exists($seedFile)) {
            $sqlContent = file_get_contents($seedFile);
            $statements = array_filter(array_map('trim', explode(";\n", $sqlContent)));
            $executed = 0;
            $errors = 0;
            foreach ($statements as $stmt) {
                if (!empty($stmt) && strpos($stmt, '--') !== 0) {
                    if (mysqli_query($connection, $stmt)) {
                        $executed++;
                    } else {
                        $errors++;
                    }
                }
            }
            if ($errors === 0) {
                header('Location: dip-lookup-list.php?msg=imported');
                exit;
            } else {
                $message = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle mr-1"></i> Imported with ' . $errors . ' statement errors.</div>';
            }
        } else {
            $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-1"></i> Seed SQL file not found in resources directory.</div>';
        }
    } elseif (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
        $fileName = $_FILES['import_file']['name'];
        $tmpPath  = $_FILES['import_file']['tmp_name'];
        $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($ext === 'sql') {
            $sqlContent = file_get_contents($tmpPath);
            $statements = array_filter(array_map('trim', explode(";\n", $sqlContent)));
            $executed = 0;
            foreach ($statements as $stmt) {
                if (!empty($stmt) && strpos($stmt, '--') !== 0) {
                    if (mysqli_query($connection, $stmt)) {
                        $executed++;
                    }
                }
            }
            header('Location: dip-lookup-list.php?msg=imported');
            exit;
        } elseif ($ext === 'csv') {
            $handle = fopen($tmpPath, 'r');
            $row = 0;
            $imported = 0;
            while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                $row++;
                if ($row === 1 && !is_numeric($data[0])) continue; // skip header
                if (count($data) >= 3) {
                    $cap   = mysqli_real_escape_string($connection, trim($data[0]));
                    $mm    = mysqli_real_escape_string($connection, trim($data[1]));
                    $litre = mysqli_real_escape_string($connection, trim($data[2]));

                    if (is_numeric($mm) && is_numeric($litre)) {
                        $sql = "INSERT INTO tbl_dip_lookup (tank_capacity, dip_mm, dip_litre) VALUES ('$cap', '$mm', '$litre') ON DUPLICATE KEY UPDATE dip_litre = '$litre', deleted_at = NULL";
                        mysqli_query($connection, $sql);
                        $imported++;
                    }
                }
            }
            fclose($handle);
            header('Location: dip-lookup-list.php?msg=imported');
            exit;
        } else {
            $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-1"></i> Unsupported file format. Please upload a .sql or .csv file.</div>';
        }
    }
}
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
    <title>PPMS - Import Dip Lookup Master</title>
</head>
<body>
    <?php include('../include/navbar.php');?>

    <main class="main">
        <div class="container pt-4 pb-4">
            <?php echo $message; ?>
            <div class="row mb-3 align-items-center">
                <div class="col-md-6">
                    <h4><i class="fas fa-file-import mr-2" style="color: var(--primary-color);"></i>Import Dip Lookup Chart</h4>
                </div>
                <div class="col-md-6 text-right">
                    <a href="dip-lookup-list.php" class="btn btn-secondary"><i class="fas fa-arrow-left mr-1"></i> Back to Master List</a>
                </div>
            </div>

            <div class="row">
                <!-- Option 1: Fast One-Click Seed from resources/ -->
                <div class="col-md-6 mb-4">
                    <div class="card shadow-sm h-100">
                        <div class="card-header card-header-navy font-weight-bold">
                            <i class="fas fa-database mr-2"></i>Quick Import from Project Seed File
                        </div>
                        <div class="card-body d-flex flex-column justify-content-between">
                            <div>
                                <p class="card-text text-muted">
                                    Import the complete pre-parsed <strong>4,904 entries</strong> directly from <code>resources/tbl_dip_lookup_seed.sql</code>.
                                </p>
                                <ul class="small text-muted pl-3">
                                    <li><strong>23,500 Ltrs Tank Chart:</strong> 2,350 dip entries</li>
                                    <li><strong>50,000 Ltrs Tank Chart:</strong> 2,554 dip entries</li>
                                </ul>
                            </div>
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="seed_from_resources">
                                <button type="submit" class="btn btn-primary btn-block">
                                    <i class="fas fa-bolt mr-1"></i> Run Seed SQL Import Now
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Option 2: Upload custom SQL/CSV file -->
                <div class="col-md-6 mb-4">
                    <div class="card shadow-sm h-100">
                        <div class="card-header card-header-navy font-weight-bold">
                            <i class="fas fa-upload mr-2"></i>Upload Dip Chart File (.sql / .csv)
                        </div>
                        <div class="card-body d-flex flex-column justify-content-between">
                            <form method="POST" action="" enctype="multipart/form-data">
                                <div class="form-group">
                                    <label class="font-weight-bold">Select File to Upload:</label>
                                    <div class="custom-file">
                                        <input type="file" name="import_file" class="custom-file-input" id="importFile" accept=".sql,.csv" required>
                                        <label class="custom-file-label" for="importFile">Choose file...</label>
                                    </div>
                                    <small class="form-text text-muted">Supports SQL seed scripts (.sql) or CSV files with <code>capacity, dip_mm, dip_litre</code> columns.</small>
                                </div>
                                <button type="submit" class="btn btn-success btn-block mt-3">
                                    <i class="fas fa-file-upload mr-1"></i> Upload &amp; Process Import
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
    <script>
        // Update label on file select
        $('.custom-file-input').on('change', function() {
            var fileName = $(this).val().split('\\').pop();
            $(this).next('.custom-file-label').addClass("selected").html(fileName);
        });
    </script>
</body>
</html>
