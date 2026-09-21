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

    // Guard 1: Check if any active subcategories exist for this category
    $chk_sub = mysqli_query($connection, "SELECT COUNT(*) AS sub_cnt FROM tbl_product_subcategories WHERE category_id = '$id' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
    $sub_row = mysqli_fetch_assoc($chk_sub);
    if ($sub_row && intval($sub_row['sub_cnt']) > 0) {
        echo 'Cannot delete category: it has ' . $sub_row['sub_cnt'] . ' active subcategory(ies). Please delete or reassign them first.';
        exit;
    }

    // Guard 2: Check if any active products use this category
    $chk_prod = mysqli_query($connection, "SELECT COUNT(*) AS prod_cnt FROM tbl_lubricant_products WHERE category_id = '$id' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
    $prod_row = mysqli_fetch_assoc($chk_prod);
    if ($prod_row && intval($prod_row['prod_cnt']) > 0) {
        echo 'Cannot delete category: it is currently assigned to ' . $prod_row['prod_cnt'] . ' active product(s). Please reassign those products first.';
        exit;
    }

    $sql = "UPDATE tbl_product_categories SET deleted_at = NOW() WHERE id = '$id'";
    if (mysqli_query($connection, $sql)) {
        echo 'Category deleted successfully.';
    } else {
        echo 'Error: ' . mysqli_error($connection);
    }
}
mysqli_close($connection);
?>
