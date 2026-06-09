<?php
require 'config.php';
if (isset($_POST['id']) && !empty($_POST['id'])) {
    $id = mysqli_real_escape_string($connection, $_POST['id']);
    $sql = "DELETE FROM tbl_nozzles WHERE id='$id'";
    if (mysqli_query($connection, $sql)) {
        echo 'Nozzle deleted.';
    } else {
        echo "Error: " . mysqli_error($connection);
    }
}
mysqli_close($connection);
?>
