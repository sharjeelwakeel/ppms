<?php
require 'session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require 'config.php';
require 'permissions.php';

if (!has_permission('items', 'delete')) {
    echo 'Error: Unauthorized operation.';
    exit;
}

if (isset($_POST['id']) && !empty($_POST['id'])) {
    $id = intval($_POST['id']);

    // Guard: Check if any active products use this subcategory
    $chk_prod = mysqli_query($connection, "SELECT COUNT(*) AS prod_cnt FROM tbl_lubricant_products WHERE subcategory_id = '$id' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
    $prod_row = mysqli_fetch_assoc($chk_prod);
    if ($prod_row && intval($prod_row['prod_cnt']) > 0) {
        echo 'Cannot delete subcategory: it is currently assigned to ' . $prod_row['prod_cnt'] . ' active product(s). Please reassign those products first.';
        exit;
    }

    $sql = "UPDATE tbl_product_subcategories SET deleted_at = NOW() WHERE id = '$id'";
    if (mysqli_query($connection, $sql)) {
        echo 'Subcategory deleted successfully.';
    } else {
        echo 'Error: ' . mysqli_error($connection);
    }
}
mysqli_close($connection);
?>
