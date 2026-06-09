<?php require 'config.php';
$id = $_POST['id'];
$sql = "DELETE FROM tbl_roles WHERE id='$id'";
if (mysqli_query($connection, $sql)) { echo 'Role deleted.'; }
else { echo "Error: " . mysqli_error($connection); }
mysqli_close($connection);
?>
