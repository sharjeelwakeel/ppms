<?php
require 'config.php';

$id = $_POST['id'];

$sql = "DELETE FROM tbl_shifts WHERE id='$id'";

if (mysqli_query($connection, $sql)) {
    echo 'Shift deleted.';
}

else {
    echo "Error: " . $sql . "<br>" . mysqli_error($connection);
}

mysqli_close($connection);
?>
