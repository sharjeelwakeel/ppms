<?php
require 'session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require 'config.php';
if (isset($_POST['id'])) {
    $id = intval($_POST['id']);
    
    // Check if the product has associated purchases or sales to prevent integrity issues
    $p_check = mysqli_query($connection, "SELECT COUNT(*) FROM tbl_lubricant_purchases WHERE product_id='$id'");
    $p_count = mysqli_fetch_row($p_check)[0];
    
    $s_check = mysqli_query($connection, "SELECT COUNT(*) FROM tbl_lubricant_sales WHERE product_id='$id'");
    $s_count = mysqli_fetch_row($s_check)[0];
    
    if ($p_count == 0 && $s_count == 0) {
        $sql = "DELETE FROM tbl_lubricant_products WHERE id='$id'";
        mysqli_query($connection, $sql);
        echo "success";
    } else {
        echo "error_in_use";
    }
}
?>
