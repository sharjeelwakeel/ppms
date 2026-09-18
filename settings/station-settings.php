<?php
require_once __DIR__ . '/../include/session.php';
if (!userloggedin()) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/permissions.php';
require_once __DIR__ . '/../include/settings_helper.php';

// Check access permission for settings
if (!has_permission('settings', 'show') && !has_permission('settings', 'edit')) {
    echo '<div style="padding: 30px; text-align: center; font-family: sans-serif;">
            <h3 style="color: #c00;">Access Denied</h3>
            <p>You do not have permission to view or manage Station Settings.</p>
            <a href="../dashboard.php">&larr; Return to Dashboard</a>
          </div>';
    exit;
}

$message = '';
$settings = get_station_settings($connection);

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if (!has_permission('settings', 'edit')) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-2"></i>You do not have permission to modify settings.</div>';
    } else {
        $pump_name      = mysqli_real_escape_string($connection, trim($_POST['pump_name'] ?? ''));
        $tagline        = mysqli_real_escape_string($connection, trim($_POST['tagline'] ?? ''));
        $phone          = mysqli_real_escape_string($connection, trim($_POST['phone'] ?? ''));
        $email          = mysqli_real_escape_string($connection, trim($_POST['email'] ?? ''));
        $address        = mysqli_real_escape_string($connection, trim($_POST['address'] ?? ''));
        $city           = mysqli_real_escape_string($connection, trim($_POST['city'] ?? ''));
        $ntn_no         = mysqli_real_escape_string($connection, trim($_POST['ntn_no'] ?? ''));
        $license_no     = mysqli_real_escape_string($connection, trim($_POST['license_no'] ?? ''));
        $receipt_footer = mysqli_real_escape_string($connection, trim($_POST['receipt_footer'] ?? ''));

        if (empty($pump_name)) {
            $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-2"></i>Station / Pump Name cannot be empty.</div>';
        } else {
            $logo_path = $settings['logo_path'];

            // Handle logo removal
            if (isset($_POST['remove_logo']) && $_POST['remove_logo'] == '1') {
                if (!empty($logo_path) && file_exists(__DIR__ . '/../' . $logo_path)) {
                    @unlink(__DIR__ . '/../' . $logo_path);
                }
                $logo_path = '';
            }

            // Handle logo upload
            if (isset($_FILES['station_logo']) && $_FILES['station_logo']['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath   = $_FILES['station_logo']['tmp_name'];
                $fileName      = $_FILES['station_logo']['name'];
                $fileSize      = $_FILES['station_logo']['size'];
                $fileType      = $_FILES['station_logo']['type'];
                $fileNameCmps  = explode(".", $fileName);
                $fileExtension = strtolower(end($fileNameCmps));

                $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'svg'];

                if (in_array($fileExtension, $allowedExtensions)) {
                    if ($fileSize <= 3 * 1024 * 1024) { // 3MB limit
                        $uploadDir = __DIR__ . '/../uploads/logo/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }

                        // Remove old logo if exists
                        if (!empty($logo_path) && file_exists(__DIR__ . '/../' . $logo_path)) {
                            @unlink(__DIR__ . '/../' . $logo_path);
                        }

                        $newFileName = 'station_logo_' . time() . '.' . $fileExtension;
                        $destPath    = $uploadDir . $newFileName;

                        if (move_uploaded_file($fileTmpPath, $destPath)) {
                            $logo_path = 'uploads/logo/' . $newFileName;
                        } else {
                            $message = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle mr-2"></i>Failed to save uploaded logo, but other settings were saved.</div>';
                        }
                    } else {
                        $message = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle mr-2"></i>Logo file size exceeds 3MB limit.</div>';
                    }
                } else {
                    $message = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle mr-2"></i>Invalid logo format. Allowed: JPG, PNG, WEBP, SVG.</div>';
                }
            }

            $update_sql = "UPDATE `tbl_settings` SET 
                `pump_name`      = '$pump_name',
                `tagline`        = '$tagline',
                `phone`          = '$phone',
                `email`          = '$email',
                `address`        = '$address',
                `city`           = '$city',
                `ntn_no`         = '$ntn_no',
                `license_no`     = '$license_no',
                `receipt_footer` = '$receipt_footer',
                `logo_path`      = '$logo_path'
                WHERE `id` = 1";

            if (mysqli_query($connection, $update_sql)) {
                $message = '<div class="alert alert-success"><i class="fas fa-check-circle mr-2"></i>Station settings updated successfully! All PDF reports and vouchers will now reflect these details.</div>';
                // Reload settings
                $settings = [
                    'id'             => 1,
                    'pump_name'      => stripslashes($pump_name),
                    'tagline'        => stripslashes($tagline),
                    'phone'          => stripslashes($phone),
                    'email'          => stripslashes($email),
                    'address'        => stripslashes($address),
                    'city'           => stripslashes($city),
                    'ntn_no'         => stripslashes($ntn_no),
                    'license_no'     => stripslashes($license_no),
                    'receipt_footer' => stripslashes($receipt_footer),
                    'logo_path'      => $logo_path
                ];
            } else {
                $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-2"></i>Database Error: ' . mysqli_error($connection) . '</div>';
            }
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css">
    <link rel="stylesheet" href="../include/style.css?v=1.0.1">
    <title>PPMS - Station Settings</title>
    <style>
        body { background: #f4f6fb; font-family: 'Roboto', sans-serif; }
        
        .page-header {
            background: var(--gradient-header);
            color: #fff;
            padding: 18px 26px;
            border-radius: 10px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 18px rgba(4,32,78,0.18);
        }
        .page-header h4 { margin: 0; font-weight: 700; font-size: 1.3rem; letter-spacing: .5px; }

        .settings-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 14px rgba(0,0,0,0.06);
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid #e9ecef;
        }

        .settings-section-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--primary-color);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid #f0f3f8;
            display: flex;
            align-items: center;
        }

        .form-group label {
            font-size: 12.5px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 4px;
        }

        .form-control {
            border-radius: 6px;
            font-size: 13.5px;
            border-color: #d1d5db;
        }
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(4,32,78,0.15);
        }

        .logo-preview-box {
            background: #f8fafc;
            border: 2px dashed #cbd5e1;
            border-radius: 8px;
            padding: 16px;
            text-align: center;
            margin-bottom: 12px;
        }
        .logo-preview-img {
            max-height: 90px;
            max-width: 100%;
            object-fit: contain;
            border-radius: 4px;
        }
        .logo-empty-hint {
            color: #94a3b8;
            font-size: 12px;
            font-style: italic;
        }

        .live-preview-box {
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 16px 20px;
            box-shadow: inset 0 2px 6px rgba(0,0,0,0.03);
        }
    </style>
</head>
<body>
<?php include '../include/navbar.php'; ?>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h4><i class="fas fa-cogs mr-2 text-warning"></i> Station Settings &amp; Branding</h4>
            <small class="text-white-50">Configure your petrol pump name, contact numbers, address, and PDF document branding</small>
        </div>
        <div>
            <a href="../dashboard.php" class="btn btn-light btn-sm font-weight-bold">
                <i class="fas fa-arrow-left mr-1"></i> Dashboard
            </a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <?php echo $message; ?>
    <?php endif; ?>

    <form method="POST" action="" enctype="multipart/form-data">
        <div class="row">
            <!-- Left Column: Branding & Location Details -->
            <div class="col-lg-8">
                <!-- Station Identity Card -->
                <div class="settings-card">
                    <div class="settings-section-title">
                        <i class="fas fa-gas-pump mr-2 text-primary"></i> Station Identity &amp; Letterhead
                    </div>
                    <div class="row">
                        <div class="col-md-7">
                            <div class="form-group">
                                <label for="pump_name">Petrol Pump / Station Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control font-weight-bold" id="pump_name" name="pump_name" 
                                       value="<?php echo htmlspecialchars($settings['pump_name']); ?>" required 
                                       placeholder="e.g. Bismillah Petroleum Service">
                                <small class="form-text text-muted">Primary name displayed on all PDF invoices, statements, and receipts.</small>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="form-group">
                                <label for="tagline">Tagline / Subtitle</label>
                                <input type="text" class="form-control" id="tagline" name="tagline" 
                                       value="<?php echo htmlspecialchars($settings['tagline']); ?>" 
                                       placeholder="e.g. Authorized Petroleum & Lubricants Dealer">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Contact & Physical Location Card -->
                <div class="settings-card">
                    <div class="settings-section-title">
                        <i class="fas fa-map-marker-alt mr-2 text-danger"></i> Contact Details &amp; Physical Address
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="phone"><i class="fas fa-phone mr-1 text-muted"></i> Phone / Mobile Numbers</label>
                                <input type="text" class="form-control" id="phone" name="phone" 
                                       value="<?php echo htmlspecialchars($settings['phone']); ?>" 
                                       placeholder="e.g. +92 300 1234567, 042-35890000">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="email"><i class="fas fa-envelope mr-1 text-muted"></i> Official Email</label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($settings['email']); ?>" 
                                       placeholder="e.g. info@petrolpump.com">
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="form-group">
                                <label for="address"><i class="fas fa-road mr-1 text-muted"></i> Physical Station Address</label>
                                <input type="text" class="form-control" id="address" name="address" 
                                       value="<?php echo htmlspecialchars($settings['address']); ?>" 
                                       placeholder="e.g. Main GT Road, Near City Bypass">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="city"><i class="fas fa-city mr-1 text-muted"></i> City / Town</label>
                                <input type="text" class="form-control" id="city" name="city" 
                                       value="<?php echo htmlspecialchars($settings['city']); ?>" 
                                       placeholder="e.g. Lahore">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Legal / Registration & Document Policy Card -->
                <div class="settings-card">
                    <div class="settings-section-title">
                        <i class="fas fa-file-contract mr-2 text-info"></i> Tax Registration &amp; Receipt Footer Policy
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="ntn_no">NTN / STRN Registration No.</label>
                                <input type="text" class="form-control" id="ntn_no" name="ntn_no" 
                                       value="<?php echo htmlspecialchars($settings['ntn_no']); ?>" 
                                       placeholder="e.g. 1234567-8">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="license_no">Operating / Explosives License No.</label>
                                <input type="text" class="form-control" id="license_no" name="license_no" 
                                       value="<?php echo htmlspecialchars($settings['license_no']); ?>" 
                                       placeholder="e.g. OGRA-PET-8890">
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-group mb-0">
                                <label for="receipt_footer">Voucher / Receipt Footer Policy Note</label>
                                <textarea class="form-control" id="receipt_footer" name="receipt_footer" rows="2" 
                                          placeholder="e.g. Thank you for your business! Fuel once sold will not be returned."><?php echo htmlspecialchars($settings['receipt_footer']); ?></textarea>
                                <small class="form-text text-muted">Printed at the bottom of customer payment receipts and cash vouchers.</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Station Logo & Live Preview -->
            <div class="col-lg-4">
                <!-- Logo Management Card -->
                <div class="settings-card">
                    <div class="settings-section-title">
                        <i class="fas fa-image mr-2 text-warning"></i> Station Logo (Optional)
                    </div>
                    
                    <div class="logo-preview-box">
                        <?php if (!empty($settings['logo_path']) && file_exists(__DIR__ . '/../' . $settings['logo_path'])): ?>
                            <img src="../<?php echo htmlspecialchars($settings['logo_path']); ?>" alt="Station Logo" class="logo-preview-img mb-2">
                            <div class="custom-control custom-checkbox text-center mt-2">
                                <input type="checkbox" class="custom-control-input" id="remove_logo" name="remove_logo" value="1">
                                <label class="custom-control-label text-danger font-weight-bold" for="remove_logo" style="font-size: 12px; cursor: pointer;">
                                    <i class="fas fa-trash-alt mr-1"></i> Remove Current Logo
                                </label>
                            </div>
                        <?php else: ?>
                            <div class="py-3">
                                <i class="fas fa-image fa-2x text-muted mb-2"></i>
                                <div class="logo-empty-hint">No logo uploaded. If left empty, PDF headers will cleanly use text branding without empty gaps.</div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="station_logo">Upload New Logo</label>
                        <input type="file" class="form-control-file" id="station_logo" name="station_logo" accept="image/*">
                        <small class="form-text text-muted">Recommended: Transparent PNG or JPG, max 3MB.</small>
                    </div>
                </div>

                <!-- Save Action Card -->
                <div class="settings-card bg-light border">
                    <div class="font-weight-bold text-dark mb-3" style="font-size: 13px;">
                        <i class="fas fa-shield-alt mr-1 text-success"></i> Save &amp; Apply Changes
                    </div>
                    <p class="text-muted" style="font-size: 12px; line-height: 1.5;">
                        Saving updates will instantly reflect across all system areas including Customer Ledgers, Meter Reading PDFs, Payment Receipts, and Sales Manifests.
                    </p>
                    <button type="submit" name="save_settings" class="btn btn-primary btn-block font-weight-bold py-2 shadow-sm">
                        <i class="fas fa-save mr-2"></i> Save Station Settings
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.bundle.min.js"></script>
</body>
</html>
