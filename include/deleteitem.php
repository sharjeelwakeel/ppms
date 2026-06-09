<?php
require 'config.php';

$id = $_POST['id'];

$sql = "DELETE FROM tbl_items WHERE id='$id'";

if (mysqli_query($connection, $sql)) {
    echo 'Item deleted.';
}

else {
    echo "Error: " . $sql . "<br>" . mysqli_error($connection);
}

mysqli_close($connection);
?>
