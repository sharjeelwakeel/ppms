<?php
require 'config.php';

$id = $_POST['id'];

$sql = "DELETE FROM tbl_tanks WHERE id='$id'";

if (mysqli_query($connection, $sql)) {
    echo 'Tank deleted.';
} else {
    echo "Error: " . $sql . "<br>" . mysqli_error($connection);
}

mysqli_close($connection);
?>
