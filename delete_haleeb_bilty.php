<?php
include 'config/db.php';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if($id > 0){
$stmt = $conn->prepare("DELETE FROM haleeb_bilty WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->close();
}
header("location:haleeb.php");
?>
